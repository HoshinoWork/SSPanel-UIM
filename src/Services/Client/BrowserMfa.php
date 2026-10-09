<?php

declare(strict_types=1);

namespace App\Services\Client;

use App\Models\MFADevice;
use App\Models\User;
use App\Services\DB;
use App\Services\MFA\WebAuthn;
use App\Utils\Tools;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;

final class BrowserMfa
{
    public static function options(string $id, string $ticket): array
    {
        return DB::connection()->transaction(static function () use ($id, $ticket): array {
            $row = self::challenge($id, $ticket);
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            if (isset($payload['options'])) {
                return json_decode($payload['options'], true, 512, JSON_THROW_ON_ERROR);
            }
            $serializer = WebAuthn::getSerializer();
            $credentials = MFADevice::where('userid', $payload['user_id'])->whereIn('type', ['fido', 'passkey'])->get();
            if ($credentials->isEmpty()) {
                throw new ApiException(409, 'use_totp', 'Use the TOTP verification endpoint');
            }
            $allowed = [];
            foreach ($credentials as $credential) {
                $allowed[] = $serializer->deserialize($credential->body, PublicKeyCredentialSource::class, 'json')->getPublicKeyCredentialDescriptor();
            }
            $options = PublicKeyCredentialRequestOptions::create(
                random_bytes(32),
                rpId: Tools::getSiteDomain(),
                allowCredentials: $allowed,
                userVerification: 'preferred',
                timeout: WebAuthn::$timeout
            );
            $payload['options'] = $serializer->serialize($options, 'json');
            DB::table('client_challenges')->where('id', $id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
            return json_decode($payload['options'], true, 512, JSON_THROW_ON_ERROR);
        });
    }

    public static function verify(string $id, string $ticket, array $data): void
    {
        Limits::check('browser_mfa', $id, 5, 300);
        DB::connection()->transaction(static function () use ($id, $ticket, $data): void {
            $row = self::challenge($id, $ticket);
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            if ($payload['approved'] || ! isset($payload['options'])) {
                throw new ApiException(409, 'invalid_challenge', 'Challenge already verified or not initialized');
            }
            $device = MFADevice::where('userid', $payload['user_id'])->whereIn('type', ['fido', 'passkey'])
                ->where('rawid', Input::text($data, 'id', 2048))->lockForUpdate()->first();
            if ($device === null) {
                throw new ApiException(422, 'mfa_failed', 'MFA verification failed');
            }
            try {
                $serializer = WebAuthn::getSerializer();
                $credential = $serializer->deserialize(json_encode($data, JSON_THROW_ON_ERROR), PublicKeyCredential::class, 'json');
                if (! $credential->response instanceof AuthenticatorAssertionResponse) {
                    throw new \RuntimeException('Not an assertion');
                }
                $options = $serializer->deserialize($payload['options'], PublicKeyCredentialRequestOptions::class, 'json');
                $source = $serializer->deserialize($device->body, PublicKeyCredentialSource::class, 'json');
                $result = WebAuthn::getAuthenticatorAssertionResponseValidator()->check(
                    $source,
                    $credential->response,
                    $options,
                    Tools::getSiteDomain(),
                    User::find($payload['user_id'])->uuid
                );
                $device->body = $serializer->serialize($result, 'json');
                $device->used_at = date('Y-m-d H:i:s');
                $device->save();
            } catch (\Throwable) {
                throw new ApiException(422, 'mfa_failed', 'MFA verification failed');
            }
            $payload['approved'] = true;
            unset($payload['options']);
            DB::table('client_challenges')->where('id', $id)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
        });
    }
    private static function challenge(string $id, string $ticket): object
    {
        $row = DB::table('client_challenges')->where('id', $id)->lockForUpdate()->first();
        $payload = $row === null ? [] : json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        if ($row === null || $row->purpose !== 'mfa' || $row->consumed || $row->expires_at <= time() ||
            ! hash_equals($payload['browser_hash'] ?? '', Challenges::digest($ticket))) {
            throw new ApiException(422, 'invalid_challenge', 'Browser verification link invalid');
        }
        if (! Sessions::validUser(User::find($payload['user_id']), $payload['password_hash'])) {
            throw new ApiException(401, 'invalid_credentials', 'Account unavailable');
        }
        return $row;
    }
}

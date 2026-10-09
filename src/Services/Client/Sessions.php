<?php

declare(strict_types=1);

namespace App\Services\Client;

use App\Models\User;
use App\Services\Billing\Transaction;
use App\Services\DB;

final class Sessions
{
    public static function issue(User $user, string $device): array
    {
        return DB::connection()->transaction(static function () use ($user, $device): array {
            $session = Input::token();
            $access = Input::token();
            $refresh = Input::token();
            $accessTtl = max(60, min(3600, (int) ($_ENV['client_api_access_ttl'] ?? 900)));
            $refreshTtl = max(3600, min(7776000, (int) ($_ENV['client_api_refresh_ttl'] ?? 2592000)));
            DB::table('client_sessions')->insert([
                'id' => $session, 'user_id' => $user->id, 'access_hash' => hash('sha256', $access),
                'password_hash' => hash('sha256', $user->pass), 'device_name' => $device,
                'created_at' => time(), 'access_expires' => time() + $accessTtl,
                'expires_at' => time() + $refreshTtl, 'revoked' => false,
            ]);
            DB::table('client_refresh_tokens')->insert([
                'hash' => hash('sha256', $refresh), 'session_id' => $session,
                'used' => false, 'expires_at' => time() + $refreshTtl,
            ]);
            return ['token_type' => 'Bearer', 'access_token' => $access, 'refresh_token' => $refresh,
                'expires_in' => $accessTtl, 'refresh_expires_in' => $refreshTtl, 'session_id' => $session,
            ];
        });
    }

    public static function authenticate(string $token): array
    {
        $session = DB::table('client_sessions')->where('access_hash', hash('sha256', $token))->first();
        $user = $session === null ? null : User::find($session->user_id);
        if ($session === null || $session->revoked || $session->access_expires <= time() || $session->expires_at <= time() || ! self::validUser($user, $session->password_hash)) {
            throw new ApiException(401, 'unauthorized', 'A valid client access token is required');
        }
        return [$user, $session];
    }

    public static function refresh(string $token): array
    {
        $hash = hash('sha256', $token);
        $seed = DB::table('client_refresh_tokens')->where('hash', $hash)->first();
        $family = $seed === null ? null : DB::table('client_sessions')->where('id', $seed->session_id)->first();
        // Commit revocation even when a replay is rejected; throwing inside the
        // transaction would roll it back and leave the stolen family active.
        $result = Transaction::run(static function () use ($hash, $family): ?array {
            if ($family === null) {
                return null;
            }
            // Current reads, in the same user -> session lock order as password
            // changes. A repeatable-read snapshot must not resurrect a used token.
            $user = User::where('id', $family->user_id)->lockForUpdate()->first();
            $session = DB::table('client_sessions')->where('id', $family->id)->lockForUpdate()->first();
            $record = DB::table('client_refresh_tokens')->where('hash', $hash)->lockForUpdate()->first();
            if ($session === null || $session->revoked || $record === null || $record->session_id !== $session->id) {
                return null;
            }
            if ($record->used) {
                self::revoke($session->user_id, $session->id);
                return null;
            }
            if ($session->expires_at <= time() || $record->expires_at <= time() || ! self::validUser($user, $session->password_hash)) {
                self::revoke($session->user_id, $session->id);
                return null;
            }
            $access = Input::token();
            $refresh = Input::token();
            $ttl = max(60, min(3600, (int) ($_ENV['client_api_access_ttl'] ?? 900)));
            DB::table('client_refresh_tokens')->where('hash', $record->hash)->update(['used' => true]);
            DB::table('client_refresh_tokens')->insert(['hash' => hash('sha256', $refresh), 'session_id' => $session->id,
                'used' => false, 'expires_at' => $session->expires_at,
            ]);
            DB::table('client_sessions')->where('id', $session->id)->update([
                'access_hash' => hash('sha256', $access), 'access_expires' => min(time() + $ttl, $session->expires_at),
            ]);
            return ['token_type' => 'Bearer', 'access_token' => $access, 'refresh_token' => $refresh,
                'expires_in' => min($ttl, $session->expires_at - time()),
                'refresh_expires_in' => $session->expires_at - time(), 'session_id' => $session->id,
            ];
        });
        if ($result === null) {
            throw new ApiException(401, 'invalid_refresh_token', 'Refresh token is invalid or has already been used');
        }
        return $result;
    }

    public static function revoke(int $userId, ?string $sessionId = null): void
    {
        $query = DB::table('client_sessions')->where('user_id', $userId);
        if ($sessionId !== null) {
            $query->where('id', $sessionId);
        }
        $query->update(['revoked' => true]);
    }

    public static function validUser(?User $user, string $passwordHash): bool
    {
        return $user !== null && ! $user->is_banned && ! $user->is_shadow_banned && hash_equals($passwordHash, hash('sha256', $user->pass));
    }

    public static function current(User $owner): User
    {
        $user = User::where('id', $owner->id)->lockForUpdate()->first();
        if (! self::validUser($user, hash('sha256', $owner->pass))) {
            throw new ApiException(401, 'unauthorized', 'Account credentials changed or account unavailable');
        }
        return $user;
    }
}

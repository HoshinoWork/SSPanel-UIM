<?php

declare(strict_types=1);

namespace App\Services\Client;

use App\Services\Billing\Transaction;
use App\Services\DB;

final class Challenges
{
    public static function create(string $purpose, array $payload, string $secret, string $email = '', int $ttl = 300): string
    {
        $id = Input::token();
        DB::table('client_challenges')->insert(['id' => $id, 'purpose' => $purpose, 'email' => $email,
            'secret_hash' => self::digest($secret), 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'attempts' => 0, 'consumed' => false, 'expires_at' => time() + $ttl,
        ]);
        return $id;
    }

    public static function digest(string $secret): string
    {
        return hash_hmac('sha256', $secret, (string) $_ENV['key']);
    }

    public static function consume(string $id, string $secret, string $purpose, ?callable $action = null): array
    {
        // A valid proof and its business write commit together. Invalid attempts
        // still commit before the public error is thrown, preventing brute force
        // from resetting the attempt counter through transaction rollback.
        $payload = Transaction::run(static function () use ($id, $secret, $purpose, $action): ?array {
            $row = DB::table('client_challenges')->where('id', $id)->lockForUpdate()->first();
            if ($row === null || $row->purpose !== $purpose || $row->consumed || $row->expires_at <= time() || $row->attempts >= 5) {
                return null;
            }
            DB::table('client_challenges')->where('id', $id)->increment('attempts');
            if (! hash_equals($row->secret_hash, self::digest($secret))) {
                return null;
            }
            DB::table('client_challenges')->where('id', $id)->update(['consumed' => true]);
            $proof = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR) + ['email' => $row->email];
            return $action === null ? $proof : $action($proof);
        });
        if ($payload === null) {
            throw new ApiException(422, 'invalid_challenge', 'Verification is invalid or expired');
        }
        return $payload;
    }
}

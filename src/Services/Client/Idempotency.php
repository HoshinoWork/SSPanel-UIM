<?php

declare(strict_types=1);

namespace App\Services\Client;

use App\Services\Billing\Transaction;
use App\Services\DB;

final class Idempotency
{
    public static function run(int $userId, string $key, string $operation, array $input, callable $action): array
    {
        if (! preg_match('/^[A-Za-z0-9_.:-]{8,128}$/D', $key)) {
            throw new ApiException(422, 'idempotency_key_required', 'An 8 to 128 character Idempotency-Key is required');
        }
        $input = self::canonical($input);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        return Transaction::run(static function () use ($userId, $key, $operation, $hash, $action): array {
            // Serialize writes per wallet owner before checking the unique key.
            DB::table('user')->where('id', $userId)->lockForUpdate()->first();
            $keyHash = hash('sha256', $key);
            $record = DB::table('client_idempotency')->where('user_id', $userId)->where('key_hash', $keyHash)->first();
            if ($record !== null) {
                if ($record->operation !== $operation || ! hash_equals($record->request_hash, $hash)) {
                    throw new ApiException(409, 'idempotency_conflict', 'This key was used for another request');
                }
                return json_decode($record->response, true, 512, JSON_THROW_ON_ERROR);
            }
            $result = $action();
            DB::table('client_idempotency')->insert(['user_id' => $userId, 'key_hash' => $keyHash,
                'request_hash' => $hash, 'operation' => $operation, 'response' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => time(),
            ]);
            return $result;
        });
    }

    private static function canonical(array $input): array
    {
        if (! array_is_list($input)) {
            ksort($input);
        }
        foreach ($input as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            }
        }
        return $input;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Client;

use App\Services\DB;

final class Maintenance
{
    public static function cleanup(): void
    {
        // Retain consumed refresh tokens until family expiry: deleting them
        // early would defeat refresh-token replay detection.
        foreach (['client_rate_limits', 'client_challenges', 'client_refresh_tokens', 'client_sessions'] as $table) {
            $key = $table === 'client_refresh_tokens' ? 'hash' : 'id';
            $ids = DB::table($table)->where('expires_at', '<', time())->limit(500)->pluck($key);
            if ($ids->isNotEmpty()) {
                DB::table($table)->whereIn($key, $ids)->delete();
            }
        }
        // Financial idempotency records and payment evidence are retained.
    }
}

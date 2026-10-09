<?php

declare(strict_types=1);

namespace App\Services\Client;

use App\Services\DB;

final class Limits
{
    public static function check(string $scope, string $identity, int $limit, int $window = 60): void
    {
        $now = time();
        if (random_int(1, 100) === 1) {
            Maintenance::cleanup();
        }
        $id = hash('sha256', $scope . ':' . $identity . ':' . intdiv($now, $window));
        DB::table('client_rate_limits')->insertOrIgnore(['id' => $id, 'attempts' => 0, 'expires_at' => $now + $window]);
        DB::table('client_rate_limits')->where('id', $id)->increment('attempts');
        if (DB::table('client_rate_limits')->where('id', $id)->value('attempts') > $limit) {
            throw new ApiException(429, 'rate_limited', 'Too many requests');
        }
    }
}

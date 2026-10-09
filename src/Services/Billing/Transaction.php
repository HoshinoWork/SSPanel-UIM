<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Services\DB;
use Closure;
use Illuminate\Database\QueryException;

final class Transaction
{
    public static function run(Closure $operation): mixed
    {
        $connection = DB::connection();
        $nested = $connection->transactionLevel() > 0;
        for ($attempt = 1; ; $attempt++) {
            try {
                // Illuminate handles deadlocks / lock timeouts. MariaDB 11.8's
                // snapshot isolation can additionally emit ER_CHECKREAD (1020),
                // which this locked version of Illuminate does not recognize.
                return $connection->transaction($operation, 3);
            } catch (QueryException $error) {
                if ($nested || $attempt >= 3 || (int) ($error->errorInfo[1] ?? 0) !== 1020) {
                    throw $error;
                }
                // The failed transaction has already rolled back in full.
                // Never retry arbitrary errors or ambiguous commit failures.
            }
        }
    }
}

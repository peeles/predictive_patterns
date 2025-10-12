<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

class DatabaseTransactionHelper
{
    /**
     * Execute the callback within a database transaction when the current connection
     * is not already inside an open transaction. If a transaction is already active,
     * the callback will be executed immediately to avoid nested transactions on
     * databases such as SQLite that do not support them.
     *
     * @template TReturn
     *
     * @param  Closure():TReturn  $callback
     * @param  string|null  $connection
     * @return TReturn
     */
    public static function runWithoutNestedTransaction(Closure $callback, ?string $connection = null)
    {
        $connectionInstance = $connection !== null
            ? DB::connection($connection)
            : DB::connection();

        if ($connectionInstance->transactionLevel() > 0) {
            return $callback();
        }

        return $connectionInstance->transaction(static function () use ($callback) {
            return $callback();
        });
    }
}

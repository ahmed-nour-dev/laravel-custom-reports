<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Builder\Compiler;

use AhmedNour\CustomReports\Exceptions\ReportTimedOut;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;

/**
 * SPEC §6.4: report queries run under a statement time limit (config
 * `custom-reports.max_execution_ms`), set for the session around the run
 * and restored after; hitting it becomes ReportTimedOut instead of a 500.
 *
 * MySQL calls it `max_execution_time` (ms, error 3024), MariaDB
 * `max_statement_time` (seconds, error 1969) — told apart by the server,
 * not the driver name, since the `mysql` driver also talks to MariaDB.
 * Other databases run unguarded.
 */
final class Guardrail
{
    /** server => [session variable, unit divisor from ms, timeout error code] */
    private const array SERVERS = [
        'mysql' => ['max_execution_time', 1, 3024],
        'mariadb' => ['max_statement_time', 1000, 1969],
    ];

    public function milliseconds(): int
    {
        return max(1, (int) config('custom-reports.max_execution_ms', 30000));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    public function run(ConnectionInterface $connection, Closure $run): mixed
    {
        $driver = $connection instanceof MySqlConnection ? self::SERVERS[$connection->isMaria() ? 'mariadb' : 'mysql'] : null;

        if ($driver === null) {
            return $run();
        }

        [$variable, $divisor, $timeoutCode] = $driver;
        $set = $variable === 'max_statement_time'
            ? 'set session max_statement_time = ?'
            : 'set session max_execution_time = ?';
        $read = $variable === 'max_statement_time'
            ? 'select @@session.max_statement_time as v'
            : 'select @@session.max_execution_time as v';

        // Bound as integers: MariaDB refuses a string for this variable.
        $previous = (int) ceil((float) ($connection->selectOne($read)->v ?? 0));
        $connection->statement($set, [(int) ceil($this->milliseconds() / $divisor)]);

        try {
            return $run();
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === $timeoutCode) {
                throw ReportTimedOut::after($this->milliseconds());
            }

            throw $e;
        } finally {
            $connection->statement($set, [$previous]);
        }
    }
}

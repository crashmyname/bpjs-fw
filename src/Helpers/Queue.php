<?php

namespace Bpjs\Framework\Helpers;

use PDO;
use Throwable;
use Exception;

class Queue
{
    protected static array $connections = [];
    protected static array $lastActivity = [];
    protected static string $connection = 'default';
    protected static int $maxIdleTime = 60;
    protected static int $maxAttempts = 3;

    /* =========================
     * DB CONNECTION
     * ========================= */
    protected static function db(?string $connection = null): PDO
    {
        $connection ??= self::$connection;

        if (!isset(self::$connections[$connection])) {
            self::connect($connection);
        }

        if (
            isset(self::$lastActivity[$connection]) &&
            (time() - self::$lastActivity[$connection]) > self::$maxIdleTime
        ) {
            self::reconnect($connection);
        }

        try {
            self::$connections[$connection]->query('SELECT 1');
        } catch (Throwable) {
            self::reconnect($connection);
        }

        self::$lastActivity[$connection] = time();

        return self::$connections[$connection];
    }

    /* =========================
     * CONNECT (ENV VERSION)
     * ========================= */
    protected static function connect(string $connection = 'default'): void
    {
        self::$connection = $connection;

        $driver   = env('DB_CONNECTION', 'mysql');
        $host     = env('DB_HOST', '127.0.0.1');
        $port     = env('DB_PORT', '3306');
        $dbname   = env('DB_DATABASE', '');
        $charset  = env('DB_CHARSET', 'utf8mb4');
        $username = env('DB_USERNAME', 'root');
        $password = env('DB_PASSWORD', '');

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        $dsn = match ($driver) {
            'mysql'  => "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}",
            'pgsql'  => "pgsql:host={$host};port={$port};dbname={$dbname}",
            'sqlite' => "sqlite:{$dbname}",
            'sqlsrv' => "sqlsrv:Server={$host},{$port};Database={$dbname}",
            default  => throw new Exception("Driver [$driver] not supported.")
        };

        self::$connections[$connection] = new PDO(
            $dsn,
            $username,
            $password,
            $options
        );

        self::$lastActivity[$connection] = time();
    }

    public static function reconnect(?string $connection = null): void
    {
        $connection ??= self::$connection;

        unset(self::$connections[$connection]);
        self::$lastActivity[$connection] = 0;

        self::connect($connection);
    }

    /* =========================
     * CORE HELPERS
     * ========================= */
    protected static function isLostConnection(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'server has gone away')
            || str_contains($msg, 'lost connection')
            || str_contains($msg, 'broken pipe')
            || str_contains($msg, 'connection was killed');
    }

    protected static function run(callable $cb, ?string $connection = null)
    {
        try {
            return $cb(self::db($connection));
        } catch (Throwable $e) {

            if (self::isLostConnection($e)) {
                self::reconnect($connection);
                return $cb(self::db($connection));
            }

            throw $e;
        }
    }

    protected static function transaction(callable $cb, ?string $connection = null)
    {
        $db = self::db($connection);

        try {
            $db->beginTransaction();

            $result = $cb($db);

            $db->commit();

            return $result;

        } catch (Throwable $e) {

            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }

    /* =========================
     * TIME
     * ========================= */
    protected static function now(): string
    {
        $driver = env('DB_CONNECTION', 'mysql');

        return match ($driver) {
            'sqlite' => "datetime('now')",
            default  => "NOW()"
        };
    }

    /* =========================
     * PUSH JOB
     * ========================= */
    public static function push(string $jobClass, array $data = [], string $queue = 'default')
    {
        return self::run(function ($db) use ($jobClass, $data, $queue) {

            $now = self::now();

            return $db->prepare("
                INSERT INTO jobs (
                    queue,
                    payload,
                    status,
                    attempts,
                    created_at,
                    updated_at
                )
                VALUES (
                    :queue,
                    :payload,
                    'pending',
                    0,
                    {$now},
                    {$now}
                )
            ")->execute([
                'queue' => $queue,
                'payload' => json_encode([
                    'job' => $jobClass,
                    'data' => $data
                ], JSON_THROW_ON_ERROR)
            ]);
        });
    }

    /* =========================
     * POP JOB
     * ========================= */
    public static function pop(string $queue = 'default')
    {
        return self::transaction(function ($db) use ($queue) {

            $now = self::now();

            $stmt = $db->prepare("
                SELECT *
                FROM jobs
                WHERE queue = :queue
                AND status = 'pending'
                AND attempts < :max
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            ");

            $stmt->execute([
                'queue' => $queue,
                'max'   => self::$maxAttempts
            ]);

            $job = $stmt->fetch();

            if (!$job) return null;

            $db->prepare("
                UPDATE jobs
                SET status = 'processing',
                    attempts = attempts + 1,
                    updated_at = {$now}
                WHERE id = :id
            ")->execute(['id' => $job->id]);

            $job->attempts++;

            return $job;
        });
    }

    /* =========================
     * DONE / FAIL
     * ========================= */
    public static function done(int $id): bool
    {
        return self::run(function ($db) use ($id) {
            return $db->prepare("
                UPDATE jobs
                SET status = 'done',
                    updated_at = NOW()
                WHERE id = :id
            ")->execute(['id' => $id]);
        });
    }

    public static function fail(int $id, ?string $msg = null): bool
    {
        return self::run(function ($db) use ($msg,$id) {
            return $db->prepare("
                UPDATE jobs
                SET status = 'failed',
                    error_message = :msg,
                    updated_at = NOW()
                WHERE id = :id
            ")->execute([
                'id' => $id,
                'msg' => $msg
            ]);
        });
    }

    /* =========================
     * 🔥 RESTORED: RELEASE JOB
     * ========================= */
    public static function release(int $id, int $delay = 10): bool
    {
        return self::run(function ($db) use ($id, $delay) {

            $availableAt = date('Y-m-d H:i:s', time() + $delay);

            return $db->prepare("
                UPDATE jobs
                SET status = 'pending',
                    reserved_at = NULL,
                    available_at = :available_at,
                    updated_at = NOW()
                WHERE id = :id
            ")->execute([
                'id' => $id,
                'available_at' => $availableAt
            ]);
        });
    }
}
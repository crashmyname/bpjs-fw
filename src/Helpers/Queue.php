<?php

namespace Bpjs\Framework\Helpers;

use PDO;
use PDOException;
use Throwable;

class Queue
{
    protected static ?PDO $pdo = null;
    protected static int $lastActivity = 0;
    protected static int $maxIdleTime = 60; // detik

    /**
     * Ambil koneksi database
     */
    protected static function db(): PDO
    {
        if (self::$pdo === null) {
            self::connect();
        }

        // reconnect jika idle terlalu lama
        if ((time() - self::$lastActivity) > self::$maxIdleTime) {
            self::reconnect();
        }

        // ping koneksi
        try {
            self::$pdo->query("SELECT 1");
        } catch (Throwable $e) {
            self::reconnect();
        }

        self::$lastActivity = time();

        return self::$pdo;
    }

    /**
     * Connect database
     */
    protected static function connect(): void
    {
        $host     = env('DB_HOST', '127.0.0.1');
        $port     = env('DB_PORT', '3306');
        $database = env('DB_DATABASE', '');
        $username = env('DB_USERNAME', 'root');
        $password = env('DB_PASSWORD', '');
        $charset  = env('DB_CHARSET', 'utf8mb4');
        $socket   = env('DB_SOCKET', null);

        if ($socket) {
            $dsn = "mysql:unix_socket={$socket};dbname={$database};charset={$charset}";
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
        }

        self::$pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);

        self::$lastActivity = time();
    }

    /**
     * Reconnect database
     */
    public static function reconnect(): void
    {
        self::$pdo = null;
        self::connect();
    }

    /**
     * Jalankan query biasa dengan auto retry
     */
    protected static function run(callable $callback)
    {
        try {
            return $callback(self::db());
        } catch (Throwable $e) {
            if (self::isLostConnection($e)) {
                self::reconnect();
                return $callback(self::db());
            }

            throw $e;
        }
    }

    /**
     * Jalankan transaction tanpa auto retry
     */
    protected static function transaction(callable $callback)
    {
        $db = self::db();

        try {
            $db->beginTransaction();

            $result = $callback($db);

            $db->commit();

            return $result;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Cek lost connection
     */
    protected static function isLostConnection(Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'server has gone away') ||
               str_contains($message, 'lost connection') ||
               str_contains($message, 'error while sending') ||
               str_contains($message, 'broken pipe') ||
               str_contains($message, 'connection was killed');
    }

    /**
     * Push job
     */
    public static function push(string $jobClass, array $data = [], string $queue = 'default')
    {
        return self::run(function ($db) use ($jobClass, $data, $queue) {
            $stmt = $db->prepare("
                INSERT INTO jobs (queue, payload, status, attempts, created_at, updated_at)
                VALUES (:queue, :payload, 'pending', 0, NOW(), NOW())
            ");

            return $stmt->execute([
                'queue'   => $queue,
                'payload' => json_encode([
                    'job'  => $jobClass,
                    'data' => $data
                ])
            ]);
        });
    }

    /**
     * Pop job
     */
    public static function pop(string $queue = 'default')
    {
        return self::transaction(function ($db) use ($queue) {

            $stmt = $db->prepare("
                SELECT *
                FROM jobs
                WHERE queue = :queue
                  AND status = 'pending'
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            ");

            $stmt->execute(['queue' => $queue]);

            $job = $stmt->fetch();

            if ($job) {
                $update = $db->prepare("
                    UPDATE jobs
                    SET status = 'processing',
                        attempts = attempts + 1,
                        updated_at = NOW()
                    WHERE id = :id
                ");

                $update->execute([
                    'id' => $job->id
                ]);

                $job->attempts++;
            }

            return $job;
        });
    }

    /**
     * Done
     */
    public static function done(int $id)
    {
        return self::run(function ($db) use ($id) {
            $stmt = $db->prepare("
                UPDATE jobs
                SET status = 'done',
                    updated_at = NOW()
                WHERE id = :id
            ");

            return $stmt->execute(['id' => $id]);
        });
    }

    /**
     * Fail
     */
    public static function fail(int $id)
    {
        return self::run(function ($db) use ($id) {
            $stmt = $db->prepare("
                UPDATE jobs
                SET status = 'failed',
                    updated_at = NOW()
                WHERE id = :id
            ");

            return $stmt->execute(['id' => $id]);
        });
    }

    /**
     * Release
     */
    public static function release(int $id)
    {
        return self::run(function ($db) use ($id) {
            $stmt = $db->prepare("
                UPDATE jobs
                SET status = 'pending',
                    updated_at = NOW()
                WHERE id = :id
            ");

            return $stmt->execute(['id' => $id]);
        });
    }
}
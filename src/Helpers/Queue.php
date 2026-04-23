<?php

namespace Bpjs\Framework\Helpers;

use PDO;
use PDOException;

class Queue
{
    protected static function db(): PDO
    {
        static $pdo = null;

        if ($pdo === null) {
            $host     = env('DB_HOST', '127.0.0.1');
            $port     = env('DB_PORT', '3306');
            $database = env('DB_DATABASE', '');
            $username = env('DB_USERNAME', 'root');
            $password = env('DB_PASSWORD', '');
            $charset  = env('DB_CHARSET', 'utf8mb4');
            $socket   = env('DB_SOCKET', null);

            try {
                if ($socket) {
                    $dsn = "mysql:unix_socket={$socket};dbname={$database};charset={$charset}";
                } else {
                    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
                }

                $pdo = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE=> PDO::FETCH_OBJ,
                    PDO::ATTR_EMULATE_PREPARES  => false,
                    PDO::ATTR_PERSISTENT        => env('DB_PERSISTENT', false),
                ]);

            } catch (PDOException $e) {
                die("Queue DB Connection failed: " . $e->getMessage());
            }
        }

        return $pdo;
    }

    public static function push(string $jobClass, array $data = [], string $queue = 'default')
    {
        $sql = "INSERT INTO jobs (queue, payload, status, created_at, updated_at)
                VALUES (:queue, :payload, 'pending', NOW(), NOW())";

        $stmt = self::db()->prepare($sql);

        return $stmt->execute([
            'queue'   => $queue,
            'payload' => json_encode([
                'job'  => $jobClass,
                'data' => $data
            ])
        ]);
    }

    public static function pop(string $queue = 'default')
    {
        try {
            self::db()->beginTransaction();

            $sql = "SELECT * FROM jobs
                    WHERE queue = :queue
                    AND status = 'pending'
                    ORDER BY id ASC
                    LIMIT 1
                    FOR UPDATE";

            $stmt = self::db()->prepare($sql);
            $stmt->execute(['queue' => $queue]);

            $job = $stmt->fetch();

            if ($job) {
                $update = self::db()->prepare("
                    UPDATE jobs
                    SET status = 'processing',
                        attempts = attempts + 1,
                        updated_at = NOW()
                    WHERE id = :id
                ");

                $update->execute(['id' => $job->id]);
            }

            self::db()->commit();

            return $job;
        } catch (PDOException $e) {
            self::db()->rollBack();
            throw $e;
        }
    }

    public static function done(int $id)
    {
        $stmt = self::db()->prepare("
            UPDATE jobs
            SET status = 'done',
                updated_at = NOW()
            WHERE id = :id
        ");

        return $stmt->execute(['id' => $id]);
    }

    public static function fail(int $id)
    {
        $stmt = self::db()->prepare("
            UPDATE jobs
            SET status = 'failed',
                updated_at = NOW()
            WHERE id = :id
        ");

        return $stmt->execute(['id' => $id]);
    }

    public static function release(int $id)
    {
        $stmt = self::db()->prepare("
            UPDATE jobs
            SET status = 'pending',
                updated_at = NOW()
            WHERE id = :id
        ");

        return $stmt->execute(['id' => $id]);
    }
}
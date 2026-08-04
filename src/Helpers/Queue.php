<?php

namespace Bpjs\Framework\Helpers;

use PDO;
use Redis;
use Throwable;
use Exception;

/**
 * Queue Helper
 *
 * Engine queue dapat dikonfigurasi melalui .env:
 *   QUEUE_ENGINE=database  → menggunakan tabel `jobs` di database (default)
 *   QUEUE_ENGINE=redis     → menggunakan Redis
 *
 * Konfigurasi Database (berlaku jika QUEUE_ENGINE=database):
 *   DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
 *
 * Konfigurasi Redis (berlaku jika QUEUE_ENGINE=redis):
 *   REDIS_HOST, REDIS_PORT, REDIS_PASSWORD, REDIS_DATABASE
 *   REDIS_PREFIX  → prefix key (default: "queue:")
 *   REDIS_TIMEOUT → connection timeout detik (default: 2)
 *
 * Konfigurasi Umum:
 *   QUEUE_MAX_ATTEMPTS → maksimal percobaan job (default: 3)
 *   QUEUE_RETRY_AFTER  → detik sebelum job yang stuck bisa diambil ulang (default: 90)
 *
 * @package Bpjs\Framework\Helpers
 */
class Queue
{
    /* =========================================================
     * CONSTANTS
     * ========================================================= */
    public const ENGINE_DATABASE = 'database';
    public const ENGINE_REDIS    = 'redis';

    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_FAILED     = 'failed';

    /* =========================================================
     * REDIS STATE
     * ========================================================= */
    protected static ?Redis $redis = null;
    protected static int $redisLastPing = 0;
    protected static int $redisPingInterval = 30;

    /* =========================================================
     * SHARED CONFIG
     * ========================================================= */
    protected static int $maxAttempts = 0;
    protected static int $retryAfter = 0;
    protected static bool $migrationChecked = false;

    /* =========================================================
     * ENGINE RESOLVER
     * ========================================================= */

    public static function engine(): string
    {
        $engine = strtolower(env('QUEUE_ENGINE', self::ENGINE_DATABASE));

        if (!in_array($engine, [self::ENGINE_DATABASE, self::ENGINE_REDIS], true)) {
            throw new Exception(
                "QUEUE_ENGINE [{$engine}] tidak valid. Gunakan 'database' atau 'redis'."
            );
        }

        return $engine;
    }

    protected static function getMaxAttempts(): int
    {
        if (self::$maxAttempts === 0) {
            self::$maxAttempts = (int) env('QUEUE_MAX_ATTEMPTS', 3);
        }

        return self::$maxAttempts;
    }

    protected static function getRetryAfter(): int
    {
        if (self::$retryAfter === 0) {
            self::$retryAfter = (int) env('QUEUE_RETRY_AFTER', 90);
        }

        return self::$retryAfter;
    }

    /* =========================================================
     * DATABASE CONNECTION - MENGGUNAKAN DATABASE HELPER
     * ========================================================= */

    protected static function db(): PDO
    {
        return Database::connection();
    }

    protected static function run(callable $cb): mixed
    {
        try {
            return $cb(self::db());
        } catch (Throwable $e) {
            if (self::isLostConnection($e)) {
                Database::disconnect();
                return $cb(self::db());
            }
            throw $e;
        }
    }

    protected static function transaction(callable $cb): mixed
    {
        $db = self::db();
        
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

    protected static function isLostConnection(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        $codes = [2002, 2006, 2013];

        return in_array($e->getCode(), $codes, true) ||
               str_contains($msg, 'server has gone away') ||
               str_contains($msg, 'lost connection') ||
               str_contains($msg, 'broken pipe') ||
               str_contains($msg, 'connection was killed') ||
               str_contains($msg, 'no connection to the server') ||
               str_contains($msg, 'could not find driver');
    }

    protected static function dbNow(): string
    {
        $driver = env('DB_CONNECTION', 'mysql');
        return match ($driver) {
            'sqlite' => "datetime('now')",
            'pgsql'  => "NOW()",
            default  => "NOW()"
        };
    }

    /* =========================================================
     * INSTALLATION / MIGRATION
     * ========================================================= */

    /**
     * Buat tabel jobs jika belum ada (auto-run saat pertama kali push)
     */
    protected static function ensureTableExists(): void
    {
        if (self::$migrationChecked) {
            return;
        }

        try {
            $db = self::db();
            $driver = env('DB_CONNECTION', 'mysql');
            
            $tableExists = match ($driver) {
                'mysql' => $db->query("SHOW TABLES LIKE 'jobs'")->rowCount() > 0,
                'pgsql' => $db->query("SELECT 1 FROM information_schema.tables WHERE table_name = 'jobs'")->rowCount() > 0,
                'sqlite' => $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='jobs'")->rowCount() > 0,
                default => false,
            };

            if (!$tableExists) {
                self::installMigration();
            }

            self::$migrationChecked = true;
        } catch (Throwable $e) {
            // Jika error, biarkan saja dan coba lagi nanti
            error_log("Queue: Gagal cek tabel jobs: " . $e->getMessage());
        }
    }

    public static function installMigration(): void
    {
        $db = self::db();
        $driver = env('DB_CONNECTION', 'mysql');
        
        $schema = match ($driver) {
            'mysql' => "
                CREATE TABLE IF NOT EXISTS jobs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    queue VARCHAR(255) NOT NULL DEFAULT 'default',
                    payload TEXT NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    attempts INT NOT NULL DEFAULT 0,
                    error_message TEXT,
                    available_at TIMESTAMP NULL,
                    reserved_at TIMESTAMP NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_queue_status (queue, status),
                    INDEX idx_available (available_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",
            'pgsql' => "
                CREATE TABLE IF NOT EXISTS jobs (
                    id SERIAL PRIMARY KEY,
                    queue VARCHAR(255) NOT NULL DEFAULT 'default',
                    payload TEXT NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    attempts INT NOT NULL DEFAULT 0,
                    error_message TEXT,
                    available_at TIMESTAMP NULL,
                    reserved_at TIMESTAMP NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_queue_status ON jobs(queue, status);
                CREATE INDEX IF NOT EXISTS idx_available ON jobs(available_at);
            ",
            'sqlite' => "
                CREATE TABLE IF NOT EXISTS jobs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    queue VARCHAR(255) NOT NULL DEFAULT 'default',
                    payload TEXT NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    attempts INT NOT NULL DEFAULT 0,
                    error_message TEXT,
                    available_at TIMESTAMP NULL,
                    reserved_at TIMESTAMP NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_queue_status ON jobs(queue, status);
                CREATE INDEX IF NOT EXISTS idx_available ON jobs(available_at);
            ",
            default => throw new Exception("Unsupported database driver: $driver")
        };

        $db->exec($schema);
        echo "Table 'jobs' created successfully.\n";
    }

    /* =========================================================
     * REDIS CONNECTION
     * ========================================================= */

    protected static function redis(): Redis
    {
        if (self::$redis === null) {
            self::connectRedis();
        }

        if ((time() - self::$redisLastPing) > self::$redisPingInterval) {
            try {
                self::$redis->ping();
                self::$redisLastPing = time();
            } catch (Throwable) {
                self::reconnectRedis();
            }
        }

        return self::$redis;
    }

    protected static function connectRedis(): void
    {
        $host     = env('REDIS_HOST', '127.0.0.1');
        $port     = (int) env('REDIS_PORT', 6379);
        $password = env('REDIS_PASSWORD', '');
        $database = (int) env('REDIS_DATABASE', 0);
        $timeout  = (float) env('REDIS_TIMEOUT', 2);

        $redis = new Redis();

        if (!$redis->connect($host, $port, $timeout)) {
            throw new Exception(
                "Gagal terhubung ke Redis di {$host}:{$port}"
            );
        }

        if (!empty($password)) {
            $redis->auth($password);
        }

        if ($database > 0) {
            $redis->select($database);
        }

        self::$redis = $redis;
        self::$redisLastPing = time();
    }

    public static function reconnectRedis(): void
    {
        self::$redis = null;
        self::$redisLastPing = 0;
        self::connectRedis();
    }

    protected static function redisPrefix(): string
    {
        return rtrim(env('REDIS_PREFIX', 'queue'), ':') . ':';
    }

    protected static function redisPendingKey(string $queue): string
    {
        return self::redisPrefix() . $queue . ':pending';
    }

    protected static function redisDelayedKey(string $queue): string
    {
        return self::redisPrefix() . $queue . ':delayed';
    }

    protected static function redisProcessingKey(string $queue): string
    {
        return self::redisPrefix() . $queue . ':processing';
    }

    protected static function redisJobKey(string $jobId): string
    {
        return self::redisPrefix() . 'job:' . $jobId;
    }

    protected static function redisCounterKey(): string
    {
        return self::redisPrefix() . 'counter';
    }

    /* =========================================================
     * PUBLIC API — ENGINE-AGNOSTIC
     * ========================================================= */

    public static function push(
        string $jobClass,
        array  $data = [],
        string $queue = 'default',
        int    $delay = 0
    ): int|bool {
        // Auto-create table jika pakai database
        if (self::engine() === self::ENGINE_DATABASE) {
            self::ensureTableExists();
        }

        return self::engine() === self::ENGINE_REDIS
            ? self::redisPush($jobClass, $data, $queue, $delay)
            : self::dbPush($jobClass, $data, $queue, $delay);
    }

    public static function later(
        int    $delay,
        string $jobClass,
        array  $data = [],
        string $queue = 'default'
    ): int|bool {
        return self::push($jobClass, $data, $queue, $delay);
    }

    public static function pop(string $queue = 'default'): ?object
    {
        return self::engine() === self::ENGINE_REDIS
            ? self::redisPop($queue)
            : self::dbPop($queue);
    }

    public static function done(int|string $id): bool
    {
        return self::engine() === self::ENGINE_REDIS
            ? self::redisDone($id)
            : self::dbDone((int) $id);
    }

    public static function fail(int|string $id, ?string $message = null): bool
    {
        return self::engine() === self::ENGINE_REDIS
            ? self::redisFail($id, $message)
            : self::dbFail((int) $id, $message);
    }

    public static function release(int|string $id, int $delay = 10): bool
    {
        return self::engine() === self::ENGINE_REDIS
            ? self::redisRelease($id, $delay)
            : self::dbRelease((int) $id, $delay);
    }

    public static function delete(int|string $id): bool
    {
        return self::engine() === self::ENGINE_REDIS
            ? self::redisDelete($id)
            : self::dbDelete((int) $id);
    }

    public static function process(object $job, callable $handler): bool
    {
        $payload = json_decode($job->payload, true);

        try {
            $handler($payload);
            self::done($job->id);
            return true;
        } catch (Throwable $e) {
            $attempts = (int) ($job->attempts ?? 1);
            $maxAttempts = self::getMaxAttempts();

            if ($attempts < $maxAttempts) {
                $delay = 10 * (2 ** ($attempts - 1));
                self::release($job->id, $delay);
            } else {
                self::fail($job->id, $e->getMessage());
            }

            return false;
        }
    }

    public static function size(string $queue = 'default'): array
    {
        return self::engine() === self::ENGINE_REDIS
            ? self::redisSize($queue)
            : self::dbSize($queue);
    }

    public static function purge(string $queue = 'default', string $status = self::STATUS_FAILED): bool
    {
        return self::engine() === self::ENGINE_REDIS
            ? self::redisPurge($queue, $status)
            : self::dbPurge($queue, $status);
    }

    public static function retryStuck(string $queue = 'default'): int
    {
        return self::engine() === self::ENGINE_REDIS
            ? self::redisRetryStuck($queue)
            : self::dbRetryStuck($queue);
    }

    public static function listJobs(
        string $queue = 'default',
        string $status = self::STATUS_PENDING,
        int    $limit = 20
    ): array {
        return self::engine() === self::ENGINE_REDIS
            ? self::redisListJobs($queue, $status, $limit)
            : self::dbListJobs($queue, $status, $limit);
    }

    /* =========================================================
     * DATABASE IMPLEMENTATION
     * ========================================================= */

    protected static function dbPush(
        string $jobClass,
        array  $data,
        string $queue,
        int    $delay
    ): int|false {
        return self::run(function ($db) use ($jobClass, $data, $queue, $delay) {
            $now = self::dbNow();
            $availableAt = $delay > 0
                ? date('Y-m-d H:i:s', time() + $delay)
                : null;

            $stmt = $db->prepare("
                INSERT INTO jobs (
                    queue, payload, status, attempts,
                    available_at, created_at, updated_at
                ) VALUES (
                    :queue, :payload, 'pending', 0,
                    :available_at, {$now}, {$now}
                )
            ");

            $stmt->execute([
                'queue' => $queue,
                'payload' => json_encode(
                    ['job' => $jobClass, 'data' => $data],
                    JSON_THROW_ON_ERROR
                ),
                'available_at' => $availableAt,
            ]);

            return (int) $db->lastInsertId();
        });
    }

    protected static function dbPop(string $queue): ?object
    {
        return self::transaction(function ($db) use ($queue) {
            $now = self::dbNow();
            $maxAttempts = self::getMaxAttempts();
            $nowTs = date('Y-m-d H:i:s');

            $stmt = $db->prepare("
                SELECT * FROM jobs
                WHERE queue = :queue
                  AND status = 'pending'
                  AND attempts < :max
                  AND (available_at IS NULL OR available_at <= :now)
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            ");

            $stmt->execute([
                'queue' => $queue,
                'max' => $maxAttempts,
                'now' => $nowTs,
            ]);

            $job = $stmt->fetch();

            if (!$job) {
                return null;
            }

            $stmt = $db->prepare("
                UPDATE jobs
                SET status = 'processing',
                    attempts = attempts + 1,
                    reserved_at = {$now},
                    updated_at = {$now}
                WHERE id = :id
            ");
            $stmt->execute(['id' => $job->id]);

            $job->attempts++;

            return $job;
        });
    }

    protected static function dbDone(int $id): bool
    {
        return self::run(function ($db) use ($id) {
            $now = self::dbNow();
            $stmt = $db->prepare("
                UPDATE jobs
                SET status = 'done', updated_at = {$now}
                WHERE id = :id
            ");
            return $stmt->execute(['id' => $id]);
        });
    }

    protected static function dbFail(int $id, ?string $message): bool
    {
        return self::run(function ($db) use ($id, $message) {
            $now = self::dbNow();
            $stmt = $db->prepare("
                UPDATE jobs
                SET status = 'failed',
                    error_message = :msg,
                    updated_at = {$now}
                WHERE id = :id
            ");
            return $stmt->execute(['id' => $id, 'msg' => $message]);
        });
    }

    protected static function dbRelease(int $id, int $delay): bool
    {
        return self::run(function ($db) use ($id, $delay) {
            $now = self::dbNow();
            $availableAt = date('Y-m-d H:i:s', time() + $delay);

            $stmt = $db->prepare("
                UPDATE jobs
                SET status = 'pending',
                    reserved_at = NULL,
                    available_at = :available_at,
                    updated_at = {$now}
                WHERE id = :id
            ");
            return $stmt->execute(['id' => $id, 'available_at' => $availableAt]);
        });
    }

    protected static function dbDelete(int $id): bool
    {
        return self::run(function ($db) use ($id) {
            $stmt = $db->prepare("DELETE FROM jobs WHERE id = :id");
            return $stmt->execute(['id' => $id]);
        });
    }

    protected static function dbSize(string $queue): array
    {
        return self::run(function ($db) use ($queue) {
            $stmt = $db->prepare("
                SELECT status, COUNT(*) AS total
                FROM jobs
                WHERE queue = :queue
                GROUP BY status
            ");
            $stmt->execute(['queue' => $queue]);

            $result = [
                self::STATUS_PENDING => 0,
                self::STATUS_PROCESSING => 0,
                self::STATUS_DONE => 0,
                self::STATUS_FAILED => 0,
            ];

            foreach ($stmt->fetchAll() as $row) {
                if (isset($result[$row->status])) {
                    $result[$row->status] = (int) $row->total;
                }
            }

            return $result;
        });
    }

    protected static function dbPurge(string $queue, string $status): bool
    {
        return self::run(function ($db) use ($queue, $status) {
            $stmt = $db->prepare("
                DELETE FROM jobs
                WHERE queue = :queue AND status = :status
            ");
            return $stmt->execute(['queue' => $queue, 'status' => $status]);
        });
    }

    protected static function dbRetryStuck(string $queue): int
    {
        return self::run(function ($db) use ($queue) {
            $now = self::dbNow();
            $cutoff = date('Y-m-d H:i:s', time() - self::getRetryAfter());

            $stmt = $db->prepare("
                UPDATE jobs
                SET status = 'pending',
                    reserved_at = NULL,
                    updated_at = {$now}
                WHERE queue = :queue
                  AND status = 'processing'
                  AND reserved_at < :cutoff
            ");
            $stmt->execute(['queue' => $queue, 'cutoff' => $cutoff]);

            return $stmt->rowCount();
        });
    }

    protected static function dbListJobs(string $queue, string $status, int $limit): array
    {
        return self::run(function ($db) use ($queue, $status, $limit) {
            $stmt = $db->prepare("
                SELECT * FROM jobs
                WHERE queue = :queue
                  AND status = :status
                ORDER BY id DESC
                LIMIT :limit
            ");

            $stmt->bindValue('queue', $queue);
            $stmt->bindValue('status', $status);
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        });
    }

    /* =========================================================
     * REDIS IMPLEMENTATION
     * ========================================================= */

    protected static function redisPush(
        string $jobClass,
        array  $data,
        string $queue,
        int    $delay
    ): bool {
        $redis = self::redis();
        $jobId = (string) $redis->incr(self::redisCounterKey());
        $now = time();

        $job = [
            'id' => $jobId,
            'queue' => $queue,
            'payload' => json_encode(
                ['job' => $jobClass, 'data' => $data],
                JSON_THROW_ON_ERROR
            ),
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'error_message' => null,
            'available_at' => $delay > 0 ? $now + $delay : $now,
            'reserved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $redis->hMSet(self::redisJobKey($jobId), self::flattenForRedis($job));

        if ($delay > 0) {
            $redis->zAdd(self::redisDelayedKey($queue), $now + $delay, $jobId);
        } else {
            $redis->rPush(self::redisPendingKey($queue), $jobId);
        }

        return true;
    }

    protected static function redisPop(string $queue): ?object
    {
        $redis = self::redis();

        self::redisMigrateDelayed($queue);
        self::redisRetryStuck($queue);

        $jobId = $redis->lPop(self::redisPendingKey($queue));

        if (!$jobId) {
            return null;
        }

        $jobData = $redis->hGetAll(self::redisJobKey($jobId));

        if (empty($jobData)) {
            return null;
        }

        $now = time();
        $attempts = (int) $jobData['attempts'] + 1;

        $redis->hMSet(self::redisJobKey($jobId), [
            'status' => self::STATUS_PROCESSING,
            'attempts' => $attempts,
            'reserved_at' => $now,
            'updated_at' => $now,
        ]);

        $redis->zAdd(self::redisProcessingKey($queue), $now, $jobId);

        $jobData['status'] = self::STATUS_PROCESSING;
        $jobData['attempts'] = $attempts;
        $jobData['reserved_at'] = $now;
        $jobData['updated_at'] = $now;

        return self::arrayToObject($jobData);
    }

    protected static function redisDone(string|int $id): bool
    {
        $redis = self::redis();
        $key = self::redisJobKey((string) $id);

        $queue = $redis->hGet($key, 'queue') ?: 'default';
        $redis->zRem(self::redisProcessingKey($queue), (string) $id);

        return (bool) $redis->hMSet($key, [
            'status' => self::STATUS_DONE,
            'updated_at' => time(),
        ]);
    }

    protected static function redisFail(string|int $id, ?string $message): bool
    {
        $redis = self::redis();
        $key = self::redisJobKey((string) $id);

        $queue = $redis->hGet($key, 'queue') ?: 'default';
        $redis->zRem(self::redisProcessingKey($queue), (string) $id);

        return (bool) $redis->hMSet($key, [
            'status' => self::STATUS_FAILED,
            'error_message' => $message ?? '',
            'updated_at' => time(),
        ]);
    }

    protected static function redisRelease(string|int $id, int $delay): bool
    {
        $redis = self::redis();
        $key = self::redisJobKey((string) $id);
        $queue = $redis->hGet($key, 'queue') ?: 'default';
        $availableAt = time() + $delay;

        $redis->zRem(self::redisProcessingKey($queue), (string) $id);

        $redis->hMSet($key, [
            'status' => self::STATUS_PENDING,
            'reserved_at' => '',
            'available_at' => $availableAt,
            'updated_at' => time(),
        ]);

        if ($delay > 0) {
            $redis->zAdd(self::redisDelayedKey($queue), $availableAt, (string) $id);
        } else {
            $redis->rPush(self::redisPendingKey($queue), (string) $id);
        }

        return true;
    }

    protected static function redisDelete(string|int $id): bool
    {
        $redis = self::redis();
        $key = self::redisJobKey((string) $id);
        $queue = $redis->hGet($key, 'queue') ?: 'default';

        $redis->zRem(self::redisDelayedKey($queue), (string) $id);
        $redis->zRem(self::redisProcessingKey($queue), (string) $id);
        $redis->lRem(self::redisPendingKey($queue), (string) $id, 0);
        $redis->del($key);

        return true;
    }

    protected static function redisSize(string $queue): array
    {
        $redis = self::redis();

        $pending = (int) $redis->lLen(self::redisPendingKey($queue));
        $delayed = (int) $redis->zCard(self::redisDelayedKey($queue));
        $processing = (int) $redis->zCard(self::redisProcessingKey($queue));

        $done = 0;
        $failed = 0;
        $prefix = self::redisPrefix() . 'job:';
        $cursor = null;

        do {
            [$cursor, $keys] = $redis->scan($cursor, ['match' => $prefix . '*', 'count' => 100]);
            foreach ($keys as $jobKey) {
                $jobQueue = $redis->hGet($jobKey, 'queue');
                $jobStatus = $redis->hGet($jobKey, 'status');

                if ($jobQueue !== $queue) {
                    continue;
                }

                if ($jobStatus === self::STATUS_DONE) {
                    $done++;
                }
                if ($jobStatus === self::STATUS_FAILED) {
                    $failed++;
                }
            }
        } while ($cursor != 0);

        return [
            self::STATUS_PENDING => $pending + $delayed,
            self::STATUS_PROCESSING => $processing,
            self::STATUS_DONE => $done,
            self::STATUS_FAILED => $failed,
        ];
    }

    protected static function redisPurge(string $queue, string $status): bool
    {
        $redis = self::redis();
        $prefix = self::redisPrefix() . 'job:';
        $cursor = null;

        do {
            [$cursor, $keys] = $redis->scan($cursor, ['match' => $prefix . '*', 'count' => 100]);
            foreach ($keys as $jobKey) {
                $jobQueue = $redis->hGet($jobKey, 'queue');
                $jobStatus = $redis->hGet($jobKey, 'status');

                if ($jobQueue !== $queue || $jobStatus !== $status) {
                    continue;
                }

                $jobId = str_replace($prefix, '', $jobKey);
                $redis->del($jobKey);
                $redis->zRem(self::redisProcessingKey($queue), $jobId);
                $redis->lRem(self::redisPendingKey($queue), $jobId, 0);
            }
        } while ($cursor != 0);

        if ($status === self::STATUS_PENDING) {
            $redis->del(self::redisPendingKey($queue));
            $redis->del(self::redisDelayedKey($queue));
        }

        return true;
    }

    protected static function redisRetryStuck(string $queue): int
    {
        $redis = self::redis();
        $cutoff = time() - self::getRetryAfter();
        $stuckIds = $redis->zRangeByScore(
            self::redisProcessingKey($queue),
            '-inf',
            (string) $cutoff
        );

        if (empty($stuckIds)) {
            return 0;
        }

        $count = 0;

        foreach ($stuckIds as $jobId) {
            $key = self::redisJobKey($jobId);
            $attempts = (int) $redis->hGet($key, 'attempts');
            $max = self::getMaxAttempts();

            $redis->zRem(self::redisProcessingKey($queue), $jobId);

            if ($attempts >= $max) {
                $redis->hMSet($key, [
                    'status' => self::STATUS_FAILED,
                    'updated_at' => time(),
                ]);
            } else {
                $redis->hMSet($key, [
                    'status' => self::STATUS_PENDING,
                    'reserved_at' => '',
                    'updated_at' => time(),
                ]);
                $redis->rPush(self::redisPendingKey($queue), $jobId);
            }

            $count++;
        }

        return $count;
    }

    protected static function redisListJobs(string $queue, string $status, int $limit): array
    {
        $redis = self::redis();
        $prefix = self::redisPrefix() . 'job:';
        $cursor = null;
        $result = [];

        do {
            [$cursor, $keys] = $redis->scan($cursor, ['match' => $prefix . '*', 'count' => 100]);
            foreach ($keys as $jobKey) {
                $jobData = $redis->hGetAll($jobKey);

                if (($jobData['queue'] ?? '') !== $queue) {
                    continue;
                }
                if (($jobData['status'] ?? '') !== $status) {
                    continue;
                }

                $result[] = self::arrayToObject($jobData);

                if (count($result) >= $limit) {
                    break 2;
                }
            }
        } while ($cursor != 0);

        return $result;
    }

    protected static function redisMigrateDelayed(string $queue): void
    {
        $redis = self::redis();
        $now = time();

        $readyIds = $redis->zRangeByScore(
            self::redisDelayedKey($queue),
            '-inf',
            (string) $now
        );

        foreach ($readyIds as $jobId) {
            $redis->zRem(self::redisDelayedKey($queue), $jobId);
            $redis->rPush(self::redisPendingKey($queue), $jobId);

            $redis->hMSet(self::redisJobKey($jobId), [
                'available_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /* =========================================================
     * UTILITIES
     * ========================================================= */

    protected static function flattenForRedis(array $data): array
    {
        return array_map(
            static fn ($v) => $v === null ? '' : $v,
            $data
        );
    }

    protected static function arrayToObject(array $data): object
    {
        return (object) $data;
    }
}
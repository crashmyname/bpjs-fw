<?php

namespace Bpjs\Framework\Helpers;

use Throwable;
use Exception;
use Closure;

/**
 * Event Helper
 *
 * Sistem event/listener sederhana berbasis observer pattern.
 * Mendukung:
 *   - listen()       → daftarkan listener ke event
 *   - dispatch()     → tembak event ke semua listener
 *   - subscribe()    → daftarkan subscriber class
 *   - once()         → listener yang hanya dieksekusi sekali
 *   - prepend()      → listener yang dieksekusi paling awal
 *   - forget()       → hapus listener
 *   - forgetAll()    → hapus semua listener
 *   - hasListeners() → cek apakah event punya listener
 *   - getListeners() → ambil semua listener event
 *   - firing()       → ambil event yang sedang berjalan
 *   - wildcard (*)   → listener untuk semua event
 *   - priority       → urutan eksekusi listener
 *   - stopPropagation → hentikan penyebaran event
 *   - queue dispatch → push ke Queue helper
 *
 * @package Bpjs\Framework\Helpers
 */
class Event
{
    /* =========================================================
     * STATE
     * ========================================================= */

    /** @var array<string, list<array{handler: callable|string, priority: int, once: bool}>> */
    protected static array $listeners = [];

    /** @var list<string>  Stack event yang sedang berjalan */
    protected static array $firing = [];

    /** @var bool Apakah akan membuang exception dari listener */
    protected static bool $throwExceptions = false;

    /** @var callable|null Error handler kustom */
    protected static $errorHandler = null;

    /* =========================================================
     * REGISTRASI LISTENER
     * ========================================================= */

    /**
     * Daftarkan listener ke event.
     *
     * @param  string|array        $events    Nama event atau array nama event
     * @param  callable|string     $listener  Callable atau 'ClassName@method'
     * @param  int                 $priority  Semakin tinggi = dijalankan lebih awal (default: 0)
     */
    public static function listen(
        string|array   $events,
        callable|string $listener,
        int             $priority = 0
    ): void {
        foreach ((array) $events as $event) {
            self::$listeners[$event][] = [
                'handler'  => $listener,
                'priority' => $priority,
                'once'     => false,
            ];

            // Urutkan berdasarkan priority descending
            usort(self::$listeners[$event], static fn ($a, $b) => $b['priority'] <=> $a['priority']);
        }
    }

    /**
     * Listener yang hanya berjalan satu kali, lalu otomatis dihapus.
     *
     * @param  string           $event
     * @param  callable|string  $listener
     * @param  int              $priority
     */
    public static function once(
        string          $event,
        callable|string $listener,
        int             $priority = 0
    ): void {
        self::$listeners[$event][] = [
            'handler'  => $listener,
            'priority' => $priority,
            'once'     => true,
        ];

        usort(self::$listeners[$event], static fn ($a, $b) => $b['priority'] <=> $a['priority']);
    }

    /**
     * Daftarkan listener di posisi paling awal (priority tertinggi).
     *
     * @param  string           $event
     * @param  callable|string  $listener
     */
    public static function prepend(string $event, callable|string $listener): void
    {
        $maxPriority = 0;

        if (!empty(self::$listeners[$event])) {
            $maxPriority = max(
                array_column(self::$listeners[$event], 'priority')
            );
        }

        self::listen($event, $listener, $maxPriority + 1);
    }

    /**
     * Daftarkan subscriber class.
     * Class subscriber harus memiliki method `subscribe(Event $event)` atau
     * method-method public yang diawali `on` diikuti nama event (camelCase).
     *
     * Contoh class subscriber:
     * ```php
     * class UserSubscriber {
     *     public function subscribe(): array {
     *         return [
     *             'user.registered' => 'onUserRegistered',
     *             'user.deleted'    => 'onUserDeleted',
     *         ];
     *     }
     *     public function onUserRegistered($payload) { ... }
     *     public function onUserDeleted($payload) { ... }
     * }
     * ```
     *
     * @param  object|string  $subscriber  Instance atau FQCN
     */
    public static function subscribe(object|string $subscriber): void
    {
        if (is_string($subscriber)) {
            $subscriber = new $subscriber();
        }

        if (!method_exists($subscriber, 'subscribe')) {
            throw new Exception(
                get_class($subscriber) . ' harus memiliki method subscribe().'
            );
        }

        $events = $subscriber->subscribe();

        if (!is_array($events)) {
            throw new Exception(
                get_class($subscriber) . '::subscribe() harus mengembalikan array.'
            );
        }

        foreach ($events as $event => $method) {
            self::listen($event, [$subscriber, $method]);
        }
    }

    /* =========================================================
     * DISPATCH EVENT
     * ========================================================= */

    /**
     * Tembak event dan jalankan semua listener-nya.
     *
     * @param  string  $event    Nama event
     * @param  mixed   $payload  Data yang dikirim ke listener
     * @param  bool    $halt     Jika true, berhenti ketika listener return non-null
     * @return array|null        Hasil semua listener, atau null jika halt
     */
    public static function dispatch(
        string $event,
        mixed  $payload = [],
        bool   $halt    = false
    ): ?array {
        // Pastikan payload selalu array agar konsisten
        if (!is_array($payload)) {
            $payload = [$payload];
        }

        self::$firing[] = $event;

        $results  = [];
        $stopped  = false;

        $listeners = self::getListeners($event);

        foreach ($listeners as $key => $entry) {
            if ($stopped) break;

            try {
                $handler = self::resolveHandler($entry['handler']);
                $result  = $handler(...$payload);
            } catch (Throwable $e) {
                $result = null;
                self::handleError($e, $event);
            }

            // Hapus listener `once`
            if ($entry['once']) {
                self::removeListenerByKey($event, $key);
            }

            if ($result === false) {
                $stopped = true;
            }

            $results[] = $result;

            if ($halt && $result !== null) {
                array_pop(self::$firing);
                return [$result];
            }
        }

        array_pop(self::$firing);

        return $results;
    }

    /**
     * Dispatch event hingga listener pertama yang memberikan nilai non-null.
     *
     * @param  string  $event
     * @param  mixed   $payload
     * @return mixed
     */
    public static function dispatchUntil(string $event, mixed $payload = []): mixed
    {
        $results = self::dispatch($event, $payload, halt: true);

        return $results[0] ?? null;
    }

    /**
     * Dispatch event secara asinkron melalui Queue helper.
     * Listener akan dieksekusi di background worker.
     *
     * @param  string  $event      Nama event
     * @param  mixed   $payload    Data event
     * @param  string  $queue      Nama antrian
     * @param  int     $delay      Tunda eksekusi (detik)
     */
    public static function dispatchAsync(
        string $event,
        mixed  $payload = [],
        string $queue   = 'default',
        int    $delay   = 0
    ): int|bool {
        return Queue::push(
            jobClass: self::class . '@handleAsync',
            data: ['event' => $event, 'payload' => $payload],
            queue: $queue,
            delay: $delay
        );
    }

    /**
     * Handler yang dipanggil worker untuk memproses async event.
     * (Tidak perlu dipanggil manual)
     *
     * @param  array  $data  ['event' => '...', 'payload' => [...]]
     */
    public static function handleAsync(array $data): void
    {
        self::dispatch($data['event'], $data['payload'] ?? []);
    }

    /* =========================================================
     * MANAJEMEN LISTENER
     * ========================================================= */

    /**
     * Hapus semua listener untuk event tertentu.
     *
     * @param  string  $event
     */
    public static function forget(string $event): void
    {
        unset(self::$listeners[$event]);
    }

    /**
     * Hapus semua listener dari semua event.
     */
    public static function forgetAll(): void
    {
        self::$listeners = [];
    }

    /**
     * Cek apakah event memiliki listener.
     *
     * @param  string  $event
     * @return bool
     */
    public static function hasListeners(string $event): bool
    {
        return !empty(self::getListeners($event));
    }

    /**
     * Ambil semua listener untuk event tertentu.
     * Wildcard listener (*) selalu disertakan.
     *
     * @param  string  $event
     * @return array
     */
    public static function getListeners(string $event): array
    {
        $direct   = self::$listeners[$event] ?? [];
        $wildcard = self::$listeners['*'] ?? [];

        // Gabungkan dan urutkan ulang berdasarkan priority
        $merged = array_merge($direct, $wildcard);
        usort($merged, static fn ($a, $b) => $b['priority'] <=> $a['priority']);

        return $merged;
    }

    /**
     * Ambil semua event yang terdaftar beserta jumlah listener-nya.
     *
     * @return array<string, int>
     */
    public static function getAllEvents(): array
    {
        return array_map('count', self::$listeners);
    }

    /**
     * Ambil nama event yang sedang dalam proses dispatch.
     *
     * @return string|null
     */
    public static function firing(): ?string
    {
        return end(self::$firing) ?: null;
    }

    /**
     * Cek apakah event tertentu sedang dalam proses dispatch.
     *
     * @param  string  $event
     * @return bool
     */
    public static function isFiring(string $event): bool
    {
        return in_array($event, self::$firing, true);
    }

    /* =========================================================
     * KONFIGURASI ERROR
     * ========================================================= */

    /**
     * Aktifkan/nonaktifkan throw exception dari listener.
     * Default: false (exception ditelan).
     *
     * @param  bool  $throw
     */
    public static function throwExceptions(bool $throw = true): void
    {
        self::$throwExceptions = $throw;
    }

    /**
     * Set error handler kustom untuk exception dari listener.
     * Handler menerima (Throwable $e, string $event).
     *
     * @param  callable  $handler
     */
    public static function onError(callable $handler): void
    {
        self::$errorHandler = $handler;
    }

    /* =========================================================
     * INTERNALS
     * ========================================================= */

    /**
     * Resolve handler dari string 'Class@method' atau callable
     */
    protected static function resolveHandler(callable|string $handler): callable
    {
        if (is_callable($handler)) {
            return $handler;
        }

        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);

            if (!class_exists($class)) {
                throw new Exception("Event listener class [{$class}] tidak ditemukan.");
            }

            $instance = new $class();

            if (!method_exists($instance, $method)) {
                throw new Exception(
                    "Method [{$method}] tidak ditemukan di [{$class}]."
                );
            }

            return [$instance, $method];
        }

        throw new Exception('Event listener tidak valid: ' . (string) $handler);
    }

    protected static function handleError(Throwable $e, string $event): void
    {
        if (self::$errorHandler !== null) {
            (self::$errorHandler)($e, $event);
            return;
        }

        if (self::$throwExceptions) {
            throw $e;
        }

        // Default: tulis ke error log
        error_log(
            sprintf('[Event] Error di event [%s]: %s in %s:%d',
                $event,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            )
        );
    }

    protected static function removeListenerByKey(string $event, int $key): void
    {
        unset(self::$listeners[$event][$key]);

        if (empty(self::$listeners[$event])) {
            unset(self::$listeners[$event]);
        } else {
            self::$listeners[$event] = array_values(self::$listeners[$event]);
        }
    }
}
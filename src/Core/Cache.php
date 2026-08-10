<?php

namespace Bpjs\Framework\Core;

class Cache
{
    private static ?CacheDriver $driver = null;

    public static function init(CacheDriver $driver): void
    {
        self::$driver = $driver;
    }

    public static function isInitialized(): bool
    {
        return self::$driver !== null;
    }

    public static function get(string $key)
    {
        if (self::$driver === null) {
            return null;
        }
        return self::$driver->get($key);
    }

    public static function put(string $key, $value, int $ttl = 60)
    {
        if (self::$driver === null) {
            return false;
        }
        return self::$driver->set($key, $value, $ttl);
    }

    public static function forget(string $key)
    {
        if (self::$driver === null) {
            return false;
        }
        return self::$driver->delete($key);
    }

    public static function has(string $key): bool
    {
        if (self::$driver === null) {
            return false;
        }
        return self::$driver->has($key);
    }

    public static function clear(): bool
    {
        if (self::$driver === null) {
            return false;
        }
        return self::$driver->clear();
    }

    public static function remember(string $key, int $ttl, callable $callback)
    {
        if (self::has($key)) {
            return self::get($key);
        }

        $value = $callback();

        self::put($key, $value, $ttl);

        return $value;
    }
}
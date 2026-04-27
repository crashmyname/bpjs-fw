<?php

namespace Bpjs\Framework\Core;

class Cache
{
    private static CacheDriver $driver;

    public static function init(CacheDriver $driver)
    {
        self::$driver = $driver;
    }

    public static function get(string $key)
    {
        return self::$driver->get($key);
    }

    public static function put(string $key, $value, int $ttl = 60)
    {
        return self::$driver->set($key, $value, $ttl);
    }

    public static function forget(string $key)
    {
        return self::$driver->delete($key);
    }

    public static function has(string $key): bool
    {
        return self::$driver->has($key);
    }

    public static function clear()
    {
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

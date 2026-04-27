<?php

namespace Bpjs\Framework\Core;

interface CacheDriver
{
    public function get(string $key);
    public function set(string $key, $value, int $ttl = 60);
    public function delete(string $key);
    public function clear();
    public function has(string $key): bool;
}

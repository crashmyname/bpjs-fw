<?php

namespace Bpjs\Framework\Core;

class FileCacheDriver implements CacheDriver
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = rtrim($path, '/');

        if (!is_dir($this->path)) {
            mkdir($this->path, 0777, true);
        }
    }

    private function filename(string $key): string
    {
        return $this->path . '/' . md5($key) . '.cache';
    }

    public function set(string $key, $value, int $ttl = 60)
    {
        $data = [
            'expired_at' => time() + $ttl,
            'value' => serialize($value)
        ];

        file_put_contents($this->filename($key), serialize($data));
    }

    public function get(string $key)
    {
        $file = $this->filename($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = unserialize(file_get_contents($file));

        if ($data['expired_at'] < time()) {
            unlink($file);
            return null;
        }

        return unserialize($data['value']);
    }

    public function delete(string $key)
    {
        $file = $this->filename($key);

        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function clear()
    {
        foreach (glob($this->path . '/*.cache') as $file) {
            unlink($file);
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }
}

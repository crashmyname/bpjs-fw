<?php

namespace Bpjs\Framework\Helpers\Session;

class FlashBag
{
    protected string $key = '_flash';

    public function add(string $key, $value): void
    {
        $_SESSION[$this->key][$key] = $value;
    }

    public function get(string $key, $default = null)
    {
        $value = $_SESSION[$this->key][$key] ?? $default;
        unset($_SESSION[$this->key][$key]);
        return $value;
    }

    public function all(): array
    {
        $data = $_SESSION[$this->key] ?? [];
        unset($_SESSION[$this->key]);
        return $data;
    }
}

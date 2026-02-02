<?php

namespace Bpjs\Framework\Helpers\Session;

class SessionManager
{
    protected bool $started = false;
    protected FlashBag $flash;

    public function __construct()
    {
        $this->flash = new FlashBag();
    }

    public function flash(string $key, $value): void
    {
        $this->start();
        $this->flash->add($key, $value);
    }

    public function getFlash(string $key, $default = null)
    {
        $this->start();
        return $this->flash->get($key, $default);
    }

    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_start();
        $this->started = true;
    }

    public function get(string $key, $default = null)
    {
        $this->start();
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->start();
        return isset($_SESSION[$key]);
    }

    public function forget(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function all(): array
    {
        $this->start();
        return $_SESSION;
    }

    public function destroy(): void
    {
        $this->start();
        session_destroy();
        $_SESSION = [];
    }
}

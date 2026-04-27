<?php

namespace Bpjs\Core;

use Bpjs\Framework\Helpers\View;

class App
{
    protected array $bindings = [];

    public function singleton(string $abstract, callable $concrete)
    {
        $this->bindings[$abstract] = $concrete($this);
    }

    public function make(string $abstract)
    {
        if (!isset($this->bindings[$abstract])) {
            throw new \Exception("Service {$abstract} tidak terdaftar.");
        }
        return $this->bindings[$abstract];
    }
}

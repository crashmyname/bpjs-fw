<?php
namespace Bpjs\Framework\Helpers\Live;

abstract class LiveComponent
{
    public string $id;

    public function __construct()
    {
        $this->id = uniqid('live_', true);
    }

    public function mount() {}
    public function hydrate() {}
    public function dehydrate() {}

    abstract public function render(): string;

    public function state(): array
    {
        return get_object_vars($this);
    }
}

<?php
namespace Bpjs\Framework\Helpers\Live;

class LiveRequest
{
    public ?string $componentId;
    public array $payload;

    public static function capture(): self
    {
        $req = new self;
        $req->componentId = $_POST['_live_id'] ?? null;
        $req->payload = $_POST;
        unset($req->payload['_live_id']);
        return $req;
    }
}

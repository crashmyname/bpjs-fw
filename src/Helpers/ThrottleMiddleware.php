<?php
namespace Bpjs\Framework\Helpers;

class ThrottleMiddleware
{
    protected int $maxRequests;

    public function __construct(int $maxRequests = 60)
    {
        $this->maxRequests = $maxRequests;
    }

    public function handle($request)
    {
        $request->setRateLimit($this->maxRequests);

        (new \Middlewares\LimitRequests())->handle($request);
    }
}
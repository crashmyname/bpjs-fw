<?php
use Bpjs\Framework\Helpers\View;
use Bpjs\Framework\Helpers\RateLimiter;
use Bpjs\Framework\Helpers\CORSMiddleware;
use Middlewares\SessionMiddleware;

function handleMiddleware() {
    SessionMiddleware::start();
    $rateLimiter = new RateLimiter();
    if (!$rateLimiter->check($_SERVER['REMOTE_ADDR'])) {
        http_response_code(429);
        include BPJS_BASE_PATH . '/app/handle/errors/429.php';
        exit();
    }
    
    CORSMiddleware::handle();
}

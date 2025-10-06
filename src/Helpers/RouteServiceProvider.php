<?php

namespace Bpjs\Framework\Helpers;

use Bpjs\Framework\Helpers\Route;
use Bpjs\Framework\Helpers\Api;

class RouteServiceProvider
{
    public function map()
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $apiPrefix = '/api';

        if (strpos($uri, $apiPrefix) === 0) {
            Api::init($apiPrefix);
            require BPJS_BASE_PATH . '/routes/api.php';
        } else {
            Route::init('/');
            require BPJS_BASE_PATH . '/routes/web.php';
        }
    }
}

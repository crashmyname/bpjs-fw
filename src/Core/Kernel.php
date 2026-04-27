<?php

namespace Bpjs\Framework\Core;
use Bpjs\Framework\Helpers\Api;
use Bpjs\Framework\Helpers\Route;
use Bpjs\Framework\Helpers\View;

class Kernel
{
    protected array $middleware = [
        \Bpjs\Framework\Helpers\CORSMiddleware::class,
    ]; 
    protected string $dispatcherType = 'web';

    public function __construct(protected App $app)
    {
        $this->mapRoutes();
    }

    protected function mapRoutes(): void
    {
        if (php_sapi_name() === 'cli') {
            Route::init(app_base_path());
            require BPJS_BASE_PATH . '/routes/web.php';

            Api::init(api_prefix());
            require BPJS_BASE_PATH . '/routes/api.php';

            return;
        }
        $cacheFile = BPJS_BASE_PATH . '/storage/cache/routes.php';

        if (file_exists($cacheFile)) {
            $routes = require $cacheFile;

            if ($this->isApiRequest()) {
                $this->dispatcherType = 'api';
                Api::init(api_prefix());
                Api::setRoutes($routes['api']);
                Api::setNames($routes['api_names'] ?? []);
            } else {
                $this->dispatcherType = 'web';
                Route::init(app_base_path());
                Route::setRoutes($routes['web']);
                Route::setNames($routes['web_names'] ?? []);
            }

            return;
        }

        // fallback normal
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = parse_url($uri, PHP_URL_PATH);

        if (str_starts_with($uri, '/api')) {
            $this->dispatcherType = 'api';
            Api::init(api_prefix());
            require BPJS_BASE_PATH . '/routes/api.php';
        } else {
            $this->dispatcherType = 'web';
            Route::init(app_base_path());
            require BPJS_BASE_PATH . '/routes/web.php';
        }
    }

    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $uri = parse_url($uri, PHP_URL_PATH);
        return str_starts_with($uri, '/api');
    }

    private function getUri(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    }

    public function handle(Request $request): Response
    {
        foreach ($this->middleware as $middleware) {
            (new $middleware())->handle($request);
        }
        return match ($this->dispatcherType) {
            'web' => Route::dispatch(),
            'api' => Api::dispatch(),
            default => new \Bpjs\Core\Response('Dispatcher not found', 500)
        };
    }

    public function terminate(): void
    {
        // Bisa untuk logging, session cleanup, dsb.
    }

    public function addMiddleware(string $class): void
    {
        $this->middleware[] = $class;
    }
}

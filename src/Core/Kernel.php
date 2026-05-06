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
        $this->dispatcherType = $this->isApiRequest() ? 'api' : 'web';

        $cacheFile = BPJS_BASE_PATH . '/storage/cache/routes.php';

        if (file_exists($cacheFile)) {
            $routes = require $cacheFile;

            Route::init(app_base_path());
            Route::setRoutes($routes['web']);
            Route::setNames($routes['web_names'] ?? []);

            Api::init('/api');
            Api::setRoutes($routes['api']);
            Api::setNames($routes['api_names'] ?? []);

            return;
        }

        Route::init(app_base_path());
        Api::init('/api');

        require BPJS_BASE_PATH . '/routes/web.php';
        require BPJS_BASE_PATH . '/routes/api.php';
    }

    private function isApiRequest(): bool
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

        if ($base && $base !== '/' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

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
            default => new \Bpjs\Framework\Core\Response('Dispatcher not found', 500)
        };
    }

    public function terminate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function reset(): void
    {
        // reset global state
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_COOKIE = [];
        $_REQUEST = [];

        // reset route current
        foreach ($_SESSION ?? [] as $k => $v) {
            unset($_SESSION[$k]);
        }

        Route::flushCurrent();
        Route::reset();

        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        // reset container kalau perlu
        if (method_exists($this->app, 'reset')) {
            $this->app->reset();
        }
    }

    public function addMiddleware(string $class): void
    {
        $this->middleware[] = $class;
    }
}

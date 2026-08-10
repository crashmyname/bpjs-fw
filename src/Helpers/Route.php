<?php

namespace Bpjs\Framework\Helpers;

use Bpjs\Framework\Core\Request;
use Bpjs\Framework\Core\Response;
use Bpjs\Framework\Core\Cache;
use Bpjs\Framework\Core\Container;
use Bpjs\Framework\Core\FormRequest;
use Bpjs\Framework\Helpers\View;
use Middlewares\SessionMiddleware;
use ReflectionClass;
use ReflectionMethod;
use Throwable;
use Closure;

/**
 * Route Helper - v2.5.1 (Fixed & Enhanced)
 *
 * Changelog dari 2.5.0:
 * - Fix: addRoute() tidak lagi prepend prefix (dispatch sudah handle)
 * - Fix: Cache::get() null check diperbaiki
 * - New: Route::redirect()
 * - New: Route::view() shortcut
 * - New: Route::resource() RESTful helper
 * - New: Named route dengan query string
 */
class Route
{
    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    private static array $routes = [];
    private static array $names = [];
    private static string $prefix = '';
    private static array $groupMiddlewares = [];
    private static ?string $lastRouteMethod = null;
    private static ?string $lastRouteUri = null;
    private static array $currentRoute = [];
    private static array $reflectionCache = [];
    private static $notFoundHandler = null;
    private static $errorHandler = null;

    // =========================================================================
    // Initialisation
    // =========================================================================

    public static function init(string $prefix = ''): void
    {
        self::$routes = array_fill_keys(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'],
            []
        );
        self::$prefix = rtrim($prefix, '/');
    }

    // =========================================================================
    // Route Registration
    // =========================================================================

    public static function get(string $uri, $handler, array $middlewares = []): static
    {
        return self::addRoute('GET', $uri, $handler, $middlewares);
    }

    public static function post(string $uri, $handler, array $middlewares = []): static
    {
        return self::addRoute('POST', $uri, $handler, $middlewares);
    }

    public static function put(string $uri, $handler, array $middlewares = []): static
    {
        return self::addRoute('PUT', $uri, $handler, $middlewares);
    }

    public static function patch(string $uri, $handler, array $middlewares = []): static
    {
        return self::addRoute('PATCH', $uri, $handler, $middlewares);
    }

    public static function delete(string $uri, $handler, array $middlewares = []): static
    {
        return self::addRoute('DELETE', $uri, $handler, $middlewares);
    }

    public static function options(string $uri, $handler, array $middlewares = []): static
    {
        return self::addRoute('OPTIONS', $uri, $handler, $middlewares);
    }

    public static function match(array $methods, string $uri, $handler, array $middlewares = []): static
    {
        foreach ($methods as $method) {
            self::addRoute(strtoupper($method), $uri, $handler, $middlewares);
        }
        return new static();
    }

    public static function any(string $uri, $handler, array $middlewares = []): static
    {
        return self::match(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            $uri,
            $handler,
            $middlewares
        );
    }

    // =========================================================================
    // Shortcut Routes
    // =========================================================================

    /**
     * Redirect route.
     */
    public static function redirect(string $from, string $to, int $status = 302): void
    {
        self::get($from, function () use ($to, $status) {
            return new Response('', $status, ['Location' => $to]);
        });
    }

    /**
     * View-only route (no logic, just render).
     */
    public static function view(string $uri, string $view, array $data = [], ?string $layout = null): void
    {
        self::get($uri, function () use ($view, $data, $layout) {
            return View::render($view, $data, $layout);
        });
    }

    /**
     * RESTful resource routes.
     *
     * @param string $name        Resource name (e.g. 'users')
     * @param string $controller  Controller class
     */
    public static function resource(string $name, string $controller): void
    {
        $base = '/' . trim($name, '/');
        $id = '{id}';

        self::get($base, [$controller, 'index']);
        self::get($base . '/create', [$controller, 'create']);
        self::post($base, [$controller, 'store']);
        self::get($base . '/' . $id, [$controller, 'show']);
        self::get($base . '/' . $id . '/edit', [$controller, 'edit']);
        self::put($base . '/' . $id, [$controller, 'update']);
        self::patch($base . '/' . $id, [$controller, 'update']);
        self::delete($base . '/' . $id, [$controller, 'destroy']);
    }

    // =========================================================================
    // Internal Registration
    // =========================================================================

    /**
     * FIX: Tidak prepend prefix — dispatch() sudah handle stripping.
     * Prefix hanya dipakai saat dispatch untuk mencocokkan URI.
     */
    private static function addRoute(string $method, string $uri, $handler, array $middlewares): static
    {
        $fullUri = '/' . ltrim($uri, '/');

        $middlewares = array_merge(self::$groupMiddlewares, $middlewares);

        self::$routes[$method][$fullUri] = [
            'handler'     => $handler,
            'middlewares' => $middlewares,
        ];

        self::$lastRouteMethod = $method;
        self::$lastRouteUri    = $fullUri;

        return new static();
    }

    // =========================================================================
    // Grouping
    // =========================================================================

    public static function group(array $middlewares, Closure $routes): void
    {
        $previous = self::$groupMiddlewares;
        self::$groupMiddlewares = array_merge($previous, $middlewares);
        $routes();
        self::$groupMiddlewares = $previous;
    }

    public static function prefix(string $prefix, Closure $routes, array $middlewares = []): void
    {
        $previousPrefix = self::$prefix;
        $previousMiddlewares = self::$groupMiddlewares;

        self::$prefix = rtrim($previousPrefix . '/' . trim($prefix, '/'), '/');
        self::$groupMiddlewares = array_merge($previousMiddlewares, $middlewares);

        $routes();

        self::$prefix = $previousPrefix;
        self::$groupMiddlewares = $previousMiddlewares;
    }

    // =========================================================================
    // Named Routes
    // =========================================================================

    public static function name(string $name): static
    {
        if (self::$lastRouteMethod && self::$lastRouteUri) {
            self::$names[$name] = self::$lastRouteUri;
            return new static();
        }

        self::handleError(new \Exception("Route::name('{$name}') called without a prior route registration."));
        return new static();
    }

    public static function route(string $name, array $params = [], array $query = []): string
    {
        if (!isset(self::$names[$name])) {
            self::handleError(new \Exception("Named route '{$name}' not found."));
            return '';
        }

        $uri = self::$names[$name];

        foreach ($params as $key => $value) {
            $uri = str_replace('{' . $key . '}', rawurlencode((string) $value), $uri);
        }

        // Gunakan base_url() yang sudah include subfolder, bukan APP_URL langsung
        $baseUrl = rtrim(base_url(), '/');
        $url = $baseUrl . '/' . ltrim($uri, '/');

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    // =========================================================================
    // Throttling
    // =========================================================================

    public function limit(int $maxRequests, int $decaySeconds = 60): static
    {
        $method = self::$lastRouteMethod;
        $uri = self::$lastRouteUri;

        if (!$method || !$uri) {
            throw new \RuntimeException("No route context available for applying limit().");
        }

        self::$routes[$method][$uri]['middlewares'][] = [
            'class'  => \Bpjs\Framework\Helpers\ThrottleMiddleware::class,
            'params' => [$maxRequests, $decaySeconds],
        ];

        return $this;
    }

    // =========================================================================
    // Fallback / Custom Error Handlers
    // =========================================================================

    public static function fallback(callable $handler): void
    {
        self::$notFoundHandler = $handler;
    }

    public static function onError(callable $handler): void
    {
        self::$errorHandler = $handler;
    }

    // =========================================================================
    // Dispatch
    // =========================================================================

    public static function dispatch(): Response
    {
        try {
            SessionMiddleware::start();

            $validDevice = SessionMiddleware::validateDeviceFingerprint();
            if (!$validDevice && config('auth.device_fingerprint.strict')) {
                Session::remove('user');
            }

            // Resolve method
            $method = strtoupper($_SERVER['REQUEST_METHOD']);
            if ($method === 'POST' && !empty($_POST['_method'])) {
                $overridden = strtoupper($_POST['_method']);
                if (in_array($overridden, ['PUT', 'PATCH', 'DELETE'], true)) {
                    $method = $overridden;
                }
            }

            // Resolve URI — strip prefix
            $uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
            if (self::$prefix !== '' && str_starts_with($uri, self::$prefix)) {
                $uri = substr($uri, strlen(self::$prefix));
            }
            $uri = '/' . ltrim($uri ?: '/', '/');

            $request = new Request();

            // Route lookup
            $route = self::findRoute($method, $uri);

            if (!$route) {
                return self::handleNotFound($request);
            }

            // Store current route
            self::$currentRoute = [
                'method'      => $method,
                'uri'         => $uri,
                'handler'     => $route['handler'],
                'middlewares' => $route['middlewares'] ?? [],
            ];

            // GET cache
            $cacheKey = null;
            if ($method === 'GET') {
                $cacheKey = 'route_' . md5($uri . serialize($_GET));
                $cached = Cache::get($cacheKey);
                if ($cached !== null) {
                    return new Response($cached);
                }
            }

            // Run middlewares
            self::runMiddlewares($route['middlewares'] ?? [], $request);

            // CSRF check
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $token = $request->get('csrf_token')
                    ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

                if (
                    empty($token)
                    || empty($_SESSION['csrf_token'])
                    || !hash_equals($_SESSION['csrf_token'], $token)
                ) {
                    return new Response('Invalid CSRF Token', 419);
                }
            }

            // Call handler
            $result = self::callHandler($route['handler'], $route['params'] ?? [], $request);

            $response = $result instanceof Response
                ? $result
                : new Response($result);

            // Cache GET
            if ($method === 'GET' && $response->getStatusCode() === 200 && $cacheKey) {
                Cache::put($cacheKey, $response->getContent(), 60);
            }

            return $response;

        } catch (Throwable $e) {
            return self::handleServerError($e);
        }
    }

    // =========================================================================
    // State Helpers
    // =========================================================================

    public static function current(): array
    {
        return self::$currentRoute;
    }

    public static function flushCurrent(): void
    {
        self::$currentRoute = [];
    }

    public static function reset(): void
    {
        self::$currentRoute = [];
        self::$reflectionCache = [];
    }

    public static function export(): array
    {
        return self::$routes;
    }

    public static function setRoutes(array $routes): void
    {
        self::$routes = $routes;
    }

    public static function exportNames(): array
    {
        return self::$names;
    }

    public static function setNames(array $names): void
    {
        self::$names = $names;
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    private static function findRoute(string $method, string $uri): ?array
    {
        $routes = self::$routes[$method] ?? [];

        // Exact match
        if (isset($routes[$uri])) {
            $route = $routes[$uri];
            $route['params'] = [];
            return $route;
        }

        // Pattern match
        foreach ($routes as $routeUri => $route) {
            // Support optional params {param?}
            $pattern = preg_replace('/\{[a-zA-Z0-9_]+\?\}/', '([a-zA-Z0-9_\-]*)', $routeUri);
            // Required params {param}
            $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([a-zA-Z0-9_\-]+)', $pattern);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                $route['params'] = $matches;
                return $route;
            }
        }

        // HEAD → GET
        if ($method === 'HEAD') {
            return self::findRoute('GET', $uri);
        }

        return null;
    }

    private static function runMiddlewares(array $middlewares, Request $request): void
    {
        foreach ($middlewares as $middleware) {
            if (is_array($middleware) && isset($middleware['class'])) {
                $class = $middleware['class'];
                $params = $middleware['params'] ?? [];
                $instance = (new ReflectionClass($class))->newInstanceArgs($params);
                $instance->handle($request);
            } elseif (is_string($middleware)) {
                (new $middleware())->handle($request);
            } elseif (is_callable($middleware)) {
                $middleware($request);
            }
        }
    }

    private static function callHandler($handler, array $routeParams, Request $request): mixed
    {
        if (is_array($handler) && count($handler) === 2) {
            [$controllerClass, $method] = $handler;

            $container = new Container();
            $controllerInstance = $container->make($controllerClass);

            $cacheKey = $controllerClass . '@' . $method;
            if (!isset(self::$reflectionCache[$cacheKey])) {
                self::$reflectionCache[$cacheKey] = new ReflectionMethod($controllerInstance, $method);
            }

            $reflection = self::$reflectionCache[$cacheKey];
            $methodParams = [];

            foreach ($reflection->getParameters() as $param) {
                $type = $param->getType();

                if ($type && !$type->isBuiltin()) {
                    $className = $type->getName();

                    if ($className === Request::class) {
                        $methodParams[] = $request;
                    } elseif (is_subclass_of($className, FormRequest::class)) {
                        $methodParams[] = new $className($request);
                    } else {
                        $methodParams[] = $container->make($className);
                    }
                } else {
                    $methodParams[] = array_shift($routeParams);
                }
            }

            return $reflection->invokeArgs($controllerInstance, $methodParams);
        }

        if (is_callable($handler)) {
            return call_user_func_array($handler, $routeParams);
        }

        throw new \RuntimeException("Route handler must be callable or [Controller::class, 'method'].");
    }

    private static function handleNotFound(Request $request): Response
    {
        if (self::$notFoundHandler !== null) {
            $result = (self::$notFoundHandler)($request);
            return $result instanceof Response ? $result : new Response($result, 404);
        }

        ob_start();
        $errorFile = BPJS_BASE_PATH . '/app/handle/errors/404.php';
        if (file_exists($errorFile)) {
            include $errorFile;
        } else {
            echo '<h1>404 Not Found</h1>';
        }
        return new Response(ob_get_clean(), 404);
    }

    private static function handleServerError(Throwable $e): Response
    {
        if (env('APP_DEBUG') === 'true') {
            throw $e;
        }

        if (self::$errorHandler !== null) {
            $request = new Request();
            $result = (self::$errorHandler)($e, $request);
            return $result instanceof Response ? $result : new Response($result, 500);
        }

        $isJson = Request::isAjax()
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

        if ($isJson) {
            return new Response(
                json_encode(['statusCode' => 500, 'error' => 'Internal Server Error']),
                500,
                ['Content-Type' => 'application/json']
            );
        }

        return View::error(500);
    }

    private static function handleError(\Exception $e): never
    {
        if (env('APP_DEBUG') === 'true') {
            throw $e;
        }

        error_log('[Route] ' . $e->getMessage());

        $isJson = Request::isAjax()
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

        if ($isJson) {
            header('Content-Type: application/json', true, 500);
            echo json_encode(['statusCode' => 500, 'error' => 'Internal Server Error']);
        } else {
            View::error(500);
        }

        exit;
    }
}
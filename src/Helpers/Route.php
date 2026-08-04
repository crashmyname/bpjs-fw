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

/**
 * Route Helper
 *
 * Handles HTTP routing, middleware chaining, named routes,
 * prefix grouping, CSRF validation, throttling, and caching.
 *
 * @version 2.0.0
 */
class Route
{
    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var array<string, array<string, array>> Registered routes per HTTP method */
    private static array $routes = [];

    /** @var array<string, string> Named route map */
    private static array $names = [];

    /** @var string Current URL prefix (supports nesting) */
    private static string $prefix = '';

    /** @var array Middlewares inherited by the current group */
    private static array $groupMiddlewares = [];

    /** @var string|null HTTP method of the last registered route */
    private static ?string $lastRouteMethod = null;

    /** @var string|null URI of the last registered route */
    private static ?string $lastRouteUri = null;

    /** @var array Current dispatched route info */
    private static array $currentRoute = [];

    /** @var array<string, ReflectionMethod> Reflection method cache */
    private static array $reflectionCache = [];

    /** @var callable|null Custom 404 handler */
    private static $notFoundHandler = null;

    /** @var callable|null Custom 500 handler */
    private static $errorHandler = null;


    // =========================================================================
    // Initialisation
    // =========================================================================

    /**
     * Bootstrap the router.
     *
     * @param string $prefix  Global URL prefix (e.g. '/api/v1').
     */
    public static function init(string $prefix = ''): void
    {
        self::$routes = array_fill_keys(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], []);
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

    /**
     * Register a route for multiple HTTP methods at once.
     *
     * @param string[] $methods   e.g. ['GET', 'POST']
     */
    public static function match(array $methods, string $uri, $handler, array $middlewares = []): static
    {
        foreach ($methods as $method) {
            self::addRoute(strtoupper($method), $uri, $handler, $middlewares);
        }
        return new static();
    }

    /**
     * Register a route for ALL HTTP methods.
     */
    public static function any(string $uri, $handler, array $middlewares = []): static
    {
        return self::match(array_keys(self::$routes), $uri, $handler, $middlewares);
    }

    /**
     * Core internal registration — all public methods delegate here.
     */
    private static function addRoute(string $method, string $uri, $handler, array $middlewares): static
    {
        // Prepend active prefix
        $fullUri = self::$prefix !== ''
            ? rtrim(self::$prefix, '/') . '/' . ltrim($uri, '/')
            : $uri;
        $fullUri = '/' . ltrim($fullUri, '/');

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

    /**
     * Apply shared middlewares to a group of routes.
     *
     * @param array    $middlewares  Middleware list for the group.
     * @param \Closure $routes       Closure that registers child routes.
     */
    public static function group(array $middlewares, \Closure $routes): void
    {
        $previous = self::$groupMiddlewares;
        // Merge so nested groups stack correctly
        self::$groupMiddlewares = array_merge($previous, $middlewares);
        $routes();
        self::$groupMiddlewares = $previous;
    }

    /**
     * Apply a URL prefix to a group of routes, optionally with middlewares.
     *
     * @param string   $prefix
     * @param \Closure $routes
     * @param array    $middlewares  Optional middlewares scoped to this prefix group.
     */
    public static function prefix(string $prefix, \Closure $routes, array $middlewares = []): void
    {
        $previousPrefix      = self::$prefix;
        $previousMiddlewares = self::$groupMiddlewares;

        self::$prefix           = rtrim($previousPrefix . '/' . trim($prefix, '/'), '/');
        self::$groupMiddlewares = array_merge($previousMiddlewares, $middlewares);

        $routes();

        self::$prefix           = $previousPrefix;
        self::$groupMiddlewares = $previousMiddlewares;
    }


    // =========================================================================
    // Named Routes
    // =========================================================================

    /**
     * Assign a name to the last registered route.
     */
    public static function name(string $name): static
    {
        if (self::$lastRouteMethod && self::$lastRouteUri) {
            self::$names[$name] = self::$lastRouteUri;
            return new static();
        }

        self::handleError(new \Exception("Route::name('{$name}') called without a prior route registration."));
        return new static(); // unreachable but satisfies return type
    }

    /**
     * Generate a URL for a named route.
     *
     * @param string               $name    Route name.
     * @param array<string, mixed> $params  URI parameter substitutions.
     * @param array<string, mixed> $query   Optional query string parameters.
     */
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

        $baseUrl = rtrim(env('APP_URL', ''), '/');
        $url     = $baseUrl . '/' . trim($uri, '/');

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }


    // =========================================================================
    // Throttling
    // =========================================================================

    /**
     * Add a rate-limit to the last registered route.
     *
     * @param int $maxRequests  Max requests per window.
     * @param int $decaySeconds Time window in seconds (default 60).
     */
    public function limit(int $maxRequests, int $decaySeconds = 60): static
    {
        $method = self::$lastRouteMethod;
        $uri    = self::$lastRouteUri;

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

    /**
     * Register a custom 404 (not-found) handler.
     *
     * @param callable $handler  Receives (Request $request): Response
     */
    public static function fallback(callable $handler): void
    {
        self::$notFoundHandler = $handler;
    }

    /**
     * Register a custom 500 (server-error) handler.
     *
     * @param callable $handler  Receives (Throwable $e, Request $request): Response
     */
    public static function onError(callable $handler): void
    {
        self::$errorHandler = $handler;
    }


    // =========================================================================
    // Dispatch
    // =========================================================================

    /**
     * Resolve the current HTTP request and return a Response.
     */
    public static function dispatch(): Response
    {
        try {
            SessionMiddleware::start();

            $validDevice = SessionMiddleware::validateDeviceFingerprint();
            if (!$validDevice && config('auth.device_fingerprint.strict')) {
                Session::remove('user');
            }

            // --- Resolve method (support _method override) ---
            $method = strtoupper($_SERVER['REQUEST_METHOD']);
            if ($method === 'POST' && !empty($_POST['_method'])) {
                $overridden = strtoupper($_POST['_method']);
                if (in_array($overridden, ['PUT', 'PATCH', 'DELETE'], true)) {
                    $method = $overridden;
                }
            }

            // --- Resolve URI (strip prefix & query string) ---
            $uri = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
            if (self::$prefix !== '' && str_starts_with($uri, self::$prefix)) {
                $uri = substr($uri, strlen(self::$prefix));
            }
            $uri = '/' . ltrim($uri ?: '/', '/');

            $request = new Request();

            // --- Route lookup ---
            $route = self::findRoute($method, $uri);

            if (!$route) {
                return self::handleNotFound($request);
            }

            // --- Store current route ---
            self::$currentRoute = [
                'method'      => $method,
                'uri'         => $uri,
                'handler'     => $route['handler'],
                'middlewares' => $route['middlewares'] ?? [],
            ];

            // --- GET cache shortcut ---
            $cacheKey = null;
            if ($method === 'GET') {
                $cacheKey = 'route_' . md5($uri . serialize($_GET));
                $cached   = Cache::get($cacheKey);
                if ($cached !== null) {
                    return new Response($cached);
                }
            }

            // --- Run middlewares ---
            self::runMiddlewares($route['middlewares'] ?? [], $request);

            // --- CSRF check (POST / PUT / PATCH / DELETE) ---
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

            // --- Call handler ---
            $result = self::callHandler($route['handler'], $route['params'] ?? [], $request);

            $response = $result instanceof Response
                ? $result
                : new Response($result);

            // --- Cache successful GET responses ---
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
        self::$currentRoute    = [];
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

    /**
     * Find a matching registered route for the given method + URI.
     *
     * @return array|null  Route data including extracted `params`, or null.
     */
    private static function findRoute(string $method, string $uri): ?array
    {
        $routes = self::$routes[$method] ?? [];

        // 1. Exact match first (fastest path, no regex needed)
        if (isset($routes[$uri])) {
            $route           = $routes[$uri];
            $route['params'] = [];
            return $route;
        }

        // 2. Pattern match for dynamic segments
        foreach ($routes as $routeUri => $route) {
            $pattern = preg_replace('/\{[a-zA-Z0-9_]+\?\}/', '([a-zA-Z0-9_\-]*)', $routeUri);  // optional {param?}
            $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/',   '([a-zA-Z0-9_\-]+)', $pattern);   // required {param}
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                $route['params'] = $matches;
                return $route;
            }
        }

        // 3. HEAD falls back to GET handlers
        if ($method === 'HEAD') {
            return self::findRoute('GET', $uri);
        }

        return null;
    }

    /**
     * Execute all middlewares in the stack.
     */
    private static function runMiddlewares(array $middlewares, Request $request): void
    {
        foreach ($middlewares as $middleware) {
            if (is_array($middleware) && isset($middleware['class'])) {
                // ['class' => FooMiddleware::class, 'params' => [...]]
                $class    = $middleware['class'];
                $params   = $middleware['params'] ?? [];
                $instance = (new ReflectionClass($class))->newInstanceArgs($params);
                $instance->handle($request);

            } elseif (is_string($middleware)) {
                (new $middleware())->handle($request);

            } elseif (is_callable($middleware)) {
                $middleware($request);
            }
        }
    }

    /**
     * Invoke a route handler (controller array or callable).
     *
     * @param array|callable $handler
     * @param array          $routeParams  URI-captured segments.
     */
    private static function callHandler($handler, array $routeParams, Request $request): mixed
    {
        if (is_array($handler) && count($handler) === 2) {
            [$controllerClass, $method] = $handler;

            $container          = new Container();
            $controllerInstance = $container->make($controllerClass);

            $cacheKey = $controllerClass . '@' . $method;
            if (!isset(self::$reflectionCache[$cacheKey])) {
                self::$reflectionCache[$cacheKey] = new ReflectionMethod($controllerInstance, $method);
            }

            $reflection  = self::$reflectionCache[$cacheKey];
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
                    // Inject URI-captured segment
                    $methodParams[] = array_shift($routeParams);
                }
            }

            return $reflection->invokeArgs($controllerInstance, $methodParams);
        }

        if (is_callable($handler)) {
            return call_user_func_array($handler, $routeParams);
        }

        throw new \RuntimeException("Route handler is neither a callable nor a valid [Controller, method] array.");
    }

    /**
     * Build a 404 response using the custom fallback or the default error page.
     */
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

    /**
     * Build a 500 response using the custom error handler or the default.
     */
    private static function handleServerError(Throwable $e): Response
    {
        if (env('APP_DEBUG') === 'true') {
            throw $e; // re-throw in debug mode so Whoops / Symfony error page renders
        }

        if (self::$errorHandler !== null) {
            $request = new Request();
            $result  = (self::$errorHandler)($e, $request);
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

    /**
     * Throw or log a routing configuration error.
     */
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
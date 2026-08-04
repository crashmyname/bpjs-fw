<?php

namespace Bpjs\Framework\Helpers;

use Bpjs\Framework\Core\Request;
use Bpjs\Framework\Core\Response;
use Bpjs\Framework\Core\Container;
use Bpjs\Framework\Core\FormRequest;
use Bpjs\Framework\Helpers\View;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Api Router
 *
 * Dedicated API router for BPJS Framework.
 * Supports JSON-first responses, versioning, rate limiting,
 * API token auth helpers, and structured error envelopes.
 *
 * @version 2.0.0
 */
class Api
{
    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    /** @var array<string, array<string, array>> Registered routes per HTTP method */
    private static array $routes = [];

    /** @var array<string, string> Named route map */
    private static array $names = [];

    /** @var string Active URL prefix (supports nesting) */
    private static string $prefix = '';

    /** @var array Middlewares inherited from the active group */
    private static array $groupMiddlewares = [];

    /** @var string|null HTTP method of the last registered route */
    private static ?string $lastRouteMethod = null;

    /** @var string|null URI of the last registered route */
    private static ?string $lastRouteUri = null;

    /** @var array<string, ReflectionMethod> Reflection cache */
    private static array $reflectionCache = [];

    /** @var callable|null Custom 404 handler */
    private static $notFoundHandler = null;

    /** @var callable|null Custom 500 handler */
    private static $errorHandler = null;

    /** @var string API version prefix applied globally (e.g. 'v1') */
    private static string $version = '';


    // =========================================================================
    // Initialisation
    // =========================================================================

    /**
     * Bootstrap the API router.
     *
     * @param string $prefix   Global URL prefix (e.g. '/api').
     * @param string $version  Optional API version segment (e.g. 'v1').
     */
    public static function init(string $prefix = '', string $version = ''): void
    {
        self::$routes  = array_fill_keys(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], []);
        self::$prefix  = rtrim($prefix, '/');
        self::$version = trim($version, '/');
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
     * @param string[] $methods
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
     * Register a standard CRUD resource — generates 5 conventional routes.
     *
     * | Verb   | URI                  | Action  | Name           |
     * |--------|----------------------|---------|----------------|
     * | GET    | /{resource}          | index   | {res}.index    |
     * | POST   | /{resource}          | store   | {res}.store    |
     * | GET    | /{resource}/{id}     | show    | {res}.show     |
     * | PUT    | /{resource}/{id}     | update  | {res}.update   |
     * | DELETE | /{resource}/{id}     | destroy | {res}.destroy  |
     *
     * @param string $resource    Resource name, e.g. 'users'.
     * @param string $controller  Controller class string.
     * @param array  $only        Limit to specific actions, e.g. ['index', 'show'].
     * @param array  $except      Exclude specific actions.
     */
    public static function resource(
        string $resource,
        string $controller,
        array  $only   = [],
        array  $except = [],
        array  $middlewares = []
    ): void {
        $base = '/' . trim($resource, '/');
        $name = str_replace('/', '.', trim($resource, '/'));

        $map = [
            'index'   => ['GET',    $base,           'index'],
            'store'   => ['POST',   $base,           'store'],
            'show'    => ['GET',    $base . '/{id}', 'show'],
            'update'  => ['PUT',    $base . '/{id}', 'update'],
            'destroy' => ['DELETE', $base . '/{id}', 'destroy'],
        ];

        foreach ($map as $action => [$verb, $uri, $method]) {
            if ($only   && !in_array($action, $only,   true)) continue;
            if ($except &&  in_array($action, $except, true)) continue;

            self::addRoute($verb, $uri, [$controller, $method], $middlewares)
                ->name("{$name}.{$action}");
        }
    }

    /**
     * Core internal registration — all public methods delegate here.
     */
    private static function addRoute(string $method, string $uri, $handler, array $middlewares): static
    {
        // Build full URI: optional version segment + active prefix + uri
        $segments = array_filter([
            self::$version !== '' ? '/' . self::$version : '',
            self::$prefix  !== '' ? self::$prefix        : '',
            '/' . ltrim($uri, '/'),
        ]);
        $fullUri = implode('', $segments);
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
     * Supports nesting — middlewares are merged, not replaced.
     */
    public static function group(array $middlewares, \Closure $routes): void
    {
        $previous               = self::$groupMiddlewares;
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

    /**
     * Set (or change) the global API version segment.
     * Can also be used to scope a group of routes to a version.
     *
     * @param string   $version  e.g. 'v2'
     * @param \Closure $routes   When provided, version is scoped to the closure.
     */
    public static function version(string $version, ?\Closure $routes = null): void
    {
        if ($routes === null) {
            self::$version = trim($version, '/');
            return;
        }

        $previous      = self::$version;
        self::$version = trim($version, '/');
        $routes();
        self::$version = $previous;
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

        self::handleError(new \Exception("Api::name('{$name}') called without a prior route registration."));
        return new static();
    }

    /**
     * Generate a URL for a named route.
     *
     * @param string               $name
     * @param array<string, mixed> $params  URI parameter substitutions.
     * @param array<string, mixed> $query   Optional query string parameters.
     */
    public static function route(string $name, array $params = [], array $query = []): string
    {
        if (!isset(self::$names[$name])) {
            self::handleError(new \Exception("Named API route '{$name}' not found."));
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
     * Register a custom 404 handler.
     *
     * @param callable $handler  Receives (Request $request): Response|array
     */
    public static function fallback(callable $handler): void
    {
        self::$notFoundHandler = $handler;
    }

    /**
     * Register a custom 500 handler.
     *
     * @param callable $handler  Receives (Throwable $e, Request $request): Response|array
     */
    public static function onError(callable $handler): void
    {
        self::$errorHandler = $handler;
    }


    // =========================================================================
    // Dispatch
    // =========================================================================

    /**
     * Resolve the current HTTP request and return a JSON Response.
     */
    public static function dispatch(): Response
    {
        // API responses are always JSON
        header('Content-Type: application/json; charset=utf-8');

        try {
            // --- CORS preflight ---
            self::handleCors();

            // --- Resolve method ---
            $method = strtoupper($_SERVER['REQUEST_METHOD']);
            if ($method === 'POST' && !empty($_POST['_method'])) {
                $overridden = strtoupper($_POST['_method']);
                if (in_array($overridden, ['PUT', 'PATCH', 'DELETE'], true)) {
                    $method = $overridden;
                }
            }

            // Short-circuit for CORS preflight
            if ($method === 'OPTIONS') {
                return new Response('', 204);
            }

            // --- Resolve URI ---
            $uri = self::normalizeUri();

            // --- Route lookup ---
            $route = self::findRoute($method, $uri);

            if (!$route) {
                return self::handleNotFound(new Request());
            }

            $request = new Request();

            // --- Run middlewares ---
            self::runMiddlewares($route['middlewares'] ?? [], $request);

            // --- API token check (if configured) ---
            if (config('api.require_token', false)) {
                $token = $_SERVER['HTTP_X_API_KEY']
                    ?? $_SERVER['HTTP_AUTHORIZATION']
                    ?? null;

                // Strip 'Bearer ' prefix
                if ($token && str_starts_with($token, 'Bearer ')) {
                    $token = substr($token, 7);
                }

                if (empty($token) || !self::validateApiToken($token)) {
                    return self::jsonResponse(['statusCode' => 401, 'error' => 'Unauthorized'], 401);
                }
            }

            // --- Call handler ---
            $result = self::callHandler($route['handler'], $route['params'] ?? [], $request);

            // Normalize result to Response
            if ($result instanceof Response) {
                return $result;
            }

            // Auto-wrap plain arrays/scalars in a JSON response
            if (is_array($result) || is_object($result)) {
                return self::jsonResponse($result, 200);
            }

            return new Response((string) $result, 200, ['Content-Type' => 'application/json; charset=utf-8']);

        } catch (Throwable $e) {
            return self::handleServerError($e);
        }
    }


    // =========================================================================
    // State Helpers
    // =========================================================================

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

    /**
     * Normalize the incoming request URI (strip base path & query string).
     */
    public static function normalizeUri(): string
    {
        $uri        = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $base       = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($base && $base !== '/' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        return '/' . ltrim($uri ?: '/', '/');
    }


    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Find a matching registered route.
     *
     * @return array|null  Route data with `params`, or null.
     */
    private static function findRoute(string $method, string $uri): ?array
    {
        $routes = self::$routes[$method] ?? [];

        // 1. Exact match (fastest path)
        if (isset($routes[$uri])) {
            $r           = $routes[$uri];
            $r['params'] = [];
            return $r;
        }

        // 2. Dynamic pattern match
        foreach ($routes as $routeUri => $route) {
            $pattern = preg_replace('/\{[a-zA-Z0-9_]+\?\}/', '([a-zA-Z0-9_\-]*)', $routeUri); // optional
            $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/',   '([a-zA-Z0-9_\-]+)', $pattern);  // required
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                $route['params'] = $matches;
                return $route;
            }
        }

        // 3. HEAD falls back to GET
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
                $class    = $middleware['class'];
                $params   = $middleware['params'] ?? [];
                $instance = (new ReflectionClass($class))->newInstanceArgs($params);
                $instance->handle($request);

            } elseif (is_string($middleware)) {
                $instance = new $middleware();
                if (method_exists($instance, 'handle')) {
                    $instance->handle($request);
                }

            } elseif (is_callable($middleware)) {
                $middleware($request);
            }
        }
    }

    /**
     * Invoke a route handler (controller array or callable).
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

            $reflection   = self::$reflectionCache[$cacheKey];
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

        throw new \RuntimeException("API route handler is neither callable nor a valid [Controller, method] array.");
    }

    /**
     * Handle CORS headers — reads from config('api.cors').
     */
    private static function handleCors(): void
    {
        $cors = config('api.cors', []);

        $origin  = $cors['allow_origin']  ?? '*';
        $methods = $cors['allow_methods'] ?? 'GET, POST, PUT, PATCH, DELETE, OPTIONS';
        $headers = $cors['allow_headers'] ?? 'Content-Type, Authorization, X-API-Key, X-Requested-With';
        $maxAge  = $cors['max_age']       ?? '86400';

        header("Access-Control-Allow-Origin: {$origin}");
        header("Access-Control-Allow-Methods: {$methods}");
        header("Access-Control-Allow-Headers: {$headers}");
        header("Access-Control-Max-Age: {$maxAge}");
    }

    /**
     * Validate an API token against config('api.tokens') list or a custom resolver.
     */
    private static function validateApiToken(string $token): bool
    {
        $resolver = config('api.token_resolver');
        if (is_callable($resolver)) {
            return (bool) $resolver($token);
        }

        $tokens = config('api.tokens', []);
        return in_array($token, (array) $tokens, true);
    }

    /**
     * Build a standardised JSON Response.
     *
     * @param array|object $data
     */
    private static function jsonResponse($data, int $status = 200): Response
    {
        return new Response(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * Build a 404 JSON response using the custom fallback or default envelope.
     */
    private static function handleNotFound(Request $request): Response
    {
        if (self::$notFoundHandler !== null) {
            $result = (self::$notFoundHandler)($request);

            if ($result instanceof Response) return $result;
            if (is_array($result))            return self::jsonResponse($result, 404);

            return new Response((string) $result, 404, ['Content-Type' => 'application/json; charset=utf-8']);
        }

        return self::jsonResponse([
            'statusCode' => 404,
            'error'      => 'Endpoint tidak ditemukan.',
        ], 404);
    }

    /**
     * Build a 500 JSON response using the custom error handler or default envelope.
     */
    private static function handleServerError(Throwable $e): Response
    {
        if (env('APP_DEBUG') === 'true') {
            return self::jsonResponse([
                'statusCode' => 500,
                'error'      => $e->getMessage(),
                'file'       => $e->getFile(),
                'line'       => $e->getLine(),
                'trace'      => explode("\n", $e->getTraceAsString()),
            ], 500);
        }

        if (self::$errorHandler !== null) {
            $result = (self::$errorHandler)($e, new Request());

            if ($result instanceof Response) return $result;
            if (is_array($result))           return self::jsonResponse($result, 500);

            return new Response((string) $result, 500, ['Content-Type' => 'application/json; charset=utf-8']);
        }

        error_log('[Api] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        return self::jsonResponse([
            'statusCode' => 500,
            'error'      => 'Internal Server Error',
        ], 500);
    }

    /**
     * Throw or log a routing configuration error.
     */
    private static function handleError(\Exception $e): never
    {
        if (env('APP_DEBUG') === 'true') {
            throw $e;
        }

        error_log('[Api] ' . $e->getMessage());

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['statusCode' => 500, 'error' => 'Internal Server Error']);
        exit;
    }
}
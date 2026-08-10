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
use Closure;

/**
 * Api Router - v2.1.0
 *
 * Dedicated API router for BPJS Framework.
 * Supports JSON-first responses, versioning, rate limiting,
 * API token auth helpers, structured error envelopes,
 * parameter validation, and response macros.
 */
class Api
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
    private static array $reflectionCache = [];
    private static $notFoundHandler = null;
    private static $errorHandler = null;
    private static string $version = '';

    // =========================================================================
    // Initialisation
    // =========================================================================

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

    public static function match(array $methods, string $uri, $handler, array $middlewares = []): static
    {
        foreach ($methods as $method) {
            self::addRoute(strtoupper($method), $uri, $handler, $middlewares);
        }
        return new static();
    }

    public static function any(string $uri, $handler, array $middlewares = []): static
    {
        return self::match(array_keys(self::$routes), $uri, $handler, $middlewares);
    }

    public static function resource(
        string $resource,
        string $controller,
        array  $only = [],
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

    private static function addRoute(string $method, string $uri, $handler, array $middlewares): static
    {
        $fullUri = '/' . ltrim($uri, '/');

        $middlewares = array_merge(self::$groupMiddlewares, $middlewares);

        self::$routes[$method][$fullUri] = [
            'handler'     => $handler,
            'middlewares' => $middlewares,
            'where'       => null,
        ];

        self::$lastRouteMethod = $method;
        self::$lastRouteUri    = $fullUri;

        return new static();
    }

    // =========================================================================
    // NEW: Parameter Validation
    // =========================================================================

    /**
     * Add regex constraints to route parameters.
     *
     * Example:
     *   Api::get('/users/{id}', ...)->where(['id' => '[0-9]+']);
     *   Api::get('/posts/{slug}', ...)->where(['slug' => '[a-z0-9\-]+']);
     */
    public function where(array $conditions): static
    {
        $method = self::$lastRouteMethod;
        $uri    = self::$lastRouteUri;

        if ($method && $uri && isset(self::$routes[$method][$uri])) {
            self::$routes[$method][$uri]['where'] = $conditions;
        }

        return $this;
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

    public static function version(string $version, ?Closure $routes = null): void
    {
        if ($routes === null) {
            self::$version = trim($version, '/');
            return;
        }

        $previous = self::$version;
        self::$version = trim($version, '/');
        $routes();
        self::$version = $previous;
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

        self::handleError(new \Exception("Api::name('{$name}') called without a prior route registration."));
        return new static();
    }

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

        // FIX: Gunakan base_url() biar subfolder aman
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

    public static function fallback(callable $handler): void
    {
        self::$notFoundHandler = $handler;
    }

    public static function onError(callable $handler): void
    {
        self::$errorHandler = $handler;
    }

    // =========================================================================
    // NEW: Response Macros
    // =========================================================================

    /**
     * Standard success response.
     *
     * @param mixed  $data
     * @param string $message
     * @param int    $code
     */
    public static function success($data = null, string $message = 'OK', int $code = 200): Response
    {
        $body = [
            'statusCode' => $code,
            'message'    => $message,
        ];

        if ($data !== null) {
            $body['data'] = $data;
        }

        return self::jsonResponse($body, $code);
    }

    /**
     * Standard error response.
     *
     * @param string     $message
     * @param int        $code
     * @param mixed|null $errors  Additional error details (validation errors, etc.)
     */
    public static function error(string $message, int $code = 400, $errors = null): Response
    {
        $body = [
            'statusCode' => $code,
            'error'      => $message,
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return self::jsonResponse($body, $code);
    }

    /**
     * Paginated success response.
     *
     * @param array $data
     * @param int   $total
     * @param int   $page
     * @param int   $perPage
     */
    public static function paginated(array $data, int $total, int $page = 1, int $perPage = 15): Response
    {
        return self::jsonResponse([
            'statusCode' => 200,
            'message'    => 'OK',
            'data'       => $data,
            'meta'       => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / $perPage),
            ],
        ], 200);
    }

    // =========================================================================
    // Dispatch
    // =========================================================================

    public static function dispatch(): Response
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            self::handleCors();

            $method = strtoupper($_SERVER['REQUEST_METHOD']);
            if ($method === 'POST' && !empty($_POST['_method'])) {
                $overridden = strtoupper($_POST['_method']);
                if (in_array($overridden, ['PUT', 'PATCH', 'DELETE'], true)) {
                    $method = $overridden;
                }
            }

            if ($method === 'OPTIONS') {
                return new Response('', 204);
            }

            $uri = self::normalizeUri();

            // Strip API prefix + version
            $fullPrefix = self::$prefix;
            if (self::$version !== '') {
                $fullPrefix = '/' . self::$version . $fullPrefix;
            }
            if ($fullPrefix !== '' && str_starts_with($uri, $fullPrefix)) {
                $uri = substr($uri, strlen($fullPrefix));
            }
            $uri = '/' . ltrim($uri ?: '/', '/');

            $route = self::findRoute($method, $uri);

            if (!$route) {
                return self::handleNotFound(new Request());
            }

            $request = new Request();

            // Run middlewares
            self::runMiddlewares($route['middlewares'] ?? [], $request);

            // API token check
            if (config('api.require_token', false)) {
                $token = $_SERVER['HTTP_X_API_KEY']
                    ?? $_SERVER['HTTP_AUTHORIZATION']
                    ?? null;

                if ($token && str_starts_with($token, 'Bearer ')) {
                    $token = substr($token, 7);
                }

                if (empty($token) || !self::validateApiToken($token)) {
                    return self::error('Unauthorized', 401);
                }
            }

            // Call handler
            $result = self::callHandler($route['handler'], $route['params'] ?? [], $request);

            if ($result instanceof Response) {
                return $result;
            }

            if (is_array($result) || is_object($result)) {
                return self::jsonResponse($result, 200);
            }

            return new Response((string) $result, 200, ['Content-Type' => 'application/json; charset=utf-8']);

        } catch (Throwable $e) {
            return self::handleServerError($e);
        }
    }

    // =========================================================================
    // NEW: API Documentation Generator
    // =========================================================================

    /**
     * Generate API documentation array.
     * Useful for auto-generating docs or a /api/docs endpoint.
     *
     * @return array
     */
    public static function docs(): array
    {
        $docs = [
            'version' => self::$version ?: '1',
            'base'    => base_url() . (self::$version ? '/' . self::$version : '') . self::$prefix,
            'routes'  => [],
        ];

        foreach (self::$routes as $method => $routes) {
            foreach ($routes as $uri => $route) {
                $routeName = null;
                foreach (self::$names as $name => $namedUri) {
                    if ($namedUri === $uri) {
                        $routeName = $name;
                        break;
                    }
                }

                $docs['routes'][] = [
                    'method'      => $method,
                    'uri'         => $uri,
                    'name'        => $routeName,
                    'where'       => $route['where'] ?? null,
                    'middlewares' => array_map(function ($m) {
                        return is_array($m) ? ($m['class'] ?? 'closure') : (is_string($m) ? $m : 'closure');
                    }, $route['middlewares'] ?? []),
                ];
            }
        }

        return $docs;
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

    private static function findRoute(string $method, string $uri): ?array
    {
        $routes = self::$routes[$method] ?? [];

        // 1. Exact match
        if (isset($routes[$uri])) {
            $r = $routes[$uri];
            $r['params'] = [];
            return $r;
        }

        // 2. Pattern match with where validation
        foreach ($routes as $routeUri => $route) {
            // Extract param names
            preg_match_all('/\{([a-zA-Z0-9_]+)(\?)?\}/', $routeUri, $paramMatches);
            $paramNames = $paramMatches[1] ?? [];

            // Build regex
            $pattern = preg_replace('/\{[a-zA-Z0-9_]+\?\}/', '([a-zA-Z0-9_\-]*)', $routeUri);
            $pattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([a-zA-Z0-9_\-]+)', $pattern);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                $route['params'] = $matches;

                // NEW: Validate where conditions
                if (!empty($route['where'])) {
                    foreach ($route['where'] as $i => $condition) {
                        // Match by index or by param name
                        $value = $matches[$i] ?? null;
                        if ($value === null) {
                            continue;
                        }
                        if (!preg_match('#^' . $condition . '$#', $value)) {
                            continue 2; // Skip this route, where condition failed
                        }
                    }
                }

                return $route;
            }
        }

        // 3. HEAD → GET
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
                $instance = new $middleware();
                if (method_exists($instance, 'handle')) {
                    $instance->handle($request);
                }
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

        throw new \RuntimeException("API route handler is neither callable nor a valid [Controller, method] array.");
    }

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

    private static function validateApiToken(string $token): bool
    {
        $resolver = config('api.token_resolver');
        if (is_callable($resolver)) {
            return (bool) $resolver($token);
        }

        $tokens = config('api.tokens', []);
        return in_array($token, (array) $tokens, true);
    }

    private static function jsonResponse($data, int $status = 200): Response
    {
        return new Response(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    private static function handleNotFound(Request $request): Response
    {
        if (self::$notFoundHandler !== null) {
            $result = (self::$notFoundHandler)($request);
            if ($result instanceof Response) return $result;
            if (is_array($result)) return self::jsonResponse($result, 404);
            return new Response((string) $result, 404, ['Content-Type' => 'application/json; charset=utf-8']);
        }

        return self::error('Endpoint tidak ditemukan.', 404);
    }

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
            if (is_array($result)) return self::jsonResponse($result, 500);
            return new Response((string) $result, 500, ['Content-Type' => 'application/json; charset=utf-8']);
        }

        error_log('[Api] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

        return self::error('Internal Server Error', 500);
    }

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
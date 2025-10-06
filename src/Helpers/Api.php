<?php
namespace Bpjs\Framework\Helpers;

use Throwable;
use Bpjs\Core\Request;
use Bpjs\Framework\View;
use Bpjs\Framework\ErrorHandler;

class Api
{
    private static $routes = [];
    private static $names = [];
    private static $prefix;
    private static $groupMiddlewares = [];
    private static $lastRouteMethod = null;
    private static $lastRouteUri = null;

    public static function init($prefix = '')
    {
        self::$routes['GET'] = [];
        self::$routes['POST'] = [];
        self::$routes['PUT'] = [];
        self::$routes['DELETE'] = [];
        self::$prefix = rtrim($prefix, '/');
    }

    public static function get($uri, $handler, $middlewares = [])
    {
        $middlewares = array_merge(self::$groupMiddlewares, $middlewares);
        self::$routes['GET'][$uri] = [
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
        return new self();
    }

    public static function post($uri, $handler, $middlewares = [])
    {
        $middlewares = array_merge(self::$groupMiddlewares, $middlewares);
        self::$routes['POST'][$uri] = [
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
        return new self();
    }
    public static function put($uri, $handler, $middlewares = [])
    {
        $middlewares = array_merge(self::$groupMiddlewares, $middlewares);
        self::$routes['PUT'][$uri] = [
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
        return new self();
    }

    public static function delete($uri, $handler, $middlewares = [])
    {
        $middlewares = array_merge(self::$groupMiddlewares, $middlewares);
        self::$routes['DELETE'][$uri] = [
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
        return new self();
    }

    public static function group(array $middlewares, \Closure $routes)
    {
        self::$groupMiddlewares = $middlewares;

        call_user_func($routes);

        self::$groupMiddlewares = [];
    }
    public static function name($name)
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            if (!empty(self::$routes[$method])) {
                $lastRoute = array_key_last(self::$routes[$method]);
                self::$names[$name] = $lastRoute;
                return new self();
            }
        }

        throw new \Exception("Tidak dapat memberi nama route '{$name}', route tidak ditemukan.");
    }

    public static function route($name, $params = [])
    {
        if (!isset(self::$names[$name])) {
            throw new \Exception("Route dengan nama '{$name}' tidak ditemukan.");
        }

        $uri = self::$names[$name];
        foreach ($params as $key => $value) {
            $uri = str_replace('{' . $key . '}', $value, $uri);
        }

        return self::$prefix . '/' . trim($uri, '/');
    }

    public static function dispatch(): \Bpjs\Core\Response
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            if ($method === 'POST' && isset($_POST['_method'])) {
                $method = strtoupper($_POST['_method']);
            }

            $uri = strtok($_SERVER['REQUEST_URI'], '?');
            if (self::$prefix && str_starts_with($uri, self::$prefix)) {
                $uri = substr($uri, strlen(self::$prefix));
            }
            $uri = '/' . ltrim($uri, '/');

            $route = self::findRoute($method, $uri);

            if (!$route) {
                // Tidak ada route ditemukan → gunakan error handler 404
                http_response_code(404);
                return new \Bpjs\Core\Response(View::error(404), 404);
            }

            $handler = $route['handler'];
            $middlewares = $route['middlewares'];
            $params = $route['params'] ?? [];
            $request = new Request();

            // Jalankan middleware
            foreach ($middlewares as $middleware) {
                if (is_string($middleware)) {
                    $instance = new $middleware();
                    if (method_exists($instance, 'handle')) {
                        $instance->handle($request);
                    }
                } elseif (is_callable($middleware)) {
                    $middleware($request);
                }
            }

            // Validasi CSRF Token jika POST
            if ($method === 'POST') {
                $csrfToken = $request->get('csrf_token') ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
                if (empty($csrfToken) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
                    throw new \Exception('Invalid CSRF Token', 419);
                }
            }

            // Jalankan handler (controller atau closure)
            if (is_array($handler) && count($handler) === 2) {
                [$controller, $action] = $handler;
                $instance = new $controller();
                $reflection = new \ReflectionMethod($instance, $action);
                $paramsDef = $reflection->getParameters();

                if (isset($paramsDef[0]) && $paramsDef[0]->getType()?->getName() === Request::class) {
                    array_unshift($params, $request);
                }

                $result = call_user_func_array([$instance, $action], $params);
            } else {
                $result = call_user_func_array($handler, $params);
            }

            return $result instanceof Response ? $result : new \Bpjs\Core\Response($result);

        } catch (Throwable $e) {
            // Serahkan ke ErrorHandler global
            ErrorHandler::handleException($e);
            exit;
        }
    }

    private static function findRoute($method, $uri)
    {
        foreach (self::$routes[$method] as $routeUri => $route) {
            $routePattern = preg_replace('/\{[a-zA-Z0-9_]+\}/', '([a-zA-Z0-9_\-]+)', $routeUri);
            if (preg_match('#^' . $routePattern . '$#', $uri, $matches)) {
                array_shift($matches);
                $route['params'] = $matches;
                return $route;
            }
        }
        return null;
    }

    private static function routeExists($uri)
    {
        return isset(self::$routes['GET'][$uri]) || isset(self::$routes['POST'][$uri]);
    }
}

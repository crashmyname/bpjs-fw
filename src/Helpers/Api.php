<?php
namespace Bpjs\Framework\Helpers;

use Bpjs\Framework\Core\Request;
use Bpjs\Framework\Helpers\View;

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
        self::$lastRouteMethod = 'GET';
        self::$lastRouteUri = $uri;
        return new self();
    }

    public static function post($uri, $handler, $middlewares = [])
    {
        $middlewares = array_merge(self::$groupMiddlewares, $middlewares);
        self::$routes['POST'][$uri] = [
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
        self::$lastRouteMethod = 'POST';
        self::$lastRouteUri = $uri;
        return new self();
    }
    public static function put($uri, $handler, $middlewares = [])
    {
        $middlewares = array_merge(self::$groupMiddlewares, $middlewares);
        self::$routes['PUT'][$uri] = [
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
        self::$lastRouteMethod = 'PUT';
        self::$lastRouteUri = $uri;
        return new self();
    }

    public static function delete($uri, $handler, $middlewares = [])
    {
        $middlewares = array_merge(self::$groupMiddlewares, $middlewares);
        self::$routes['DELETE'][$uri] = [
            'handler' => $handler,
            'middlewares' => $middlewares,
        ];
        self::$lastRouteMethod = 'DELETE';
        self::$lastRouteUri = $uri;
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

                error_log("Route name '{$name}' mapped to URI '{$lastRoute}'");

                return new self();
            }
        }
        if (env('APP_DEBUG') == 'false') {
            if (Request::isAjax() || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
                header('Content-Type: application/json', true, 500);
                echo json_encode([
                    'statusCode' => 500,
                    'error'      => 'Internal Server Error'
                ]);
            } else {
                return View::error(500);
            }
            exit;
        }
        throw new \Exception("No routes found for naming '{$name}'");
    }
    public static function route($name, $params = [])
    {
        if (isset(self::$names[$name])) {
            $uri = self::$names[$name];

            foreach ($params as $key => $value) {
                $uri = str_replace('{' . $key . '}', $value, $uri);
            }

            if (php_sapi_name() === 'cli-server' || PHP_SAPI === 'cli') {
                return '/' . trim($uri, '/');
            }

            return self::$prefix . '/' . trim($uri, '/');
        }
        if (env('APP_DEBUG') == 'false') {
            if (Request::isAjax() || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
                header('Content-Type: application/json', true, 500);
                echo json_encode([
                    'statusCode' => 500,
                    'error'      => 'Internal Server Error'
                ]);
            } else {
                return View::error(500);
            }
            exit;
        }
        self::renderErrorPage("Route dengan nama '{$name}' tidak ditemukan.");
    }

    // Dispatch routing
    public static function dispatch(): \Bpjs\Framework\Core\Response
    {
        try {

            $method = $_SERVER['REQUEST_METHOD'];
            if ($method === 'POST' && isset($_POST['_method'])) {
                $method = strtoupper($_POST['_method']);
            }

            $uri = self::normalizeUri();

            $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

            if ($base && $base !== '/' && str_starts_with($uri, $base)) {
                $uri = substr($uri, strlen($base));
            }

            if (self::$prefix && str_starts_with($uri, self::$prefix)) {
                $uri = substr($uri, strlen(self::$prefix));
            }

            $uri = '/' . ltrim($uri, '/');
            if ($uri === '') $uri = '/';

            $route = self::findRoute($method, $uri);

            if ($route) {
                $handler = $route['handler'];
                $middlewares = $route['middlewares'];
                $params = $route['params'] ?? [];

                $request = new \Bpjs\Framework\Core\Request();

                // Middleware
                foreach ($middlewares as $middleware) {
                    if (is_string($middleware)) {
                        $middlewareInstance = new $middleware();
                        if (method_exists($middlewareInstance, 'handle')) {
                            $middlewareInstance->handle($request);
                        }
                    } elseif (is_callable($middleware)) {
                        $middleware($request);
                    }
                }

                // Jalankan controller atau closure
                if (is_array($handler) && count($handler) === 2) {
                    [$controller, $method] = $handler;

                        $container = new \Bpjs\Framework\Core\Container();
                        $controllerInstance = $container->make($controller);

                        $reflection = new \ReflectionMethod($controllerInstance, $method);

                        $methodParams = [];

                        foreach ($reflection->getParameters() as $param) {

                            $type = $param->getType();

                            if ($type) {

                                $className = $type->getName();

                                if ($className === \Bpjs\Framework\Core\Request::class) {

                                    $methodParams[] = $request;

                                } else {

                                    $methodParams[] = $container->make($className);
                                }

                            } else {

                                $methodParams[] = array_shift($params);
                            }
                        }

                        $result = $reflection->invokeArgs($controllerInstance, $methodParams);
                } else {
                    $result = call_user_func_array($handler, $params);
                }

                return $result instanceof \Bpjs\Framework\Core\Response
                    ? $result
                    : new \Bpjs\Framework\Core\Response($result);
            }

            ob_start();
            include BPJS_BASE_PATH . '/app/handle/errors/404.php';
            $content = ob_get_clean();
            return new \Bpjs\Framework\Core\Response($content, 404);

        } catch (\Throwable $e) {
            ob_start();
            include BPJS_BASE_PATH . '/app/handle/errors/500.php';
            $content = ob_get_clean();
            return new \Bpjs\Framework\Core\Response($content, 500);
        }
    }

    public static function normalizeUri(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($base && $base !== '/' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        return '/' . ltrim($uri, '/');
    }

    public static function export()
    {
        return self::$routes;
    }

    public static function setRoutes($routes)
    {
        self::$routes = $routes;
    }

    public static function exportNames()
    {
        return self::$names;
    }

    public static function setNames($names)
    {
        self::$names = $names;
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
    private static function renderErrorPage($message)
    {
        ob_clean();
        header('Content-Type: text/html; charset=utf-8');
        $url = base_url();
        echo "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Error</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    background-color: #f4f4f9;
                    color: #333;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    height: 100vh;
                    margin: 0;
                }
                .error-container {
                    background-color: #fff;
                    border: 1px solid #ddd;
                    border-radius: 8px;
                    padding: 20px;
                    max-width: 600px;
                    width: 100%;
                    text-align: center;
                }
                h1 {
                    color: #e74c3c;
                    font-size: 2em;
                }
                p {
                    font-size: 1.2em;
                    margin: 15px 0;
                }
                a {
                    color: #3498db;
                    text-decoration: none;
                }
                a:hover {
                    text-decoration: underline;
                }
            </style>
        </head>
        <body>
            <div class='error-container'>
                <h1>Error: {$message}</h1>
                <p>Something went wrong while processing the request.</p>
                <p><a href='{$url}'>Return to Home</a></p>
            </div>
        </body>
        </html>
    ";
        exit();
    }
}

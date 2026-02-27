<?php

namespace Bpjs\Framework\Helpers;
use Bpjs\Core\Request;
use Bpjs\Framework\Helpers\View;
use Bpjs\Framework\Helpers\QueryLogger;
use Bpjs\Framework\Helpers\Route;
use ReflectionClass;

class BaseController {
    
    public function prettyPrint($data)
    {
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        $isJson = isset($_SERVER['HTTP_ACCEPT']) 
            && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json');

        $traceFull = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT);

        $routeInfo = Route::current();

        $routeName = null;
        $routeController = null;
        $routeFile = null;
        $routeLine = null;
        $routeMiddlewares = [];
        $controllerInfo = null;
        $serviceList = [];
        $modelList = [];
        $dbQueries = QueryLogger::all();

        foreach ($traceFull as $trace) {

            if (!isset($trace['class'])) continue;

            $class = $trace['class'];

            if (
                $controllerInfo === null &&
                str_contains($class, 'Controller') &&
                !str_contains($class, 'BaseController')
            ) {
                $controllerInfo = $class.'@'.($trace['function'] ?? 'unknown');
            }

            if (
                str_contains($class, 'Service') ||
                str_contains($class, '\\Services\\')
            ) {
                $serviceList[] = $class;
            }

            try {

                if (
                    class_exists($class) &&
                    (
                        str_contains($class, 'Model') ||
                        is_subclass_of($class, \Bpjs\Framework\Helpers\BaseModel::class)
                    )
                ) {
                    $modelList[] = $class;
                }

            } catch (\Throwable $e) {}
        }

        $serviceList = array_values(array_unique($serviceList));
        $modelList   = array_values(array_unique($modelList));
        $formattedQueries = [];

            foreach ($dbQueries as $query) {

                if (!is_array($query)) continue;

                $sql = $query['sql'] ?? '';

                $params = !empty($query['params'])
                    ? json_encode($query['params'])
                    : '';

                $time = $query['time_ms'] ?? null;

                $model = $query['model'] ?? null;

                $formattedQueries[] =
                    $sql .
                    ($params ? " | params: {$params}" : '') .
                    ($time ? " | {$time} ms" : '') .
                    ($model ? " | model: {$model}" : '');
            }

            $groupedQueries = [];

            foreach ($dbQueries as $query) {

                if (!is_array($query)) continue;

                $key = ($query['model'] ?? 'raw').'|'.($query['sql'] ?? '');

                if (!isset($groupedQueries[$key])) {
                    $groupedQueries[$key] = [
                        'sql' => $query['sql'] ?? '',
                        'model' => $query['model'] ?? null,
                        'count' => 0,
                        'total_time' => 0,
                        'items' => []
                    ];
                }

                $groupedQueries[$key]['count']++;
                $groupedQueries[$key]['total_time'] += $query['time_ms'] ?? 0;
                $groupedQueries[$key]['items'][] = $query;
            }

            $dbQueries = array_values($groupedQueries);


        /* =====================
        JSON MODE
        ===================== */
        if ($isAjax || $isJson) {

            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[1] ?? [];

            $response = [
                'success' => true,
                'type' => gettype($data),
                'data' => $data,
                'debug' => [
                    'controller' => $controllerInfo,
                    'services' => $serviceList,
                    'models' => $modelList,
                    'queries' => $dbQueries,
                    'file' => $caller['file'] ?? null,
                    'line' => $caller['line'] ?? null,
                    'function' => $caller['function'] ?? null,
                    'timestamp' => date('Y-m-d H:i:s'),
                    'memory_usage' => memory_get_usage(true),
                    'execution_time_ms' => defined('BPJS_START')
                        ? round((microtime(true) - BPJS_START) * 1000, 2)
                        : null,
                    'request' => [
                        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                        'uri' => $_SERVER['REQUEST_URI'] ?? null,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? null
                    ]
                ]
            ];

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        /* =====================
        HTML MODE
        ===================== */

        $executionTime = defined('BPJS_START')
            ? round((microtime(true) - BPJS_START) * 1000, 2)
            : null;

        if (!empty($routeInfo)) {

            if (is_array($routeInfo['handler'])) {

                $controller = $routeInfo['handler'][0];
                $method = $routeInfo['handler'][1];

                $routeController = $controller.'@'.$method;

                try {
                    $reflection = new \ReflectionMethod($controller, $method);
                    $routeFile = $reflection->getFileName();
                    $routeLine = $reflection->getStartLine();
                } catch (\Throwable $e) {}
            }

            $routeMiddlewares = array_map(function ($m) {
                return is_string($m) ? $m : 'Closure';
            }, $routeInfo['middlewares']);
        }
        $debugInfo = [
            'URL' => ($_SERVER['REQUEST_SCHEME'] ?? 'http').'://'.($_SERVER['HTTP_HOST'] ?? '').($_SERVER['REQUEST_URI'] ?? ''),
            'Method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'Execution Time' => $executionTime . ' ms',
            'Memory Usage' => round(memory_get_usage(true)/1024/1024,2).' MB',
            'Peak Memory' => round(memory_get_peak_usage(true)/1024/1024,2).' MB',
            'User Agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'Query String' => $_SERVER['QUERY_STRING'] ?? null,
            'Controller' => $controllerInfo,
            'Route Method' => $routeInfo['method'] ?? null,
            'Route URI' => $routeInfo['uri'] ?? null,
            'Route Handler' => $routeController,
            'Route File' => $routeFile ? basename($routeFile).':'.$routeLine : null,
            'Route Middlewares' => implode(', ', $routeMiddlewares),
        ];

        $headers = function_exists('headers_list') ? headers_list() : [];

        /* =====================
        STYLE
        ===================== */
        echo '
        <style>
        .pretty-wrapper{
            background:#1e1e1e;
            border-radius:10px;
            padding:15px;
            font-family: monospace;
            color:#e6e6e6;
            box-shadow:0 4px 15px rgba(0,0,0,0.4);
        }

        .pretty-debug-panel{
            background:#252526;
            border-radius:8px;
            padding:10px;
            margin-bottom:15px;
            font-size:13px;
        }

        .pretty-debug-row{
            display:flex;
            justify-content:space-between;
            border-bottom:1px solid #333;
            padding:4px 0;
        }

        .pretty-debug-key{ color:#4fc1ff; }
        .pretty-debug-val{ color:#dcdcaa; text-align:right; }

        .pretty-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:10px;
            border-bottom:1px solid #333;
            padding-bottom:8px;
        }

        .pretty-title{ font-weight:bold; font-size:16px; }

        .pretty-toolbar button{
            background:#333;
            border:none;
            color:#fff;
            padding:5px 10px;
            margin-left:5px;
            border-radius:5px;
            cursor:pointer;
            font-size:12px;
        }

        .pretty-print{ font-size:14px; line-height:1.6; }

        .key{color:#66d9ef;}
        .string{color:#a6e22e;}
        .number{color:#fd971f;}
        .bool{color:#f92672;}
        .null{color:#75715e;}

        .node{
            margin-left:18px;
            border-left:1px dashed #333;
            padding-left:8px;
            display:none;
        }

        .collapsible{ cursor:pointer; color:#ccc; }

        .badge{
            background:#444;
            padding:2px 6px;
            font-size:11px;
            border-radius:4px;
            margin-left:5px;
        }
        </style>
        ';

        echo '
        <script>
        function toggleAll(expand){
            document.querySelectorAll(".node").forEach(el=>{
                el.style.display = expand ? "block" : "none";
            });
        }

        function toggleNode(el){
            let node = el.nextElementSibling;
            if(node) node.style.display = node.style.display === "block" ? "none" : "block";
        }

        function copyJson(){
            navigator.clipboard.writeText(document.getElementById("json-source").textContent);
            alert("Copied JSON!");
        }
        </script>
        ';

        /* =====================
        FORMATTER
        ===================== */
        function format_data($data){
            $result = "";

            if(!is_array($data) && !is_object($data)){
                return '<div><span class="string">"'.htmlspecialchars((string)$data).'"</span></div>';
            }
            if(is_array($data) || is_object($data)){
                foreach($data as $key => $value){

                    $type = gettype($value);

                    $result .= '<div>';
                    $result .= '<span class="collapsible" onclick="toggleNode(this)">';
                    $result .= '<span class="key">['.htmlspecialchars($key).']</span>';
                    $result .= '<span class="badge">'.$type.'</span>';
                    $result .= '</span>';

                    if(is_array($value) || is_object($value)){
                        $result .= '<div class="node">';
                        $result .= format_data((array)$value);
                        $result .= '</div>';
                    }
                    elseif(is_string($value)){
                        $result .= ' => <span class="string">"'.htmlspecialchars($value).'"</span>';
                    }
                    elseif(is_numeric($value)){
                        $result .= ' => <span class="number">'.$value.'</span>';
                    }
                    elseif(is_bool($value)){
                        $result .= ' => <span class="bool">'.($value?'true':'false').'</span>';
                    }
                    else{
                        $result .= ' => <span class="null">null</span>';
                    }

                    $result .= '</div>';
                }
            }

            return $result;
        }

        if (is_object($data)) {
            $reflection = new ReflectionClass($data);
            $properties = $reflection->getProperties();
            $formatted_data = [];

            foreach ($properties as $property) {
                $property->setAccessible(true);
                $formatted_data[$property->getName()] = $property->getValue($data);
            }
        } else {
            $formatted_data = $data;
        }

        $jsonSource = htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT));

        echo '<div class="pretty-wrapper">';

        echo '<div class="pretty-header">';
        echo '<div class="pretty-title">Debug Viewer</div>';

        echo '<div class="pretty-toolbar">
                <button onclick="toggleAll(true)">Expand All</button>
                <button onclick="toggleAll(false)">Collapse All</button>
                <button onclick="copyJson()">Copy JSON</button>
            </div>';

        echo '</div>';

        echo '<pre id="json-source" style="display:none">'.$jsonSource.'</pre>';

        echo '<div class="pretty-print">';
        echo format_data($formatted_data);
        echo '</div><br>';

        echo '<div class="pretty-debug-panel">';
        echo '<b style="color:#9cdcfe;">HTTP Debug Info</b>';

        foreach($debugInfo as $k => $v){
            echo '<div class="pretty-debug-row">';
            echo '<span class="pretty-debug-key">'.$k.'</span>';
            echo '<span class="pretty-debug-val">'.htmlspecialchars((string)$v).'</span>';
            echo '</div>';
        }

        if(!empty($serviceList)){
            echo '<div class="pretty-debug-row">
                    <span>Services</span>
                    <span>'.implode(', ', $serviceList).'</span>
                </div>';
        }

        if(!empty($modelList)){
            echo '<div class="pretty-debug-row">
                    <span>Models</span>
                    <span>'.implode(', ', $modelList).'</span>
                </div>';
        }

        if(!empty($dbQueries)){
            echo '<div style="margin-top:8px;color:#c586c0;">Queries</div>';

            foreach($dbQueries as $q){

                echo '<div class="collapsible" onclick="toggleNode(this)">';

                echo '<b>SQL:</b> '.htmlspecialchars($q['sql']);
                echo ' <span class="badge">'.$q['count'].'x</span>';
                echo ' <span class="badge">'.round($q['total_time'],2).' ms</span>';

                if(!empty($q['model'])){
                    echo ' <span class="badge">'.$q['model'].'</span>';
                }

                echo '</div>';

                echo '<div class="node">';

                foreach($q['items'] as $item){

                    echo '<div class="pretty-debug-row">';
                    echo '<span>Params</span>';
                    echo '<span>'.htmlspecialchars(json_encode($item['params'] ?? [])).'</span>';
                    echo '</div>';

                    echo '<div class="pretty-debug-row">';
                    echo '<span>Time</span>';
                    echo '<span>'.($item['time_ms'] ?? 0).' ms</span>';
                    echo '</div>';

                    echo '<hr style="border-color:#333">';
                }

                echo '</div>';
            }
        }

        echo '</div>';
        echo '</div>';

        exit;
    }

    public function redirect($url)
    {
        $uri = base_url() . $url;
        header("Location: $uri");
        exit();
    }

    public function sanitize($data)
    {
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }

    public function jsonResponse($data, $statusCode)
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit();
    }

    public function uploadFile($file, $destination)
    {
        $targetFile = $destination . basename($file["name"]);
        if (move_uploaded_file($file["tmp_name"], $targetFile)) {
            return $targetFile;
        }
        return false;
    }

    public function strLimit($string, $limit, $end)
    {
        return strlen($string) > $limit ? substr($string, 0, $limit) . $end : $string;
    }

    public function toSlug($string)
    {
        $string = strtolower(trim($string));
        $string = preg_replace('/[^a-z0-9-]/', '-', $string);
        $string = preg_replace('/-+/', '-', $string);
        return rtrim($string, '-');
    }

    public function arrayFlatten($flattened)
    {
        $flattened = [];
        array_walk_recursive($array, function($value) use (&$flattened) {
            $flattened[] = $value;
        });
        return $flattened;
    }

    function base_url(string $path = ''): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            ? 'https://'
            : 'http://';
    
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    
        $dir = str_replace('\\', '/', dirname($scriptName));
        if ($dir === '/' || $dir === '\\') {
            $dir = '';
        }
    
        $url = $protocol . $host . $dir;
    
        if (!str_ends_with($url, '/')) {
            $url .= '/';
        }
    
        if ($path !== '') {
            $url .= ltrim($path, '/');
        }
    
        return $url;
    }

    public function arrayGet($array, $key, $default)
    {
        return isset($array[$key]) ? $array[$key] : $default;
    }

    public function generateRandomString($length)
    {
        return substr(bin2hex(random_bytes($length)), 0, $length);
    }

    public function toJson($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }
    
    public function fromJson($json, $assoc)
    {
        return json_decode($json, $assoc);
    }

    public function paginate($totalItems, $perPage, $page, $url)
    {
        $totalPages = ceil($totalItems / $perPage);
        $output = '<nav><ul class="pagination">';
    
        for ($i = 1; $i <= $totalPages; $i++) {
            $output .= '<li class="page-item' . ($page == $i ? ' active' : '') . '">';
            $output .= '<a class="page-link" href="' . $url . 'page=' . $i . '">' . $i . '</a>';
            $output .= '</li>';
        }
    
        $output .= '</ul></nav>';
        return $output;
    }

    public function pathJoin(...$paths)
    {
        return preg_replace('#/+#', '/', join('/', $paths));
    }

    public function rateLimit($key, $maxAttempts = 5, $seconds = 60)
    {
        $currentAttempts = $_SESSION[$key] ?? 0;
    
        if ($currentAttempts >= $maxAttempts) {
            return false; // Terlalu banyak percobaan
        }
    
        $_SESSION[$key] = $currentAttempts + 1;
        return true;
    }

    public function generateSlug($text)
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $text), '-'));
    }

    public function sortByKey($array, $key)
    {
        usort($array, function($a, $b) use ($key) {
            return $a[$key] <=> $b[$key];
        });
        return $array;
    }

    public function htmlEscape($string)
    {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }

    public function buildUrl($base,$params = [])
    {
        return $base . '?' . http_build_query($params);
    }

    public function currentUrl()
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    }

    public function formatNumber($number, $decimals = 2)
    {
        return number_format($number, $decimals, '.', ',');
    }

    public function csrfToken()
    {
        if(empty($_SESSION['csrf_token'])){
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $token = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
        $csrf = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
        return $csrf;
    }

    public function csrfMeta()
    {
        if(empty($_SESSION['csrf_token'])){
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $token = $_SESSION['csrf_token'];
        return "<meta name='csrf-token' content='{$token}'>";
    }

    public function verifyCsrfToken($token)
    {
        // return $token === $_SESSION['csrf_token'];
        // return hash_equals($_SESSION['csrf_token'] ?? '', $token);
        $isValid = hash_equals($_SESSION['csrf_token'] ?? '', $token);
        if ($isValid) {
            // Regenerasi token baru setelah validasi berhasil
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $isValid;
    }

    public function Method($method)
    {
        return "<input type='hidden' name='_method' value='{$method}'>";
    }

    public function isValidEmail($email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function isValidUrl($url)
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function setFlashMessage($key, $message)
    {
        $_SESSION[$key] = $message;
    }

    public function getFlashMessage($key)
    {
        if (isset($_SESSION[$key])) {
            $message = $_SESSION[$key];
            unset($_SESSION[$key]);
            return $message;
        }
        return null;
    }

    public function encrypt($data, $key)
    {
        return openssl_encrypt($data, 'AES-128-ECB', $key);
    }

    public function decrypt($data, $key)
    {
        return openssl_decrypt($data, 'AES-128-ECB', $key);
    }

    public function arrayPluck($array, $key)
    {
        return array_map(function($item) use ($key) {
            return is_array($item) && isset($item[$key]) ? $item[$key] : null;
        }, $array);
    }

    public function formatCurrency($amount, $currency)
    {
        return $currency . ' ' . number_format($amount, 2);
    }

    public function hashPassword($password)
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public function verifyPassword($password,$hash)
    {
        return password_verify($password, $hash);
    }

    public function logMessage($message, $level)
    {
        $logfile = 'app.log';
        $time = date('Y-m-d H:i:s');
        file_put_contents($logfile, "[$time] [$level] $message" . PHP_EOL, FILE_APPEND);
    }

    public function arrayFilterByKey($array,$key,$value)
    {
        return array_filter($array, function($item) use ($key, $value) {
            return isset($item[$key]) && $item[$key] === $value;
        });
    }

    public function toTitleCase($string)
    {
        return ucwords(strtolower($string));
    }

    public function toSentenceCase($string)
    {
        return ucfirst(strtolower($string));
    }

    public function toUpperCase($string)
    {
        return strtoupper($string);
    }

    public function toLowerCase($string)
    {
        return strtolower($string);
    }

    public function setSecurityHeaders()
    {
        header("Content-Security-Policy-Report-Only: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com;");
        header("X-Content-Type-Options: nosniff");
        header("X-Frame-Options: SAMEORIGIN");
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    }

    public function back()
    {
        if (isset($_SERVER['HTTP_REFERER'])) {
            header("Location: " . $_SERVER['HTTP_REFERER'] . base_url());
            exit();
        } else {
            $url = base_url();
            header("Location: {$url}");
            exit();
        }
    }

    public function view($view, $data = [], $layout = null)
    {
        // setSecurityHeaders();
        try{
            extract($data);
            $viewPath = BPJS_BASE_PATH . '/resources/views/' . $view . '.php';
            if (!file_exists($viewPath)) {
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
                throw new \Exception("View file not found: $viewPath");
            }
            ob_start();
            include $viewPath;
            $content = ob_get_clean();

            if ($layout) {
                $layoutPath = BPJS_BASE_PATH . '/resources/views/' . $layout . '.php';
                if (file_exists($layoutPath)) {
                    ob_start();
                    include $layoutPath;
                    $layoutContent = ob_get_clean();
                    echo $layoutContent;
                } else {
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
                    throw new \Exception("Layout file not found: $layoutPath");
                }
            } else {
                echo $content;
            }
        } catch (\Exception $e){
            if (!headers_sent()) { 
                http_response_code(500);
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
            // View::renderError($e);
            ErrorHandler::handleException($e);
        }
        exit();
    }
}
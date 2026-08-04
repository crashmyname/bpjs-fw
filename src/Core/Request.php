<?php

namespace Bpjs\Framework\Core;

use Bpjs\Framework\Helpers\Date;
use Bpjs\Framework\Helpers\Validator;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Request Helper - Production Ready
 * 
 * Fitur lengkap untuk handling HTTP request dengan:
 * - Sanitization & XSS Protection
 * - CSRF Protection
 * - Rate Limiting
 * - Input Validation
 * - File Upload Security
 * - Cache Control
 * - IP & User Agent Tracking
 * - Session Management
 * - CORS Support
 * - PSR-7 Compatibility
 * 
 * @package Bpjs\Framework\Core
 */
class Request
{
    /* =========================================================
     * PROPERTIES
     * ========================================================= */
    
    private array $data = [];
    private array $files = [];
    private array $cookies = [];
    private array $server = [];
    private array $headers = [];
    private array $queryParams = [];
    private array $validatedData = [];
    private array $validationErrors = [];
    private ?int $rateLimit = null;
    private ?string $rateLimitKey = null;
    private ?int $rateLimitWindow = null;
    private string $method;
    private string $uri;
    private string $fullUrl;
    private string $clientIp;
    private string $userAgent;
    private bool $isSecure = false;
    private ?string $csrfToken = null;
    private array $trustedProxies = [];
    private static array $config = [
        'csrf_enabled' => true,
        'csrf_token_name' => '_token',
        'csrf_header_name' => 'X-CSRF-TOKEN',
        'csrf_expire' => 7200, // 2 hours
        'rate_limit_enabled' => true,
        'rate_limit_max' => 60,
        'rate_limit_window' => 60, // 1 minute
        'trusted_proxies' => [],
        'allowed_origins' => ['*'],
        'max_file_size' => 10485760, // 10MB
        'allowed_file_types' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp', // Images
            'pdf', 'doc', 'docx', 'xls', 'xlsx', // Documents
            'zip', 'rar', 'tar', 'gz', // Archives
            'mp4', 'avi', 'mov', 'mkv', // Videos
            'csv', 'txt', 'json', 'xml', // Data
        ],
        'sanitize_enabled' => true,
        'encrypt_cookies' => true,
        'cookie_encryption_key' => null,
    ];

    /* =========================================================
     * CONSTRUCTOR
     * ========================================================= */
    
    public function __construct()
    {
        // Initialize server variables
        $this->server = $_SERVER;
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri = $this->parseUri();
        $this->fullUrl = $this->buildFullUrl();
        $this->clientIp = $this->resolveClientIp();
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $this->isSecure = $this->detectSecure();
        $this->headers = $this->parseHeaders();
        $this->cookies = $this->parseCookies();
        
        // Parse query parameters
        $this->queryParams = $this->sanitizeData($_GET);
        
        // Parse request body based on content type
        $this->parseBody();
        
        // Normalize files
        $this->files = $this->normalizeFiles($_FILES);
        
        // Sanitize all data if enabled
        if (self::$config['sanitize_enabled']) {
            $this->data = $this->sanitizeData($this->data);
            $this->queryParams = $this->sanitizeData($this->queryParams);
        }
        
        // Sanitize keys
        $this->data = $this->sanitizeKeys($this->data);
        $this->files = $this->sanitizeKeys($this->files);
        $this->queryParams = $this->sanitizeKeys($this->queryParams);
    }

    /* =========================================================
     * STATIC FACTORY METHODS
     * ========================================================= */

    /**
     * Capture current request
     */
    public static function capture(): static
    {
        return new static();
    }

    /**
     * Create request from PSR-7 ServerRequestInterface
     */
    public static function fromPsr(ServerRequestInterface $psr): static
    {
        $instance = new static();
        
        $instance->method = $psr->getMethod();
        $instance->uri = $psr->getUri()->getPath();
        $instance->headers = $psr->getHeaders();
        $instance->queryParams = $psr->getQueryParams();
        $instance->data = (array) $psr->getParsedBody();
        $instance->files = (array) $psr->getUploadedFiles();
        $instance->cookies = $psr->getCookieParams();
        $instance->server = $psr->getServerParams();
        $instance->clientIp = $instance->resolveClientIpFromServer($psr->getServerParams());
        $instance->fullUrl = (string) $psr->getUri();
        
        return $instance;
    }

    /**
     * Create request from global variables (test helper)
     */
    public static function create(
        string $method = 'GET',
        string $uri = '/',
        array $data = [],
        array $files = [],
        array $server = [],
        array $headers = []
    ): static {
        $instance = new static();
        $instance->method = strtoupper($method);
        $instance->uri = $uri;
        $instance->data = $data;
        $instance->files = $instance->normalizeFiles($files);
        $instance->headers = array_merge($instance->headers, $headers);
        $instance->server = array_merge($instance->server, $server);
        
        return $instance;
    }

    /**
     * Flush all request data
     */
    public static function flush(): void
    {
        $_GET = [];
        $_POST = [];
        $_FILES = [];
        $_COOKIE = [];
        $_REQUEST = [];
        $_SERVER = [];
    }

    /**
     * Set configuration
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
        
        if (!empty($config['trusted_proxies'])) {
            self::$config['trusted_proxies'] = (array) $config['trusted_proxies'];
        }
    }

    /* =========================================================
     * BODY PARSING
     * ========================================================= */

    private function parseBody(): void
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        
        // Parse JSON body
        if (str_contains($contentType, 'application/json')) {
            $rawBody = file_get_contents('php://input');
            $json = json_decode($rawBody, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                $this->data = array_merge($this->data, $json);
            }
        }
        
        // Parse form data (already in $_POST)
        if (str_contains($contentType, 'application/x-www-form-urlencoded') || 
            str_contains($contentType, 'multipart/form-data')) {
            $this->data = array_merge($this->data, $_POST);
        }
        
        // Parse raw body for other content types
        if (in_array($this->method, ['PUT', 'PATCH', 'DELETE'])) {
            $rawBody = file_get_contents('php://input');
            
            if (!empty($rawBody)) {
                if (str_contains($contentType, 'application/json')) {
                    $decoded = json_decode($rawBody, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $this->data = array_merge($this->data, $decoded);
                    }
                } else {
                    // Parse as form-urlencoded
                    parse_str($rawBody, $parsed);
                    $this->data = array_merge($this->data, $parsed);
                }
            }
        }
    }

    /* =========================================================
     * SANITIZATION
     * ========================================================= */

    /**
     * Sanitize array data recursively
     */
    private function sanitizeData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
                continue;
            }
            
            if (is_string($value)) {
                // Ensure UTF-8 encoding
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                
                // Remove NULL bytes
                $value = str_replace(chr(0), '', $value);
                
                // Allow only safe HTML tags
                $allowedTags = '<b><i><u><strong><em><a><p><br><ul><ol><li><span><div><h1><h2><h3><h4><h5><h6>';
                $value = strip_tags($value, $allowedTags);
                
                // Remove event handlers (XSS protection)
                $value = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $value);
                $value = preg_replace('/on\w+\s*=\s*[^\s>]+/i', '', $value);
                
                // Remove javascript: protocol
                $value = preg_replace('/((javascript|vbscript|mocha|livescript)\s*:)/i', '', $value);
                
                // Remove CSS expressions
                $value = preg_replace('/expression\s*\(.*\)/i', '', $value);
                
                // Remove embedded objects
                $value = preg_replace('/<object[^>]*>.*?<\/object>/is', '', $value);
                $value = preg_replace('/<embed[^>]*>.*?<\/embed>/is', '', $value);
                
                // Remove script tags completely
                $value = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $value);
                
                // HTML encode special characters
                $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                
                $sanitized[$key] = trim($value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }

    /**
     * Escape a single string value
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitize array keys to prevent key injection
     */
    private function sanitizeKeys(array $array): array
    {
        $clean = [];
        
        foreach ($array as $key => $value) {
            // Allow only alphanumeric, underscore, and dash
            $safeKey = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $key);
            
            if (empty($safeKey)) {
                $safeKey = '_invalid_key_' . uniqid();
            }
            
            $clean[$safeKey] = is_array($value) ? $this->sanitizeKeys($value) : $value;
        }
        
        return $clean;
    }

    /**
     * Remove specific keys from data
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->data, array_flip($keys));
    }

    /* =========================================================
     * CSRF PROTECTION
     * ========================================================= */

    /**
     * Generate CSRF token
     */
    public function generateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['csrf_token'] = $token;
            $_SESSION['csrf_token_time'] = time();
        }
        
        $this->csrfToken = $token;
        
        return $token;
    }

    /**
     * Get CSRF token
     */
    public function csrfToken(): string
    {
        if ($this->csrfToken) {
            return $this->csrfToken;
        }
        
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['csrf_token'])) {
            // Check if token is expired
            $tokenTime = $_SESSION['csrf_token_time'] ?? 0;
            if (time() - $tokenTime < self::$config['csrf_expire']) {
                return $_SESSION['csrf_token'];
            }
        }
        
        return $this->generateCsrfToken();
    }

    /**
     * Get CSRF token as hidden input field
     */
    public function csrfField(): string
    {
        $token = $this->csrfToken();
        $name = self::$config['csrf_token_name'];
        
        return '<input type="hidden" name="' . self::escape($name) . '" value="' . self::escape($token) . '">';
    }

    /**
     * Get CSRF token as meta tag
     */
    public function csrfMeta(): string
    {
        $token = $this->csrfToken();
        
        return '<meta name="csrf-token" content="' . self::escape($token) . '">';
    }

    /**
     * Verify CSRF token
     */
    public function verifyCsrfToken(): bool
    {
        if (!self::$config['csrf_enabled']) {
            return true;
        }
        
        if (in_array($this->method, ['GET', 'HEAD', 'OPTIONS'])) {
            return true;
        }
        
        $token = $this->input(self::$config['csrf_token_name']) 
            ?? $this->header(self::$config['csrf_header_name']);
        
        if (!$token) {
            return false;
        }
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionToken = $_SESSION['csrf_token'] ?? null;
            $tokenTime = $_SESSION['csrf_token_time'] ?? 0;
            
            if (!$sessionToken || time() - $tokenTime > self::$config['csrf_expire']) {
                return false;
            }
            
            return hash_equals($sessionToken, $token);
        }
        
        return false;
    }

    /* =========================================================
     * RATE LIMITING
     * ========================================================= */

    /**
     * Set rate limit for this request
     */
    public function setRateLimit(int $limit, int $window = 60, ?string $key = null): self
    {
        $this->rateLimit = $limit;
        $this->rateLimitWindow = $window;
        $this->rateLimitKey = $key ?? $this->clientIp;
        
        return $this;
    }

    /**
     * Get rate limit
     */
    public function getRateLimit(): ?int
    {
        return $this->rateLimit;
    }

    /**
     * Check if rate limit exceeded
     */
    public function isRateLimitExceeded(): bool
    {
        if (!$this->rateLimit) {
            return false;
        }
        
        $key = $this->rateLimitKey ?? $this->clientIp;
        $window = $this->rateLimitWindow ?? 60;
        $cacheKey = "rate_limit:{$key}:" . floor(time() / $window);
        
        // Implementation depends on your cache system
        // This is a simple file-based example
        $cacheDir = sys_get_temp_dir() . '/rate_limits';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $file = $cacheDir . '/' . md5($cacheKey);
        $count = 0;
        
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data['expires'] > time()) {
                $count = $data['count'];
            }
        }
        
        if ($count >= $this->rateLimit) {
            return true;
        }
        
        // Increment counter
        file_put_contents($file, json_encode([
            'count' => $count + 1,
            'expires' => time() + $window
        ]));
        
        return false;
    }

    /**
     * Get rate limit headers for response
     */
    public function getRateLimitHeaders(): array
    {
        if (!$this->rateLimit) {
            return [];
        }
        
        $key = $this->rateLimitKey ?? $this->clientIp;
        $window = $this->rateLimitWindow ?? 60;
        $cacheKey = "rate_limit:{$key}:" . floor(time() / $window);
        $cacheDir = sys_get_temp_dir() . '/rate_limits';
        $file = $cacheDir . '/' . md5($cacheKey);
        
        $remaining = $this->rateLimit;
        $reset = time() + $window;
        
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data['expires'] > time()) {
                $remaining = max(0, $this->rateLimit - $data['count']);
                $reset = $data['expires'];
            }
        }
        
        return [
            'X-RateLimit-Limit' => $this->rateLimit,
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset' => $reset,
        ];
    }

    /* =========================================================
     * INPUT VALIDATION
     * ========================================================= */

    /**
     * Validate input data with rules
     * 
     * @param array $rules ['field' => 'required|email|min:5']
     * @param array $messages Custom error messages
     * @return bool
     */
    public function validate(array $rules, array $messages = []): bool
    {
        $this->validatedData = [];
        $this->validationErrors = [];
        
        foreach ($rules as $field => $ruleString) {
            $value = $this->input($field);
            $rules = explode('|', $ruleString);
            
            foreach ($rules as $rule) {
                $params = [];
                
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }
                
                $method = 'validate' . ucfirst($rule);
                
                if (method_exists($this, $method)) {
                    $result = $this->$method($field, $value, $params);
                    
                    if ($result !== true) {
                        $message = $messages[$field . '.' . $rule] 
                            ?? $messages[$field] 
                            ?? $result;
                        
                        $this->validationErrors[$field][] = $message;
                    }
                }
            }
            
            if (!isset($this->validationErrors[$field])) {
                $this->validatedData[$field] = $value;
            }
        }
        
        return empty($this->validationErrors);
    }

    /**
     * Get validated data
     */
    public function validated(): array
    {
        return $this->validatedData;
    }

    /**
     * Get validation errors
     */
    public function errors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Check if validation fails
     */
    public function fails(): bool
    {
        return !empty($this->validationErrors);
    }

    // Built-in validation rules
    
    private function validateRequired(string $field, $value, array $params): bool|string
    {
        if (is_null($value) || $value === '') {
            return "The {$field} field is required.";
        }
        return true;
    }

    private function validateEmail(string $field, $value, array $params): bool|string
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "The {$field} field must be a valid email address.";
        }
        return true;
    }

    private function validateMin(string $field, $value, array $params): bool|string
    {
        $min = (int) ($params[0] ?? 0);
        
        if (is_string($value) && strlen($value) < $min) {
            return "The {$field} field must be at least {$min} characters.";
        }
        
        if (is_numeric($value) && $value < $min) {
            return "The {$field} field must be at least {$min}.";
        }
        
        return true;
    }

    private function validateMax(string $field, $value, array $params): bool|string
    {
        $max = (int) ($params[0] ?? 0);
        
        if (is_string($value) && strlen($value) > $max) {
            return "The {$field} field must not exceed {$max} characters.";
        }
        
        if (is_numeric($value) && $value > $max) {
            return "The {$field} field must not exceed {$max}.";
        }
        
        return true;
    }

    private function validateNumeric(string $field, $value, array $params): bool|string
    {
        if (!is_numeric($value)) {
            return "The {$field} field must be numeric.";
        }
        return true;
    }

    private function validateInteger(string $field, $value, array $params): bool|string
    {
        if (!filter_var($value, FILTER_VALIDATE_INT)) {
            return "The {$field} field must be an integer.";
        }
        return true;
    }

    private function validateString(string $field, $value, array $params): bool|string
    {
        if (!is_string($value)) {
            return "The {$field} field must be a string.";
        }
        return true;
    }

    private function validateBoolean(string $field, $value, array $params): bool|string
    {
        if (!in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
            return "The {$field} field must be a boolean.";
        }
        return true;
    }

    private function validateIn(string $field, $value, array $params): bool|string
    {
        if (!in_array($value, $params)) {
            $allowed = implode(', ', $params);
            return "The {$field} field must be one of: {$allowed}.";
        }
        return true;
    }

    private function validateDate(string $field, $value, array $params): bool|string
    {
        if (!strtotime($value)) {
            return "The {$field} field must be a valid date.";
        }
        return true;
    }

    private function validateUrl(string $field, $value, array $params): bool|string
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return "The {$field} field must be a valid URL.";
        }
        return true;
    }

    private function validateRegex(string $field, $value, array $params): bool|string
    {
        $pattern = $params[0] ?? '';
        
        if (!preg_match($pattern, $value)) {
            return "The {$field} field format is invalid.";
        }
        return true;
    }

    private function validateConfirmed(string $field, $value, array $params): bool|string
    {
        $confirmation = $this->input($field . '_confirmation');
        
        if ($value !== $confirmation) {
            return "The {$field} field confirmation does not match.";
        }
        return true;
    }

    /* =========================================================
     * FILE HANDLING
     * ========================================================= */

    /**
     * Normalize files array for consistent access
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];
        
        foreach ($files as $field => $info) {
            if (!isset($info['name']) || !is_array($info['name'])) {
                // Single file
                $normalized[$field] = [
                    'name' => $info['name'] ?? '',
                    'type' => $info['type'] ?? '',
                    'tmp_name' => $info['tmp_name'] ?? '',
                    'error' => $info['error'] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $info['size'] ?? 0,
                ];
                continue;
            }
            
            // Multiple/nested files
            $normalized[$field] = $this->buildNestedFiles(
                $info['name'],
                $info['type'],
                $info['tmp_name'],
                $info['error'],
                $info['size']
            );
        }
        
        return $normalized;
    }

    private function buildNestedFiles($names, $types, $tmpNames, $errors, $sizes): array
    {
        $result = [];
        
        foreach ($names as $key => $nameVal) {
            if (is_array($nameVal)) {
                $result[$key] = $this->buildNestedFiles(
                    $names[$key],
                    $types[$key],
                    $tmpNames[$key],
                    $errors[$key],
                    $sizes[$key]
                );
            } else {
                $result[$key] = [
                    'name' => $this->sanitizeFileName($nameVal ?? ''),
                    'type' => $types[$key] ?? '',
                    'tmp_name' => $tmpNames[$key] ?? '',
                    'error' => $errors[$key] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $sizes[$key] ?? 0,
                ];
            }
        }
        
        return $result;
    }

    /**
     * Sanitize file name
     */
    private function sanitizeFileName(string $filename): string
    {
        // Remove path info
        $filename = basename($filename);
        
        // Remove special characters
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
        
        // Remove multiple dots
        $filename = preg_replace('/\.+/', '.', $filename);
        
        return $filename;
    }

    /**
     * Get nested file by dot notation path
     */
    public function getNestedFile(string $path): ?array
    {
        $segments = preg_split('/[.\[\]]+/', $path, -1, PREG_SPLIT_NO_EMPTY);
        $cursor = $this->files;
        
        foreach ($segments as $seg) {
            if (is_array($cursor) && array_key_exists($seg, $cursor)) {
                $cursor = $cursor[$seg];
            } else {
                return null;
            }
        }
        
        if (!is_array($cursor) || !isset($cursor['tmp_name'])) {
            return null;
        }
        
        return $cursor;
    }

    /**
     * Get file information
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        
        return [
            'name' => $file['name'] ?? '',
            'extension' => strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION)),
            'mime_type' => $file['type'] ?? '',
            'size' => $size,
            'size_kb' => $size > 0 ? round($size / 1024, 2) : 0,
            'size_mb' => $size > 0 ? round($size / 1048576, 2) : 0,
            'tmp_name' => $file['tmp_name'] ?? '',
            'error' => $file['error'] ?? UPLOAD_ERR_NO_FILE,
            'uploaded_at' => Date::Now(),
        ];
    }

    /**
     * Check if file exists and is valid
     */
    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) 
            && isset($this->files[$key]['tmp_name']) 
            && $this->files[$key]['error'] === UPLOAD_ERR_OK;
    }

    /**
     * Validate uploaded file
     */
    public function validateFile(string $key): ?string
    {
        $file = $this->files[$key] ?? null;
        
        if (!$file) {
            return 'File tidak ditemukan.';
        }
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File terlalu besar.',
                UPLOAD_ERR_PARTIAL => 'File hanya terupload sebagian.',
                UPLOAD_ERR_NO_FILE => 'Tidak ada file yang diupload.',
                default => 'Terjadi kesalahan saat upload file.',
            };
        }
        
        // Check file size
        $maxSize = self::$config['max_file_size'];
        if ($file['size'] > $maxSize) {
            $maxSizeMB = round($maxSize / 1048576, 2);
            return "File terlalu besar. Maksimal {$maxSizeMB}MB.";
        }
        
        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedTypes = self::$config['allowed_file_types'];
        
        if (!empty($allowedTypes) && !in_array($extension, $allowedTypes)) {
            $allowed = implode(', ', $allowedTypes);
            return "Tipe file tidak diizinkan. Tipe yang diizinkan: {$allowed}.";
        }
        
        // Verify MIME type using finfo (more reliable than client-provided MIME)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            // Basic MIME type validation
            $dangerousMimes = ['application/x-httpd-php', 'application/x-sh', 'text/html'];
            if (in_array($detectedMime, $dangerousMimes)) {
                return 'File mencurigakan terdeteksi.';
            }
        }
        
        return null; // No error
    }

    /**
     * Move uploaded file
     */
    public function moveFile(string $key, string $destination, ?string $filename = null): ?string
    {
        $error = $this->validateFile($key);
        
        if ($error) {
            throw new \RuntimeException($error);
        }
        
        $file = $this->files[$key];
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $filename ?? uniqid() . '.' . $extension;
        
        // Ensure directory exists
        $dir = dirname($destination . '/' . $filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $fullPath = rtrim($destination, '/') . '/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $fullPath)) {
            return $filename;
        }
        
        return null;
    }

    // File info helpers
    public function getClientOriginalExtension(string $key): string
    {
        $file = $this->files[$key] ?? null;
        return $file ? strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) : '';
    }

    public function getClientOriginalName(string $key): string
    {
        return $this->files[$key]['name'] ?? '';
    }

    public function getClientMimeType(string $key): string
    {
        return $this->files[$key]['type'] ?? '';
    }

    public function getSize(string $key): int
    {
        return (int) ($this->files[$key]['size'] ?? 0);
    }

    public function getPath(string $key): string
    {
        return $this->files[$key]['tmp_name'] ?? '';
    }

    /* =========================================================
     * DATA ACCESS
     * ========================================================= */

    /**
     * Get all request data
     */
    public function all(): array
    {
        return array_merge($this->data, $this->files);
    }

    /**
     * Get input value with dot notation support
     */
    public function input(string $key, mixed $default = null): mixed
    {
        // Check dot notation
        if (str_contains($key, '.')) {
            return $this->getDotNotation($this->data, $key, $default);
        }
        
        $value = $this->data[$key] 
            ?? $this->queryParams[$key] 
            ?? $_REQUEST[$key] 
            ?? $default;

        // Try to decode JSON strings
        if (is_string($value)) {
            $decodedValue = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $json = json_decode($decodedValue, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }

        return $value;
    }

    /**
     * Get query parameter
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->queryParams[$key] ?? $default;
    }

    /**
     * Get only specified keys
     */
    public function only(array $keys): array
    {
        $filtered = [];
        
        foreach ($keys as $key) {
            if (isset($this->data[$key])) {
                $filtered[$key] = $this->data[$key];
            }
        }
        
        return $filtered;
    }

    /**
     * Get value by dot notation
     */
    private function getDotNotation(array $array, string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        
        foreach ($keys as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }
        
        return $array;
    }

    /**
     * Get boolean input
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'on']);
        }
        
        return $default;
    }

    /**
     * Get integer input
     */
    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Get float input
     */
    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->input($key);
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Get date input
     */
    public function date(string $key, ?string $default = null): ?string
    {
        $value = $this->input($key);
        
        if (empty($value)) {
            return $default;
        }
        
        $timestamp = strtotime($value);
        return $timestamp ? date('Y-m-d', $timestamp) : $default;
    }

    /* =========================================================
     * HEADERS
     * ========================================================= */

    /**
     * Parse all headers
     */
    private function parseHeaders(): array
    {
        $headers = [];
        
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $formatted = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$formatted] = $value;
            }
        }
        
        // Add Content-Type and Content-Length
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        
        return $headers;
    }

    /**
     * Get single header
     */
    public function header(string $key, mixed $default = null): mixed
    {
        $key = strtolower($key);
        return $this->headers[$key] 
            ?? $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $key))] 
            ?? $default;
    }

    /**
     * Get all headers
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Check if request has header
     */
    public function hasHeader(string $key): bool
    {
        return $this->header($key) !== null;
    }

    /**
     * Get bearer token
     */
    public function bearerToken(): ?string
    {
        $authorization = $this->header('authorization');
        
        if ($authorization && str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }
        
        return null;
    }

    /* =========================================================
     * REQUEST INFO
     * ========================================================= */

    /**
     * Get request method
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Check if method matches
     */
    public function isMethod(string $method): bool
    {
        return strtoupper($method) === $this->method;
    }

    /**
     * Get request URI
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Get full URL
     */
    public function fullUrl(): string
    {
        return $this->fullUrl;
    }

    /**
     * Get base URL
     */
    public function baseUrl(): string
    {
        $scheme = $this->isSecure ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        return "{$scheme}://{$host}";
    }

    /**
     * Parse URI from server
     */
    private function parseUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Remove query string
        $uri = strtok($uri, '?');
        
        // Remove base path if needed
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = dirname($scriptName);
        
        if ($scriptDir !== '/' && $scriptDir !== '\\' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }
        
        return '/' . trim($uri, '/');
    }

    /**
     * Build full URL
     */
    private function buildFullUrl(): string
    {
        $scheme = $this->isSecure ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        return "{$scheme}://{$host}{$uri}";
    }

    /**
     * Get client IP address
     */
    private function resolveClientIp(): string
    {
        return $this->resolveClientIpFromServer($_SERVER);
    }

    /**
     * Resolve client IP from server array (supports proxies)
     */
    private function resolveClientIpFromServer(array $server): string
    {
        $trustedProxies = self::$config['trusted_proxies'];
        $remoteAddr = $server['REMOTE_ADDR'] ?? '127.0.0.1';
        
        // If behind trusted proxy, use X-Forwarded-For
        if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies)) {
            $forwardedFor = $server['HTTP_X_FORWARDED_FOR'] ?? '';
            
            if (!empty($forwardedFor)) {
                $ips = explode(',', $forwardedFor);
                $clientIp = trim($ips[0]);
                
                if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                    return $clientIp;
                }
            }
        }
        
        return filter_var($remoteAddr, FILTER_VALIDATE_IP) ? $remoteAddr : '127.0.0.1';
    }

    /**
     * Get client IP
     */
    public function ip(): string
    {
        return $this->clientIp;
    }

    /**
     * Get user agent
     */
    public function userAgent(): string
    {
        return $this->userAgent;
    }

    /**
     * Detect secure connection
     */
    private function detectSecure(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        
        return $https === 'on' 
            || $https === '1' 
            || ($_SERVER['SERVER_PORT'] ?? 80) == 443
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * Check if secure
     */
    public function isSecure(): bool
    {
        return $this->isSecure;
    }

    /**
     * Get request scheme
     */
    public function scheme(): string
    {
        return $this->isSecure ? 'https' : 'http';
    }

    /**
     * Get request host
     */
    public function host(): string
    {
        return $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    /**
     * Get request port
     */
    public function port(): int
    {
        return (int) ($_SERVER['SERVER_PORT'] ?? 80);
    }

    /**
     * Get request path (without query string)
     */
    public function path(): string
    {
        return $this->uri;
    }

    /* =========================================================
     * CONTENT TYPE DETECTION
     * ========================================================= */

    /**
     * Check if request expects JSON response
     */
    public function expectsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }

    /**
     * Check if request is JSON
     */
    public function isJson(): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        return str_contains($contentType, 'application/json');
    }

    /**
     * Check if request is AJAX
     */
    public static function isAjax(): bool
    {
        $xRequested = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        
        return strtolower($xRequested) === 'xmlhttprequest'
            || str_contains($accept, 'application/json');
    }

    /**
     * Check if request is PJAX
     */
    public function isPjax(): bool
    {
        return $this->header('X-PJAX') !== null;
    }

    /**
     * Get content type
     */
    public function contentType(): string
    {
        return $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? 'text/plain';
    }

    /* =========================================================
     * COOKIES
     * ========================================================= */

    /**
     * Parse cookies
     */
    private function parseCookies(): array
    {
        $cookies = [];
        
        foreach ($_COOKIE as $key => $value) {
            if (self::$config['encrypt_cookies']) {
                $cookies[$key] = $this->decryptCookie($value);
            } else {
                $cookies[$key] = $value;
            }
        }
        
        return $cookies;
    }

    /**
     * Get cookie value
     */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * Check if cookie exists
     */
    public function hasCookie(string $key): bool
    {
        return isset($this->cookies[$key]);
    }

    /**
     * Simple cookie encryption (use proper encryption in production)
     */
    private function decryptCookie(string $value): string
    {
        $key = self::$config['cookie_encryption_key'] ?? 'app_secret_key';
        $decoded = base64_decode($value);
        
        if ($decoded === false) {
            return $value;
        }
        
        $ivLength = openssl_cipher_iv_length('AES-256-CBC');
        $iv = substr($decoded, 0, $ivLength);
        $encrypted = substr($decoded, $ivLength);
        
        $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
        
        return $decrypted !== false ? $decrypted : $value;
    }

    /* =========================================================
     * CORS SUPPORT
     * ========================================================= */

    /**
     * Get CORS headers
     */
    public function getCorsHeaders(): array
    {
        $origin = $this->header('origin');
        $allowedOrigins = self::$config['allowed_origins'];
        
        $allowOrigin = '*';
        
        if ($origin && $allowedOrigins !== ['*']) {
            if (in_array($origin, $allowedOrigins)) {
                $allowOrigin = $origin;
            } else {
                // Origin not allowed
                return [];
            }
        }
        
        return [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN, X-API-Key',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age' => '86400',
            'Access-Control-Expose-Headers' => 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset',
        ];
    }

    /**
     * Check if request is CORS preflight
     */
    public function isPreflight(): bool
    {
        return $this->method === 'OPTIONS' && $this->header('access-control-request-method') !== null;
    }

    /* =========================================================
     * MAGIC METHODS
     * ========================================================= */

    /**
     * Dynamic property access
     */
    public function __get(string $key): mixed
    {
        return $this->input($key);
    }

    /**
     * Check if property exists
     */
    public function __isset(string $key): bool
    {
        return $this->input($key) !== null;
    }

    /* =========================================================
     * UTILITY METHODS
     * ========================================================= */

    /**
     * Get previous URL
     */
    public function previousUrl(): string
    {
        return $_SERVER['HTTP_REFERER'] ?? $this->baseUrl();
    }

    /**
     * Get session ID
     */
    public function sessionId(): ?string
    {
        return session_id() ?: null;
    }

    /**
     * Generate unique request ID
     */
    public function requestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Convert request to array
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'uri' => $this->uri,
            'full_url' => $this->fullUrl,
            'ip' => $this->clientIp,
            'user_agent' => $this->userAgent,
            'is_secure' => $this->isSecure,
            'headers' => $this->headers,
            'data' => $this->data,
            'query' => $this->queryParams,
            'files' => $this->files,
        ];
    }

    /**
     * Get all server variables
     */
    public function server(): array
    {
        return $this->server;
    }
}
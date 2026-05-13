<?php
namespace Bpjs\Framework\Helpers\Http;

/**
 * Laravel-style HTTP Client Helper.
 *
 * ─── Quick Reference ───────────────────────────────────────────────────────
 *
 *  // Fluent builder
 *  Http::withToken($token)
 *      ->withHeaders(['X-App' => 'BPJS'])
 *      ->timeout(10)
 *      ->retry(3, 200)
 *      ->get('https://api.example.com/users');
 *
 *  // Pool (concurrent / paralel)
 *  $responses = Http::pool(function (HttpPool $pool) {
 *      $pool->as('users')->get('https://api.example.com/users');
 *      $pool->as('posts')->get('https://api.example.com/posts');
 *  });
 *  $responses['users']->json();
 *
 *  // Fake / mock (untuk testing)
 *  Http::fake(['https://api.example.com/*' => ['status' => 200, 'body' => ['ok' => true]]]);
 *  Http::fake()->assertSent('https://api.example.com/*');
 *  Http::resetFake();
 *
 *  // Response object
 *  $res = Http::get('https://api.example.com/users');
 *  $res->ok();             // true / false
 *  $res->json('data.0');   // dot notation
 *  $res->throw();          // lempar exception jika 4xx / 5xx
 *  $res->status();         // integer
 *
 *  // Macro
 *  Http::macro('bpjsApi', fn() => Http::withToken(env('BPJS_TOKEN'))
 *                                      ->baseUrl('https://api.bpjs.go.id'));
 *  Http::bpjsApi()->get('/peserta');
 */
class Http
{
    // ─── Shared State ─────────────────────────────────────────────────────────

    private static HttpFake $faker;
    private static array    $macros     = [];
    private static array    $middleware = []; // global middleware

    // ─── Instance State (fluent builder) ─────────────────────────────────────

    private array       $headers        = [];
    private array       $cookies        = [];
    private array       $queryParams    = [];
    private mixed       $body           = null;
    private bool        $isMultipart    = false;
    private string|null $baseUrl        = null;
    private int         $timeout        = 30;
    private int         $maxRetries     = 0;
    private int         $retryDelay     = 100; // ms
    private bool        $verifySsl      = true;
    private bool        $throwOnError   = false;
    private array       $beforeHooks    = [];
    private array       $afterHooks     = [];
    private bool        $debugMode      = false;

    // ─── Static Entry Points ─────────────────────────────────────────────────

    public static function new(): static
    {
        $instance = new static();
        // Terapkan global middleware ke instance baru
        foreach (self::$middleware as $m) {
            $m($instance);
        }
        return $instance;
    }

    // ─── Fluent Builder ───────────────────────────────────────────────────────

    public function baseUrl(string $url): static
    {
        $this->baseUrl = rtrim($url, '/');
        return $this;
    }

    public function withHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /**
     * Bearer token: Authorization: Bearer {token}
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withHeaders(['Authorization' => "{$type} {$token}"]);
    }

    /**
     * Basic Auth: Authorization: Basic base64(user:pass)
     */
    public function withBasicAuth(string $username, string $password): static
    {
        return $this->withHeaders([
            'Authorization' => 'Basic ' . base64_encode("{$username}:{$password}"),
        ]);
    }

    /**
     * Digest Auth (dikirim via cURL native).
     */
    public function withDigestAuth(string $username, string $password): static
    {
        $this->beforeHooks[] = function (\CurlHandle $ch) use ($username, $password) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
        };
        return $this;
    }

    public function withQueryParameters(array $params): static
    {
        $this->queryParams = array_merge($this->queryParams, $params);
        return $this;
    }

    public function withCookies(array $cookies, string $domain = ''): static
    {
        $this->cookies = array_merge($this->cookies, $cookies);
        return $this;
    }

    public function withoutVerifying(): static
    {
        $this->verifySsl = false;
        return $this;
    }

    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function retry(int $times, int $sleepMs = 100): static
    {
        $this->maxRetries = $times;
        $this->retryDelay = $sleepMs;
        return $this;
    }

    /**
     * Otomatis lempar HttpException jika response 4xx / 5xx.
     */
    public function throw(): static
    {
        $this->throwOnError = true;
        return $this;
    }

    /**
     * Tambah hook yang dijalankan sebelum request dikirim.
     * Callback menerima (string $method, string $url, array &$headers, mixed &$body).
     */
    public function beforeSending(callable $hook): static
    {
        $this->beforeHooks[] = $hook;
        return $this;
    }

    /**
     * Tambah hook yang dijalankan setelah response diterima.
     * Callback menerima (HttpResponse $response).
     */
    public function afterReceiving(callable $hook): static
    {
        $this->afterHooks[] = $hook;
        return $this;
    }

    /**
     * Dump request & response ke stdout, lanjutkan eksekusi.
     */
    public function dump(): static
    {
        $this->debugMode = true;
        return $this;
    }

    /**
     * Dump request & response ke stdout, lalu exit.
     */
    public function dd(): never
    {
        $this->debugMode = true;
        $this->get('/'); // trigger dump — biasanya digabung dengan URL nyata
        exit(1);
    }

    // ─── HTTP Methods ─────────────────────────────────────────────────────────

    public function get(string $url, array $query = []): HttpResponse
    {
        if (!empty($query)) {
            $this->withQueryParameters($query);
        }
        return $this->send('GET', $url);
    }

    public function post(string $url, mixed $data = []): HttpResponse
    {
        $this->body = $data;
        return $this->send('POST', $url);
    }

    public function put(string $url, mixed $data = []): HttpResponse
    {
        $this->body = $data;
        return $this->send('PUT', $url);
    }

    public function patch(string $url, mixed $data = []): HttpResponse
    {
        $this->body = $data;
        return $this->send('PATCH', $url);
    }

    public function delete(string $url, mixed $data = []): HttpResponse
    {
        $this->body = $data;
        return $this->send('DELETE', $url);
    }

    /**
     * Multipart/form-data (upload file).
     *
     * $files = ['fieldName' => '/path/to/file']
     */
    public function attach(string $url, array $fields = [], array $files = []): HttpResponse
    {
        $multipart = $fields;
        foreach ($files as $field => $path) {
            if (!file_exists($path)) {
                throw new \InvalidArgumentException("File not found: {$path}");
            }
            $multipart[$field] = new \CURLFile(
                $path,
                mime_content_type($path) ?: 'application/octet-stream',
                basename($path)
            );
        }
        $this->body        = $multipart;
        $this->isMultipart = true;
        return $this->send('POST', $url);
    }

    // ─── Pool ─────────────────────────────────────────────────────────────────

    /**
     * Jalankan banyak request secara PARALEL.
     *
     * @param callable(HttpPool): void $callback
     * @return array<string|int, HttpResponse>
     */
    public static function pool(callable $callback): array
    {
        $pool = new HttpPool();
        $callback($pool);
        return $pool->execute();
    }

    // ─── Fake / Mock ──────────────────────────────────────────────────────────

    /**
     * Aktifkan fake mode.
     *
     * Http::fake();                                          // semua → 200 {}
     * Http::fake(['status' => 404, 'body' => []]);          // semua → 404
     * Http::fake(['https://api.example.com/*' => [...]]);   // per URL
     *
     * @return HttpFake  supaya bisa langsung ->assertSent(...)
     */
    public static function fake(array|null $stubs = null): HttpFake
    {
        self::getFaker()->activate($stubs);
        return self::getFaker();
    }

    public static function resetFake(): void
    {
        self::getFaker()->deactivate();
    }

    private static function getFaker(): HttpFake
    {
        if (!isset(self::$faker)) {
            self::$faker = new HttpFake();
        }
        return self::$faker;
    }

    // ─── Macro ────────────────────────────────────────────────────────────────

    /**
     * Daftarkan custom method.
     *
     * Http::macro('bpjsApi', fn() => Http::withToken(env('BPJS_TOKEN'))
     *                                     ->baseUrl('https://api.bpjs.go.id'));
     * Http::bpjsApi()->get('/peserta');
     */
    public static function macro(string $name, callable $fn): void
    {
        self::$macros[$name] = $fn;
    }

    /**
     * Global middleware — diterapkan ke setiap instance baru.
     *
     * Http::withMiddleware(function (Http $http) {
     *     $http->withToken(env('API_TOKEN'));
     * });
     */
    public static function withMiddleware(callable $middleware): void
    {
        self::$middleware[] = $middleware;
    }

    public static function resetMiddleware(): void
    {
        self::$middleware = [];
    }

    /**
     * Magic static: Http::get(...), Http::post(...), Http::bpjsApi(), dll.
     */
    public static function __callStatic(string $name, array $args): mixed
    {
        // Macro
        if (isset(self::$macros[$name])) {
            return (self::$macros[$name])(...$args);
        }

        // Shortcut ke instance method (Http::get(), Http::post(), dll)
        $instance = static::new();
        if (method_exists($instance, $name)) {
            return $instance->$name(...$args);
        }

        throw new \BadMethodCallException("Http::{$name}() tidak ditemukan.");
    }

    // ─── Core Send ────────────────────────────────────────────────────────────

    private function send(string $method, string $url): HttpResponse
    {
        $url = $this->buildUrl($url);

        // Fake mode — jangan kirim request sungguhan
        if (self::getFaker()->isActive()) {
            return self::getFaker()->resolve($method, $url);
        }

        $attempt   = 0;
        $lastError = null;

        while (true) {
            try {
                $response = $this->execute($method, $url);

                if ($this->debugMode) {
                    $response->dump();
                }

                // Retry pada 5xx
                if ($response->serverError() && $attempt < $this->maxRetries) {
                    $attempt++;
                    usleep($this->retryDelay * 1000 * $attempt);
                    continue;
                }

                // Jalankan after-hooks
                foreach ($this->afterHooks as $hook) {
                    $hook($response);
                }

                if ($this->throwOnError && $response->failed()) {
                    throw new HttpException(
                        "HTTP {$response->status()} — {$method} {$url}",
                        $response->status(),
                        $response->json()
                    );
                }

                return $response;

            } catch (HttpException $e) {
                throw $e;
            } catch (\Exception $e) {
                $lastError = $e;
                if ($attempt < $this->maxRetries) {
                    $attempt++;
                    usleep($this->retryDelay * 1000 * $attempt);
                    continue;
                }
                break;
            }
        }

        throw new \RuntimeException(
            "Request gagal setelah " . ($this->maxRetries + 1) . " percobaan: " . $lastError?->getMessage(),
            0,
            $lastError
        );
    }

    private function execute(string $method, string $url): HttpResponse
    {
        $ch      = curl_init();
        $headers = $this->buildHeaders();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HEADER         => true, // untuk ambil response headers
        ]);

        // Cookies
        if (!empty($this->cookies)) {
            $cookieStr = implode('; ', array_map(
                fn($k, $v) => "{$k}={$v}",
                array_keys($this->cookies),
                $this->cookies
            ));
            curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
        }

        // Body
        if ($this->body !== null && $this->body !== []) {
            if ($this->isMultipart) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->body);
                // Jangan set Content-Type manual; cURL akan set multipart boundary otomatis
            } else {
                $json = json_encode($this->body);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                $headers['Content-Type']   = 'application/json';
                $headers['Content-Length'] = strlen($json);
            }
        }

        // Before hooks (akses ke CurlHandle langsung)
        foreach ($this->beforeHooks as $hook) {
            if ($hook instanceof \Closure) {
                $ref = new \ReflectionFunction($hook);
                $firstParam = $ref->getParameters()[0] ?? null;
                if ($firstParam && $firstParam->getType()?->getName() === \CurlHandle::class) {
                    $hook($ch);
                    continue;
                }
            }
            $hook($method, $url, $headers, $this->body);
        }

        // Set headers sebagai array
        $headerArray = array_map(
            fn($k, $v) => is_int($k) ? $v : "{$k}: {$v}",
            array_keys($headers),
            $headers
        );
        if (!empty($headerArray)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        }

        $raw      = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException("cURL error ({$errno}): {$errMsg}");
        }

        $responseHeaders = $this->parseHeaders(substr($raw, 0, $headerSize));
        $body            = substr($raw, $headerSize);

        return new HttpResponse($httpCode, $body, $responseHeaders);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function buildUrl(string $url): string
    {
        // Gabungkan dengan base URL jika URL tidak absolute
        if ($this->baseUrl && !str_starts_with($url, 'http')) {
            $url = $this->baseUrl . '/' . ltrim($url, '/');
        }

        if (!empty($this->queryParams)) {
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator . http_build_query($this->queryParams);
        }

        return $url;
    }

    private function buildHeaders(): array
    {
        $defaults = [
            'Accept'     => 'application/json',
            'User-Agent' => 'Bpjs-Http/1.0',
        ];
        return array_merge($defaults, $this->headers);
    }

    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (str_contains($line, ':')) {
                [$key, $val] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($val);
            }
        }
        return $headers;
    }
}
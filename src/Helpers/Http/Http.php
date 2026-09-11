<?php
namespace Bpjs\Framework\Helpers\Http;

/**
 * Laravel-style HTTP Client Helper.
 *
 * Dua cara pakai (keduanya setara):
 *   Http::get('https://api.example.com/users');          // static shortcut
 *   Http::new()->get('https://api.example.com/users');   // instance eksplisit
 */
class Http
{
    // ─── Shared State ────────────────────────────────────────────────────
    private static ?HttpFake $faker      = null;
    private static array     $macros     = [];
    private static array     $middleware = [];

    // ─── Instance State ──────────────────────────────────────────────────
    private array   $headers     = [];
    private array   $cookies     = [];
    private array   $queryParams = [];
    private mixed   $body        = null;
    private bool    $isMultipart = false;
    private ?string $baseUrl     = null;
    private int     $timeout     = 30;
    private int     $maxRetries  = 0;
    private int     $retryDelay  = 100;
    private bool    $verifySsl   = true;
    private bool    $throwOnError = false;
    private bool    $exitOnDump  = false;
    private array   $beforeHooks = [];
    private array   $afterHooks  = [];

    // ─── Static Entry ────────────────────────────────────────────────────
    public static function new(): static
    {
        $instance = new static();
        foreach (self::$middleware as $m) {
            $m($instance);
        }
        return $instance;
    }

    // ─── Fluent Builder (protected → diakses via __call dari luar) ───────
    protected function baseUrl(string $url): static
    {
        $this->baseUrl = rtrim($url, '/');
        return $this;
    }

    protected function withHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    protected function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withHeaders(['Authorization' => "{$type} {$token}"]);
    }

    protected function withBasicAuth(string $username, string $password): static
    {
        return $this->withHeaders([
            'Authorization' => 'Basic ' . base64_encode("{$username}:{$password}"),
        ]);
    }

    protected function withDigestAuth(string $username, string $password): static
    {
        $this->beforeHooks[] = function (\CurlHandle $ch) use ($username, $password) {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            curl_setopt($ch, CURLOPT_USERPWD, "{$username}:{$password}");
        };
        return $this;
    }

    protected function withQueryParameters(array $params): static
    {
        $this->queryParams = array_merge($this->queryParams, $params);
        return $this;
    }

    protected function withCookies(array $cookies): static
    {
        $this->cookies = array_merge($this->cookies, $cookies);
        return $this;
    }

    protected function withoutVerifying(): static
    {
        $this->verifySsl = false;
        return $this;
    }

    protected function timeout(int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    protected function retry(int $times, int $sleepMs = 100): static
    {
        $this->maxRetries = $times;
        $this->retryDelay = $sleepMs;
        return $this;
    }

    protected function throw(): static
    {
        $this->throwOnError = true;
        return $this;
    }

    protected function beforeSending(callable $hook): static
    {
        $this->beforeHooks[] = $hook;
        return $this;
    }

    protected function afterReceiving(callable $hook): static
    {
        $this->afterHooks[] = $hook;
        return $this;
    }

    /** Dump request + response ke stdout setelah request selesai. */
    protected function dump(): static
    {
        $this->exitOnDump = false;
        return $this->enableDebug();
    }

    /** Sama seperti dump(), tapi exit setelahnya. */
    protected function dd(): static
    {
        $this->exitOnDump = true;
        return $this->enableDebug();
    }

    private function enableDebug(): static
    {
        // disimpan di afterHooks supaya tidak perlu property khusus
        $this->afterHooks[] = function (HttpResponse $res) {
            $res->dump();
        };
        return $this;
    }

    // ─── HTTP Verbs ──────────────────────────────────────────────────────
    protected function get(string $url, array $query = []): HttpResponse
    {
        if (!empty($query)) {
            $this->withQueryParameters($query);
        }
        return $this->send('GET', $url);
    }

    protected function post(string $url, mixed $data = []): HttpResponse
    {
        $this->body = $data;
        return $this->send('POST', $url);
    }

    protected function put(string $url, mixed $data = []): HttpResponse
    {
        $this->body = $data;
        return $this->send('PUT', $url);
    }

    protected function patch(string $url, mixed $data = []): HttpResponse
    {
        $this->body = $data;
        return $this->send('PATCH', $url);
    }

    protected function delete(string $url, mixed $data = []): HttpResponse
    {
        $this->body = $data;
        return $this->send('DELETE', $url);
    }

    protected function attach(string $url, array $fields = [], array $files = []): HttpResponse
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

    // ─── Pool ────────────────────────────────────────────────────────────
    public static function pool(callable $callback): array
    {
        $pool = new HttpPool();
        $callback($pool);
        return $pool->execute();
    }

    // ─── Fake ────────────────────────────────────────────────────────────
    public static function fake(array|null $stubs = null): HttpFake
    {
        self::getFaker()->activate($stubs);
        return self::getFaker();
    }

    public static function resetFake(): void
    {
        self::getFaker()->deactivate();
    }

    public static function getFaker(): HttpFake
    {
        if (self::$faker === null) {
            self::$faker = new HttpFake();
        }
        return self::$faker;
    }

    // ─── Static Assertions (proxy ke faker, TIDAK reset state) ───────────
    public static function assertSent(string $pattern): void
    {
        self::getFaker()->assertSent($pattern);
    }

    public static function assertNotSent(string $pattern): void
    {
        self::getFaker()->assertNotSent($pattern);
    }

    public static function assertSentCount(int $count): void
    {
        self::getFaker()->assertSentCount($count);
    }

    public static function assertNothingSent(): void
    {
        self::getFaker()->assertNothingSent();
    }

    public static function recorded(): array
    {
        return self::getFaker()->recorded();
    }

    // ─── Macro & Middleware ──────────────────────────────────────────────
    public static function macro(string $name, callable $fn): void
    {
        self::$macros[$name] = $fn;
    }

    public static function withMiddleware(callable $middleware): void
    {
        self::$middleware[] = $middleware;
    }

    public static function resetMiddleware(): void
    {
        self::$middleware = [];
    }

    // ─── Magic ───────────────────────────────────────────────────────────
    public static function __callStatic(string $name, array $args): mixed
    {
        if (isset(self::$macros[$name])) {
            return (self::$macros[$name])(...$args);
        }

        $instance = static::new();
        if (method_exists($instance, $name)) {
            return $instance->$name(...$args);
        }

        throw new \BadMethodCallException("Http::{$name}() tidak ditemukan.");
    }

    public function __call(string $name, array $args): mixed
    {
        if (method_exists($this, $name)) {
            return $this->$name(...$args);
        }
        throw new \BadMethodCallException("Http::{$name}() tidak ditemukan.");
    }

    // ─── Core Send ───────────────────────────────────────────────────────
    private function send(string $method, string $url): HttpResponse
    {
        $url = $this->buildUrl($url);

        if (self::getFaker()->isActive()) {
            $res = self::getFaker()->resolve($method, $url);
            $this->runAfterHooks($res);
            if ($this->exitOnDump) exit(1);
            return $res;
        }

        $attempt = 0;
        $lastError = null;

        while (true) {
            try {
                $response = $this->execute($method, $url);

                if ($response->serverError() && $attempt < $this->maxRetries) {
                    $attempt++;
                    usleep($this->retryDelay * 1000 * $attempt);
                    continue;
                }

                $this->runAfterHooks($response);

                if ($this->exitOnDump) {
                    exit(1);
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
            } catch (\Throwable $e) {
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
            "Request gagal setelah " . ($this->maxRetries + 1) . " percobaan: "
                . ($lastError?->getMessage() ?? 'unknown'),
            0,
            $lastError
        );
    }

    private function runAfterHooks(HttpResponse $response): void
    {
        foreach ($this->afterHooks as $hook) {
            $hook($response);
        }
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
            CURLOPT_HEADER         => true,
        ]);

        if (!empty($this->cookies)) {
            $cookieStr = implode('; ', array_map(
                fn($k, $v) => "{$k}={$v}",
                array_keys($this->cookies),
                $this->cookies
            ));
            curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
        }

        if ($this->body !== null && $this->body !== []) {
            if ($this->isMultipart) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->body);
            } else {
                $json = json_encode($this->body);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
                $headers['Content-Type']   = 'application/json';
                $headers['Content-Length'] = strlen($json);
            }
        }

        // Before hooks
        foreach ($this->beforeHooks as $hook) {
            if ($hook instanceof \Closure) {
                $ref        = new \ReflectionFunction($hook);
                $firstParam = $ref->getParameters()[0] ?? null;
                $type       = $firstParam?->getType()?->getName();
                if ($type === \CurlHandle::class) {
                    $hook($ch);
                    continue;
                }
            }
            $hook($method, $url, $headers, $this->body);
        }

        $headerArray = array_map(
            fn($k, $v) => is_int($k) ? $v : "{$k}: {$v}",
            array_keys($headers),
            $headers
        );
        if (!empty($headerArray)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        }

        $raw        = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno      = curl_errno($ch);
        $errMsg     = curl_error($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException("cURL error ({$errno}): {$errMsg}");
        }

        $responseHeaders = $this->parseHeaders(substr($raw, 0, $headerSize));
        $body            = substr($raw, $headerSize);

        return new HttpResponse($httpCode, $body, $responseHeaders);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────
    private function buildUrl(string $url): string
    {
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
        return array_merge([
            'Accept'     => 'application/json',
            'User-Agent' => 'Bpjs-Http/1.0',
        ], $this->headers);
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
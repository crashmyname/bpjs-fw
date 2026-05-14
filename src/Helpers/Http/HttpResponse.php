<?php
namespace Bpjs\Framework\Helpers\Http;

/**
 * Laravel-style HTTP Response wrapper.
 *
 * Usage:
 *   $res = Http::get('https://api.example.com/users');
 *   $res->ok();           // true jika status 200–299
 *   $res->json();         // decode JSON sebagai array
 *   $res->json('data.0'); // dot-notation access
 *   $res->throw();        // lempar exception jika gagal
 *   $res->status();       // integer HTTP status
 */
class HttpResponse
{
    private int    $status;
    private string $body;
    private array  $headers;

    public function __construct(
        int    $status,
        string $body,
        array  $headers = [],
    ) {
        $this->status = $status;
        $this->body = $body;
        $this->headers = $headers;
    }

    // ─── Status Checks ────────────────────────────────────────────────────────

    public function status(): int
    {
        return $this->status;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function created(): bool
    {
        return $this->status === 201;
    }

    public function noContent(): bool
    {
        return $this->status === 204;
    }

    public function redirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function unauthorized(): bool
    {
        return $this->status === 401;
    }

    public function forbidden(): bool
    {
        return $this->status === 403;
    }

    public function notFound(): bool
    {
        return $this->status === 404;
    }

    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    public function failed(): bool
    {
        return $this->clientError() || $this->serverError();
    }

    public function successful(): bool
    {
        return $this->ok();
    }

    // ─── Body Access ─────────────────────────────────────────────────────────

    /**
     * Decode JSON body. Supports dot-notation: $res->json('user.name')
     */
    public function json(string $key = null, mixed $default = null): mixed
    {
        if ($this->decoded === null) {
            $this->decoded = json_decode($this->body, true) ?? [];
        }

        if ($key === null) {
            return $this->decoded;
        }

        return $this->dotGet($this->decoded, $key, $default);
    }

    public function body(): string
    {
        return $this->body;
    }

    public function object(): object|null
    {
        return json_decode($this->body);
    }

    public function collect(): array
    {
        return $this->json() ?? [];
    }

    // ─── Headers ─────────────────────────────────────────────────────────────

    public function header(string $name): string|null
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    // ─── Exception Helpers ────────────────────────────────────────────────────

    /**
     * Lempar HttpException jika response gagal (4xx / 5xx).
     */
    public function throw(): static
    {
        if ($this->failed()) {
            throw new HttpException(
                "HTTP Error {$this->status}",
                $this->status,
                $this->json()
            );
        }
        return $this;
    }

    /**
     * Lempar exception hanya jika kondisi $condition terpenuhi.
     */
    public function throwIf(bool $condition): static
    {
        if ($condition) {
            return $this->throw();
        }
        return $this;
    }

    public function throwUnlessStatus(int $status): static
    {
        if ($this->status !== $status) {
            return $this->throw();
        }
        return $this;
    }

    // ─── Debug ────────────────────────────────────────────────────────────────

    public function dd(): never
    {
        $this->dump();
        exit(1);
    }

    public function dump(): static
    {
        echo "\n[HttpResponse]\n";
        echo "  Status  : {$this->status}\n";
        echo "  Headers : " . json_encode($this->headers, JSON_PRETTY_PRINT) . "\n";
        echo "  Body    : " . json_encode($this->json() ?? $this->body, JSON_PRETTY_PRINT) . "\n\n";
        return $this;
    }

    // ─── Array Access ─────────────────────────────────────────────────────────

    public function offsetGet(string $key): mixed
    {
        return $this->json($key);
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    private function dotGet(array $array, string $key, mixed $default): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }
        return $array;
    }
}
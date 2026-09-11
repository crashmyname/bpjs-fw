<?php
namespace Bpjs\Framework\Helpers\Http;

class HttpResponse implements \ArrayAccess
{
    private int    $status;
    private string $body;
    private array  $headers;
    private ?array $decoded = null;   // ← FIX: property yang hilang

    public function __construct(int $status, string $body, array $headers = [])
    {
        $this->status  = $status;
        $this->body    = $body;
        $this->headers = $headers;
    }

    // ─── Status ──────────────────────────────────────────────────────────
    public function status(): int           { return $this->status; }
    public function ok(): bool              { return $this->status >= 200 && $this->status < 300; }
    public function successful(): bool      { return $this->ok(); }
    public function created(): bool         { return $this->status === 201; }
    public function noContent(): bool       { return $this->status === 204; }
    public function redirect(): bool        { return $this->status >= 300 && $this->status < 400; }
    public function unauthorized(): bool    { return $this->status === 401; }
    public function forbidden(): bool       { return $this->status === 403; }
    public function notFound(): bool        { return $this->status === 404; }
    public function clientError(): bool     { return $this->status >= 400 && $this->status < 500; }
    public function serverError(): bool     { return $this->status >= 500; }
    public function failed(): bool          { return $this->clientError() || $this->serverError(); }

    // ─── Body ────────────────────────────────────────────────────────────
    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($this->decoded === null) {
            $decoded       = json_decode($this->body, true);
            $this->decoded = is_array($decoded) ? $decoded : [];
        }

        if ($key === null) {
            return $this->decoded;
        }

        return $this->dotGet($this->decoded, $key, $default);
    }

    public function body(): string          { return $this->body; }
    public function object(): ?object       { return json_decode($this->body); }
    public function collect(): array        { return $this->json() ?? []; }

    // ─── Headers ─────────────────────────────────────────────────────────
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function headers(): array { return $this->headers; }

    // ─── Exception ───────────────────────────────────────────────────────
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

    public function throwIf(bool $condition): static
    {
        return $condition ? $this->throw() : $this;
    }

    public function throwUnlessStatus(int $status): static
    {
        return $this->status !== $status ? $this->throw() : $this;
    }

    // ─── Debug ───────────────────────────────────────────────────────────
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
        echo "  Body    : " . json_encode($this->json(), JSON_PRETTY_PRINT) . "\n\n";
        return $this;
    }

    // ─── ArrayAccess ─────────────────────────────────────────────────────
    public function offsetExists(mixed $offset): bool
    {
        return $this->json($offset, '__missing__') !== '__missing__';
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->json($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('HttpResponse bersifat immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('HttpResponse bersifat immutable.');
    }

    // ─── Internals ───────────────────────────────────────────────────────
    private function dotGet(array $array, string $key, mixed $default): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
                continue;
            }
            // support numeric index untuk list
            if (is_array($array) && ctype_digit($segment) && array_key_exists((int) $segment, $array)) {
                $array = $array[(int) $segment];
                continue;
            }
            return $default;
        }
        return $array;
    }
}
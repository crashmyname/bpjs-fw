<?php
namespace Bpjs\Framework\Helpers\Http;

class HttpFake
{
    private bool  $active   = false;
    private array $stubs    = [];
    private array $recorded = [];

    public function activate(array|null $stubOrMap = null): void
    {
        $this->active   = true;
        $this->recorded = [];

        if ($stubOrMap === null) {
            $this->stubs = [['pattern' => '*', 'status' => 200, 'body' => []]];
            return;
        }

        if (isset($stubOrMap['status']) || isset($stubOrMap['body'])) {
            $this->stubs = [[
                'pattern' => '*',
                'status'  => $stubOrMap['status'] ?? 200,
                'body'    => $stubOrMap['body'] ?? [],
            ]];
            return;
        }

        $this->stubs = [];
        foreach ($stubOrMap as $pattern => $stub) {
            $this->stubs[] = [
                'pattern' => $pattern,
                'status'  => $stub['status'] ?? 200,
                'body'    => $stub['body'] ?? [],
            ];
        }
    }

    public function deactivate(): void
    {
        $this->active   = false;
        $this->stubs    = [];
        $this->recorded = [];
    }

    public function isActive(): bool { return $this->active; }

    public function resolve(string $method, string $url): HttpResponse
    {
        $this->recorded[] = [
            'method' => $method,
            'url'    => $url,
            'time'   => microtime(true),
        ];

        foreach ($this->stubs as $stub) {
            if ($this->matches($url, $stub['pattern'])) {
                $body = is_array($stub['body'])
                    ? json_encode($stub['body'])
                    : (string) $stub['body'];
                return new HttpResponse($stub['status'], $body);
            }
        }

        return new HttpResponse(200, '{}');
    }

    // ─── Assertions ──────────────────────────────────────────────────────
    public function assertSent(string $urlPattern): void
    {
        if (empty($this->filter($urlPattern))) {
            throw new \RuntimeException(
                "Assert failed: tidak ada request ke '{$urlPattern}'."
            );
        }
    }

    public function assertNotSent(string $urlPattern): void
    {
        if (!empty($this->filter($urlPattern))) {
            throw new \RuntimeException(
                "Assert failed: ada request ke '{$urlPattern}'."
            );
        }
    }

    public function assertSentCount(int $count): void
    {
        $actual = count($this->recorded);
        if ($actual !== $count) {
            throw new \RuntimeException(
                "Assert failed: expected {$count} request, got {$actual}."
            );
        }
    }

    public function assertNothingSent(): void
    {
        $this->assertSentCount(0);
    }

    public function recorded(): array { return $this->recorded; }

    // ─── Internals ───────────────────────────────────────────────────────
    private function filter(string $pattern): array
    {
        return array_filter(
            $this->recorded,
            fn($r) => $this->matches($r['url'], $pattern)
        );
    }

    private function matches(string $url, string $pattern): bool
    {
        if ($pattern === '*') return true;

        $regex = '/^' . str_replace(
            ['\*', '\?'],
            ['.*', '.'],
            preg_quote($pattern, '/')
        ) . '$/';

        return (bool) preg_match($regex, $url);
    }
}
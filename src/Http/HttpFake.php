<?php
namespace Bpjs\Framework\Helpers\Http;

/**
 * Http::fake() — Mock HTTP responses untuk unit testing.
 *
 * Usage (mock semua request):
 *   Http::fake(['status' => 200, 'body' => ['ok' => true]]);
 *
 * Usage (mock per URL pattern):
 *   Http::fake([
 *       'https://api.example.com/users*' => ['status' => 200, 'body' => ['id' => 1]],
 *       'https://api.example.com/error*' => ['status' => 500, 'body' => ['msg' => 'oops']],
 *       '*'                              => ['status' => 200, 'body' => []],  // fallback
 *   ]);
 *
 * Setelah selesai test, panggil Http::resetFake() agar tidak mempengaruhi test lain.
 */
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
            // Default: semua request return 200 empty
            $this->stubs = [['pattern' => '*', 'status' => 200, 'body' => []]];
            return;
        }

        // Single stub (tidak ada 'pattern' key)
        if (isset($stubOrMap['status']) || isset($stubOrMap['body'])) {
            $this->stubs = [[
                'pattern' => '*',
                'status'  => $stubOrMap['status'] ?? 200,
                'body'    => $stubOrMap['body'] ?? [],
            ]];
            return;
        }

        // Map per URL pattern
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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function resolve(string $method, string $url): HttpResponse
    {
        $this->recorded[] = compact('method', 'url');

        foreach ($this->stubs as $stub) {
            if ($this->matches($url, $stub['pattern'])) {
                $body = is_array($stub['body'])
                    ? json_encode($stub['body'])
                    : (string) $stub['body'];
                return new HttpResponse($stub['status'], $body);
            }
        }

        // Tidak ada yang cocok → 200 kosong
        return new HttpResponse(200, '{}');
    }

    /**
     * Assert bahwa URL tertentu pernah di-request.
     */
    public function assertSent(string $urlPattern): void
    {
        $found = array_filter(
            $this->recorded,
            fn($r) => $this->matches($r['url'], $urlPattern)
        );

        if (empty($found)) {
            throw new \RuntimeException("Assert failed: No request sent matching '{$urlPattern}'.");
        }
    }

    /**
     * Assert bahwa TIDAK ada request ke URL tertentu.
     */
    public function assertNotSent(string $urlPattern): void
    {
        $found = array_filter(
            $this->recorded,
            fn($r) => $this->matches($r['url'], $urlPattern)
        );

        if (!empty($found)) {
            throw new \RuntimeException("Assert failed: Request was sent matching '{$urlPattern}'.");
        }
    }

    /**
     * Assert jumlah total request yang keluar.
     */
    public function assertSentCount(int $count): void
    {
        $actual = count($this->recorded);
        if ($actual !== $count) {
            throw new \RuntimeException("Assert failed: Expected {$count} request(s), got {$actual}.");
        }
    }

    public function assertNothingSent(): void
    {
        $this->assertSentCount(0);
    }

    public function recorded(): array
    {
        return $this->recorded;
    }

    private function matches(string $url, string $pattern): bool
    {
        if ($pattern === '*') {
            return true;
        }
        // Konversi wildcard (*) ke regex
        $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
        return (bool) preg_match($regex, $url);
    }
}
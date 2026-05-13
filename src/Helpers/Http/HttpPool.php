<?php
namespace Bpjs\Framework\Helpers\Http;

/**
 * Http::pool() — Concurrent HTTP requests menggunakan cURL multi handle.
 *
 * Semua request dieksekusi PARALEL (bukan sequential), sehingga total waktu
 * = request terlama (bukan jumlah semua waktu request).
 *
 * Usage:
 *   $responses = Http::pool(function (HttpPool $pool) {
 *       $pool->as('users')->get('https://api.example.com/users');
 *       $pool->as('posts')->get('https://api.example.com/posts');
 *       $pool->as('tags')->get('https://api.example.com/tags');
 *   });
 *
 *   $responses['users']->json();
 *   $responses['posts']->ok();
 */
class HttpPool
{
    /** @var array<string, array{method: string, url: string, data: mixed, headers: array, options: array}> */
    private array $requests  = [];
    private string|null $alias = null;
    private int   $timeout   = 30;
    private bool  $verifySsl = true;

    public function setTimeout(int $seconds): static
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function withoutVerifying(): static
    {
        $this->verifySsl = false;
        return $this;
    }

    /**
     * Beri nama / alias untuk request berikutnya.
     * Jika tidak dipanggil, index numerik akan digunakan.
     */
    public function as(string $alias): static
    {
        $this->alias = $alias;
        return $this;
    }

    public function get(string $url, array $headers = []): static
    {
        return $this->add('GET', $url, null, $headers);
    }

    public function post(string $url, mixed $data = [], array $headers = []): static
    {
        return $this->add('POST', $url, $data, $headers);
    }

    public function put(string $url, mixed $data = [], array $headers = []): static
    {
        return $this->add('PUT', $url, $data, $headers);
    }

    public function patch(string $url, mixed $data = [], array $headers = []): static
    {
        return $this->add('PATCH', $url, $data, $headers);
    }

    public function delete(string $url, mixed $data = [], array $headers = []): static
    {
        return $this->add('DELETE', $url, $data, $headers);
    }

    private function add(string $method, string $url, mixed $data, array $headers): static
    {
        $key = $this->alias ?? count($this->requests);
        $this->requests[$key] = compact('method', 'url', 'data', 'headers');
        $this->alias = null; // reset setelah dipakai
        return $this;
    }

    /**
     * Eksekusi semua request secara paralel.
     *
     * @return array<string|int, HttpResponse>
     */
    public function execute(): array
    {
        $multiHandle = curl_multi_init();
        $handles     = [];

        foreach ($this->requests as $key => $req) {
            $ch = $this->buildHandle($req);
            curl_multi_add_handle($multiHandle, $ch);
            $handles[$key] = $ch;
        }

        // Jalankan semua request secara paralel
        $running = null;
        do {
            $status = curl_multi_exec($multiHandle, $running);
            if ($running) {
                curl_multi_select($multiHandle);
            }
        } while ($running > 0 && $status === CURLM_OK);

        // Kumpulkan semua response
        $responses = [];
        foreach ($handles as $key => $ch) {
            $body     = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno    = curl_errno($ch);
            $error    = curl_error($ch);

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);

            if ($errno) {
                throw new \RuntimeException("Pool request '{$key}' cURL error ({$errno}): {$error}");
            }

            $responses[$key] = new HttpResponse($httpCode, $body ?? '');
        }

        curl_multi_close($multiHandle);

        return $responses;
    }

    private function buildHandle(array $req): \CurlHandle
    {
        $ch = curl_init();
        $headers = $req['headers'];

        curl_setopt_array($ch, [
            CURLOPT_URL            => $req['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $req['method'],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        if ($req['data'] !== null && $req['data'] !== []) {
            $json = json_encode($req['data']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        return $ch;
    }
}
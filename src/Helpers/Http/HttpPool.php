<?php
namespace Bpjs\Framework\Helpers\Http;

class HttpPool
{
    private array   $requests  = [];
    private ?string $alias     = null;
    private array   $headers   = [];   // ← pool-level headers
    private int     $timeout   = 30;
    private bool    $verifySsl = true;

    // ─── Pool-level config ───────────────────────────────────────────────
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

    /** Headers untuk SEMUA request di pool ini. */
    public function withHeaders(array $headers): static
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /** Shortcut Authorization header untuk semua request. */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withHeaders(['Authorization' => "{$type} {$token}"]);
    }

    public function as(string $alias): static
    {
        $this->alias = $alias;
        return $this;
    }

    // ─── Request methods ─────────────────────────────────────────────────
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

        // merge pool-level headers + per-request headers
        $merged = array_merge($this->headers, $headers);

        $this->requests[$key] = compact('method', 'url', 'data') + ['headers' => $merged];
        $this->alias = null;
        return $this;
    }

    // ─── Execute ─────────────────────────────────────────────────────────
    public function execute(): array
    {
        if (empty($this->requests)) {
            return [];
        }

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($this->requests as $key => $req) {
            $ch = $this->buildHandle($req);
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
        }

        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $responses = [];
        foreach ($handles as $key => $ch) {
            $body     = curl_multi_getcontent($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno    = curl_errno($ch);
            $error    = curl_error($ch);

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            if ($errno) {
                throw new \RuntimeException(
                    "Pool request '{$key}' cURL error ({$errno}): {$error}"
                );
            }

            $responses[$key] = new HttpResponse($httpCode, $body ?? '');
        }

        curl_multi_close($mh);
        return $responses;
    }

    private function buildHandle(array $req): \CurlHandle
    {
        $ch      = curl_init();
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
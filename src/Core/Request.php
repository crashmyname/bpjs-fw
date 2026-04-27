<?php
namespace Bpjs\Framework\Core;

use Bpjs\Framework\Helpers\Date;

class Request
{
    private array $data = [];
    private array $files = [];
    private ?int $rateLimit = null;

    public function __construct()
    {
        $this->data = array_merge($this->sanitize($_GET), $this->sanitize($_POST));
        $this->files = $this->normalizeFiles($_FILES);
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $json = json_decode(file_get_contents('php://input'), true);
            if (is_array($json)) {
                $this->data = array_merge($this->data, $this->sanitize($json));
            }
        }
        $this->data = $this->sanitizeKeys($this->data);
        $this->files = $this->sanitizeKeys($this->files);
    }

    public static function capture(): static
    {
        return new static();
    }

    /** -------------------------
     *  GENERAL SANITIZE HELPERS
     *  ------------------------*/
    private function sanitize(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);
                continue;
            }
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            $allowedTags = '<b><i><u><strong><em><a><p><br>';
            $clean = strip_tags($value, $allowedTags);
            $clean = preg_replace('/on\w+\s*=\s*["\'][^"\']*["\']/i', '', $clean);
            $clean = preg_replace('/expression\s*\(.*\)/i', '', $clean);
            $clean = preg_replace('/((javascript|vbscript|mocha|livescript)\s*:)/i', '', $clean);
            $clean = htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $sanitized[$key] = trim($clean);
        }

        return $sanitized;
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sanitizeKeys(array $array): array
    {
        $clean = [];
        foreach ($array as $key => $value) {
            $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $key);
            $clean[$safeKey] = is_array($value) ? $this->sanitizeKeys($value) : $value;
        }
        return $clean;
    }

    /** -------------------------
     *  FILE NORMALIZATION
     *  ------------------------*/
    private function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $info) {
            // kalau bukan array (file tunggal)
            if (!isset($info['name']) || !is_array($info['name'])) {
                $normalized[$field] = [
                    'name' => $info['name'] ?? '',
                    'type' => $info['type'] ?? '',
                    'tmp_name' => $info['tmp_name'] ?? '',
                    'error' => $info['error'] ?? 0,
                    'size' => $info['size'] ?? 0,
                ];
                continue;
            }

            // kalau nested
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

    private function buildNestedFiles($names, $types, $tmpNames, $errors, $sizes)
    {
        $result = [];

        foreach ($names as $key => $nameVal) {
            if (is_array($nameVal)) {
                // Rekursif kalau masih array
                $result[$key] = $this->buildNestedFiles(
                    $names[$key],
                    $types[$key],
                    $tmpNames[$key],
                    $errors[$key],
                    $sizes[$key]
                );
            } else {
                $result[$key] = [
                    'name' => htmlspecialchars($nameVal ?? '', ENT_QUOTES, 'UTF-8'),
                    'type' => htmlspecialchars($types[$key] ?? '', ENT_QUOTES, 'UTF-8'),
                    'tmp_name' => $tmpNames[$key] ?? '',
                    'error' => $errors[$key] ?? 0,
                    'size' => $sizes[$key] ?? 0,
                ];
            }
        }

        return $result;
    }

    /** -------------------------
     *  NESTED FILE ACCESSOR
     *  ------------------------*/
    public function getNestedFile(string $path)
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

        // jika hasil akhir bukan file (masih array besar)
        if (!is_array($cursor) || !isset($cursor['tmp_name'])) {
            return null;
        }

        return $cursor;
    }

    /** -------------------------
     *  FILE INFO HELPERS
     *  ------------------------*/
    public function file($key)
    {
        $file = $this->files[$key] ?? null;
        if (!$file) return null;

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        $sizeKB = $size / 1024;
        $sizeMB = $sizeKB / 1024;

        return [
            'name' => $file['name'] ?? '',
            'extension' => pathinfo($file['name'] ?? '', PATHINFO_EXTENSION),
            'mime_type' => $file['type'] ?? '',
            'size' => $size,
            'size_kb' => round($sizeKB, 2),
            'size_mb' => round($sizeMB, 2),
            'tmp_name' => $file['tmp_name'] ?? '',
            'error' => $file['error'] ?? 0,
            'uploaded_at' => Date::Now(),
        ];
    }

    public function hasFile($key): bool
    {
        return isset($this->files[$key]) && isset($this->files[$key]['tmp_name']) && $this->files[$key]['error'] === 0;
    }

    public function getClientOriginalExtension($key): string
    {
        $file = $this->files[$key] ?? null;
        return $file ? pathinfo($file['name'], PATHINFO_EXTENSION) : '';
    }

    public function getClientOriginalName($key): string
    {
        return $this->files[$key]['name'] ?? '';
    }

    public function getClientMimeType($key): string
    {
        return $this->files[$key]['type'] ?? '';
    }

    public function getSize($key): int
    {
        return $this->files[$key]['size'] ?? 0;
    }

    public function getPath($key): string
    {
        return $this->files[$key]['tmp_name'] ?? '';
    }

    /** -------------------------
     *  DATA ACCESS
     *  ------------------------*/
    public function all(): array
    {
        return array_merge($this->data, $this->files);
    }

    public function input(string $key, $default = null)
    {
        $value = $this->data[$key] ?? $_REQUEST[$key] ?? $default;

        if (is_string($value)) {
            $decodedValue = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $json = json_decode($decodedValue, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }
        if (is_array($value)) {
            return $value;
        }

        return $value;
    }

    public function header(string $key, $default = null)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $_SERVER[$key] ?? $default;
    }

    public function headers(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $formatted = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$formatted] = $value;
            }
        }

        return $headers;
    }

    public function expectsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return str_contains($accept, 'application/json');
    }

    public function isJson(): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        return str_contains($contentType, 'application/json');
    }

    public function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public function get($key)
    {
        return $this->data[$key] ?? $this->files[$key] ?? null;
    }

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

    /** -------------------------
     *  UTILS
     *  ------------------------*/
    public static function isAjax(): bool
    {
        $xRequested = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        
        return strtolower($xRequested) === 'xmlhttprequest'
            || str_contains($accept, 'application/json');
    }

    public function setRateLimit(int $limit): void
    {
        $this->rateLimit = $limit;
    }

    public function getRateLimit(): ?int
    {
        return $this->rateLimit;
    }

    public function __get($key)
    {
        return $this->get($key);
    }
}

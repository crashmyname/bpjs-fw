<?php
namespace Bpjs\Framework\Helpers;

class CORSMiddleware
{
    public static function handle()
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Jika request tanpa Origin (misal dari backend sendiri), jangan blok
        if (empty($origin)) {
            return;
        }

        // Jika method OPTIONS (preflight request), langsung beri izin
        if ($method === 'OPTIONS') {
            self::setCorsHeaders($origin);
            header("HTTP/1.1 200 OK");
            exit();
        }

        // Set header CORS utama
        self::setCorsHeaders($origin);
    }

    private static function setCorsHeaders($origin)
    {
        $allowAll = config('cors.allow_all_origins');
        $allowedOrigins = config('cors.allowed_origins');
        $allowCreds = config('cors.allowed_credentials');
        $allowedMethods = implode(',', config('cors.allowed_methods'));
        $allowedHeaders = implode(',', config('cors.allowed_headers'));

        // Tentukan Origin yang diizinkan
        if ($allowAll) {
            if ($allowCreds) {
                // Tidak boleh wildcard jika credentials = true
                header("Access-Control-Allow-Origin: $origin");
                header("Access-Control-Allow-Credentials: true");
            } else {
                header("Access-Control-Allow-Origin: *");
            }
        } elseif (in_array($origin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: $origin");
            if ($allowCreds) {
                header("Access-Control-Allow-Credentials: true");
            }
        } else {
            header("HTTP/1.1 403 Forbidden");
            echo json_encode(['error' => 'Origin not allowed']);
            exit();
        }

        // Izinkan metode dan header
        header("Access-Control-Allow-Methods: $allowedMethods");
        header("Access-Control-Allow-Headers: $allowedHeaders");
        header("Access-Control-Max-Age: 86400");
    }
}

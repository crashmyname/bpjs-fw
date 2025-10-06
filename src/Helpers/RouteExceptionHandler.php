<?php
namespace Bpjs\Framework\Helpers;

use Bpjs\Core\Request;

class RouteExceptionHandler
{
    public static function handle($exception)
    {
        if (env('APP_DEBUG') == 'false') {
            if (Request::isAjax() || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
                header('Content-Type: application/json', true, 500);
                echo json_encode([
                    'statusCode' => 500,
                    'error'      => 'Internal Server Error'
                ]);
            } else {
                return View::error(500);
            }
            exit;
        }
        // Cek apakah pesan kesalahan mengandung 'Class not found'
        if (strpos($exception->getMessage(), 'Class') !== false) {
            http_response_code(404);
            $message = "Controller not found: " . ($exception->getMessage());
            // include __DIR__ . '/../../app/Handle/errors/controller_not_found.php'; // Ganti dengan view error sesuai kebutuhan
            include BPJS_BASE_PATH . '/app/handle/errors/page_error.php'; // Ganti dengan view error sesuai kebutuhan
            exit();
        }

        // Jika bukan kesalahan controller, gunakan metode lain jika perlu
        http_response_code(500);
        include BPJS_BASE_PATH . '/app/handle/errors/500.php'; // Ganti dengan view error umum
        exit();
    }
}
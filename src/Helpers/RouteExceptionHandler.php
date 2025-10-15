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

        if (strpos($exception->getMessage(), 'Class') !== false) {
            http_response_code(404);
            $message = "Controller not found: " . ($exception->getMessage());
            include BPJS_BASE_PATH . '/app/handle/errors/page_error.php';
            exit();
        }

        http_response_code(500);
        include BPJS_BASE_PATH . '/app/handle/errors/500.php';
        exit();
    }
}
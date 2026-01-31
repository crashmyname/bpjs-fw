<?php
namespace Bpjs\Framework\Helpers;

use Bpjs\Core\Request;

class CSRFToken {
    public static function generateToken() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $csrf = "<input type='hidden' value='{$token}' name='csrf_token'>";
        return $csrf;
    }

    public static function validateToken($token) {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
            unset($_SESSION['csrf_token']);
            return true;
        }
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
        return vd('CSRF Token tidak valid');
    }

    public static function createToken()
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['token'] = $token;
        return $token;
    }
}
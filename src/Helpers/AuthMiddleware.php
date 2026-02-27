<?php
namespace Bpjs\Framework\Helpers;
use Bpjs\Framework\Helpers\View;

class AuthMiddleware
{
    public function handle() {
        if (!$this->checkLogin()) {
            // include __DIR__ . '/../../app/Handle/errors/401.php';
            return $this->unauthorized();
        }
        $now = time();

        $idleTimeout = env('SESSION_IDLE_TIMEOUT', 480) * 60;

        if (isset($_SESSION['last_activity']) 
            && ($now - $_SESSION['last_activity']) > $idleTimeout) {

            session_unset();
            session_destroy();

            return $this->unauthorized();
        }

        $_SESSION['last_activity'] = $now;

        return true;
    }

    public function checkLogin() {
        if (!Session::has('user')) {
            if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'){
                http_response_code(401); // Unauthorized
                exit;
            } elseif (!empty($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true'){
                header('HX-Redirect: '.base_url().'login');
                http_response_code(200);
                exit;
            } else {
                return redirect('login');
            }
        }

        $session_lifetime = env('SESSION_LIFETIME')*60;
        $current_time = time();
        
        if (isset($_SESSION['login_time']) && ($current_time - $_SESSION['login_time']) > $session_lifetime) {
            session_unset();
            session_destroy();
            if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'){
                http_response_code(401); // Unauthorized
                exit;
            } elseif (!empty($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true'){
                header('HX-Redirect: '.base_url().'login');
                http_response_code(200);
                exit;
            } else {
                return redirect('login');
            }
        }
        
        $_SESSION['login_time'] = $current_time;
        return true;
    }

    private function unauthorized()
    {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {

            http_response_code(401);
            exit;

        } elseif (!empty($_SERVER['HTTP_HX_REQUEST']) 
            && $_SERVER['HTTP_HX_REQUEST'] === 'true') {

            header('HX-Redirect: '.base_url().'login');
            http_response_code(200);
            exit;

        } else {
            View::redirectTo('login');
            exit;
        }
    }
}
<?php

namespace Bpjs\Framework\Helpers;

use App\Models\User;
use Bpjs\Framework\Core\Request;
use Bpjs\Framework\Helpers\View;
use Bpjs\Framework\Helpers\Session;
use Middlewares\SessionMiddleware;
use Middlewares\Throttle;

class Auth
{
    public static function attempt(array $credentials): bool
    {
        try {
            $throttleEnabled = config('auth.throttle.enabled', false);
            $throttleKey = 'login_attempt_' . md5(
                ($credentials['identifier'] ?? '') .
                ($_SERVER['HTTP_USER_AGENT'] ?? '') .
                ($_SERVER['REMOTE_ADDR'] ?? '')
            );
            if ($throttleEnabled && Throttle::tooManyAttempts($throttleKey)) {
                Session::flash('error', 'Terlalu banyak percobaan login. Coba lagi nanti.');
                return false;
            }
            $fields = config('auth.login_fields', ['username']);
            $user = null;

            foreach ($fields as $field) {
                $row = User::query()
                    ->where($field, '=', $credentials['identifier'] ?? '')
                    ->first();

                if ($row) {
                    $user = $row instanceof User ? $row : new User((array)$row);
                    break;
                }
            }

            if (!$user) {
                if ($throttleEnabled) Throttle::increment($throttleKey);
                return false;
            }

            $passwordAlgo = config('auth.password_hash', 'bcrypt');
            $hash = $user->password ?? '';
            $isValid = ($passwordAlgo === 'bcrypt' && $hash !== '')
                ? password_verify($credentials['password'] ?? '', $hash)
                : false;

            if (!$isValid) {
                if ($throttleEnabled) Throttle::increment($throttleKey);
                return false;
            }

            if ($throttleEnabled) Throttle::clear($throttleKey);
            Session::set(config('auth.session_key', 'user'), $user->toArray());
            if (config('auth.regenerate_session', true)) {
                SessionMiddleware::regenerate();
            }
            if (config('auth.remember_me') && !empty($credentials['remember'])) {
                self::setRememberCookie((int)$user->id);
            }

            return true;
        } catch (\Throwable $e) {
            error_log("Auth Error: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

            if (env('APP_DEBUG') === 'false' || env('APP_DEBUG') === false) {
                if (Request::isAjax() ||
                    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
                    header('Content-Type: application/json', true, 500);
                    echo json_encode([
                        'statusCode' => 500,
                        'error'      => 'Internal Server Error',
                    ]);
                } else {
                    echo View::error(500);
                }
                exit;
            }
            return false;
        }
    }

    public static function user(): ?User
    {
        $data = Session::get(config('auth.session_key', 'user'));
        if (!$data) return null;

        return $data instanceof User ? $data : new User((array)$data);
    }

    public static function check(): bool
    {
        if (self::user()) return true;
        if (!empty($_COOKIE['remember_user']) && !empty($_COOKIE['remember_token'])) {
            $userId = (int)$_COOKIE['remember_user'];
            $token  = $_COOKIE['remember_token'];

            $user = User::find($userId);

            if ($user && self::verifyRememberToken($user, $token)) {
                Session::set(config('auth.session_key', 'user'), $user->toArray());

                if (config('auth.regenerate_session', true)) {
                    SessionMiddleware::regenerate();
                }
                return true;
            }

            if ($user && method_exists($user, 'save') && !empty($user->remember_token)) {
                $user->remember_token = null;
                $user->save();
            }
            self::clearRememberCookie();
        }

        return false;
    }

    public static function role(string|array $role): bool
    {
        $user = self::user();
        if (!$user || !isset($user->role)) return false;

        return is_array($role)
            ? in_array($user->role, $role, true)
            : $user->role === $role;
    }

    public static function id(): ?int
    {
        return self::user()?->id;
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user && method_exists($user, 'save') && !empty($user->remember_token)) {
            $user->remember_token = null;
            $user->save();
        }

        Session::delete(config('auth.session_key', 'user'));
        self::clearRememberCookie();

        if (config('auth.regenerate_session', true)) {
            SessionMiddleware::regenerate();
        }
        Session::destroy();
    }

    // ---------- Remember Me Helpers ----------

    private static function setRememberCookie(int $userId): void
    {
        $days  = (int) config('auth.remember_days', 7);
        $token = bin2hex(random_bytes(32));

        $user = User::find($userId);
        if ($user) {
            $attrs = method_exists($user, 'toArray') ? $user->toArray() : [];
            if (array_key_exists('remember_token', $attrs)) {
                $user->remember_token = hash('sha256', $token);
                if (method_exists($user, 'save')) {
                    $user->save();
                }
            }
        }

        $params = [
            'expires'  => time() + ($days * 86400),
            'path'     => '/',
            'secure'   => (bool) config('session.secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie('remember_user',  (string)$userId, $params);
        setcookie('remember_token', $token,          $params);
    }

    private static function verifyRememberToken(User $user, string $token): bool
    {
        if (empty($user->remember_token)) return false;
        return hash_equals($user->remember_token, hash('sha256', $token));
    }

    private static function clearRememberCookie(): void
    {
        $params = [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => (bool) config('session.secure', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie('remember_user',  '', $params);
        setcookie('remember_token', '', $params);
    }
}
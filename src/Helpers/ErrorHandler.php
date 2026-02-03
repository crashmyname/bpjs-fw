<?php
namespace Bpjs\Framework\Helpers;

use Throwable;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Handler\JsonResponseHandler;
use Bpjs\Framework\Helpers\View;

class ErrorHandler
{
    protected static $additionalData = [];

    public static function register()
    {
        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleException(Throwable $exception)
    {
        self::clearOutputBuffers();
        self::logError(
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );

        if (env('APP_DEBUG') === 'true') {
            self::renderWithWhoops($exception);
        } else {
            self::renderErrorPage($exception->getMessage());
        }
    }

    public static function handleError($errno, $errstr, $errfile, $errline)
    {
        self::clearOutputBuffers();
        self::logError($errstr, $errfile, $errline);

        if (env('APP_DEBUG') === 'true') {
            $exception = new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            self::renderWithWhoops($exception);
        } else {
            self::renderErrorPage($errstr);
        }
    }

    public static function handleShutdown()
    {
        $error = error_get_last();

        if (!$error) {
            return;
        }

        if (!in_array($error['type'], [
            E_ERROR,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_RECOVERABLE_ERROR
        ])) {
            return;
        }

        self::clearOutputBuffers();

        self::logError(
            $error['message'],
            $error['file'],
            $error['line']
        );

        $exception = new \ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        );

        if (env('APP_DEBUG') === 'true') {
            self::renderWithWhoops($exception);
        }

        self::renderErrorPage($error['message']);
    }

    /** Simpan ke log file */
    public static function logError($message, $file, $line, $trace = null)
    {
        $logDir = BPJS_BASE_PATH . '/logs';
        $logFile = $logDir . '/error.log';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $log = "[$timestamp] [Error] $message in $file on line $line";
        if ($trace) {
            $log .= "\nTrace: $trace";
        }
        $log .= "\n-------------------------------------------\n";

        file_put_contents($logFile, $log, FILE_APPEND);
    }

    /** Render error dengan Whoops */
    protected static function renderWithWhoops(Throwable $exception)
    {
        $whoops = new Run();

        if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
            $whoops->pushHandler(new JsonResponseHandler());
        } else {
            $pageHandler = new PrettyPageHandler();
            $pageHandler->setPageTitle("Terjadi Kesalahan di Sistem");
            $whoops->pushHandler($pageHandler);
        }

        $whoops->handleException($exception);
        exit;
    }

    /** Render halaman error biasa (production) */
    public static function renderErrorPage($message)
    {
        http_response_code(500);
        $isAjax = (
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        ) || 
        (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 500,
                'error'  => 'Internal Server Error',
                'message' => env('APP_DEBUG') === 'true' ? $message : 'Something went wrong on our server.',
            ], JSON_PRETTY_PRINT);
            exit;
        }
        echo View::error(500);
        exit();
    }

    /** Menambahkan data tambahan jika ingin tampilkan di error page custom */
    public static function addAdditionalData($key, $value)
    {
        self::$additionalData[$key] = $value;
    }

    private static function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
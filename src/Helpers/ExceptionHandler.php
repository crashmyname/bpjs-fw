<?php
namespace Bpjs\Framework\Helpers;

class ExceptionHandler
{
    public static function handle($exception)
    {
        // Tangkap pesan error dan kirim ke renderErrorPage
        $message = $exception->getMessage();
        $file = $exception->getFile();
        $line = $exception->getLine();

        // Coba tampilkan error dalam format yang lebih user-friendly
        if ($message === 'Invalid CSRF token') {
            ErrorHandler::handleException($exception);
        } else {
            ErrorHandler::handleException($exception);
        }
    }
}
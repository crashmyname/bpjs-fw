<?php
namespace Bpjs\Framework\Helpers;

class ExceptionHandler
{
    public static function handle($exception)
    {
        $message = $exception->getMessage();
        $file = $exception->getFile();
        $line = $exception->getLine();

        if ($message === 'Invalid CSRF token') {
            ErrorHandler::handleException($exception);
        } else {
            ErrorHandler::handleException($exception);
        }
    }
}
<?php

namespace Bpjs\Framework\Core;
use Bpjs\Framework\Helpers\Response;

class Environment
{
    public static function validate(): void
    {
        self::validateEnvFile();
        self::validateAppKey();
    }

    protected static function validateEnvFile(): void
    {
        if (!file_exists(BPJS_BASE_PATH . '/.env')) {
            self::renderError(
                '.env file not found.',
                'Please create your environment file first.'
            );
        }
    }

    protected static function validateAppKey(): void
    {
        if (!validate_app_key(env('APP_KEY'))) {

            if (Request::isAjax()) {
                Response::json([
                    'status' => 500,
                    'message' => 'Invalid or missing APP_KEY.'
                ], 500);
            }

            self::renderError(
                'Invalid or missing APP_KEY.',
                'Please run:',
                'php bpjs generate:key'
            );
        }
    }

    protected static function renderError(
        string $title,
        string $message,
        ?string $command = null
    ): void {
        die("
        <html>
            <head>
                <title>{$title}</title>
                <style>
                    body {
                        font-family: Arial;
                        background: #f8fafc;
                        padding: 40px;
                    }
                    .box {
                        max-width:700px;
                        margin:auto;
                        background:white;
                        border-radius:10px;
                        padding:30px;
                        box-shadow:0 0 20px rgba(0,0,0,.08);
                    }
                    h1 { color:#dc2626; }
                    code {
                        background:#eee;
                        padding:8px 12px;
                        border-radius:6px;
                        display:inline-block;
                        margin-top:10px;
                    }
                </style>
            </head>
            <body>
                <div class='box'>
                    <h1>{$title}</h1>
                    <p>{$message}</p>
                    " . ($command ? "<code>{$command}</code>" : "") . "
                </div>
            </body>
        </html>
        ");
    }
}
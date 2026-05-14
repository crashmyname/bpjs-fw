<?php
namespace Bpjs\Framework\Helpers\Http;

class HttpException extends \RuntimeException
{
    private $responseBody;
    public function __construct(
        string           $message,
        int              $statusCode,
        $responseBody = null,
    ) {
        parent::__construct($message, $statusCode);
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->getCode();
    }

    public function getResponseBody(): mixed
    {
        return $this->responseBody;
    }

    public function isClientError(): bool
    {
        return $this->getCode() >= 400 && $this->getCode() < 500;
    }

    public function isServerError(): bool
    {
        return $this->getCode() >= 500;
    }
}
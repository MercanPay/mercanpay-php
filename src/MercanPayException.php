<?php

declare(strict_types=1);

namespace MercanPay;

/** Thrown for non-2xx API responses. getErrorCode() returns e.g. "insufficient_funds". */
final class MercanPayException extends \RuntimeException
{
    public function __construct(private int $httpStatus, private string $errorCode, string $message)
    {
        parent::__construct("[{$httpStatus} {$errorCode}] {$message}", $httpStatus);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}

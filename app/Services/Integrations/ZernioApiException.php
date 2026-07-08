<?php

namespace App\Services\Integrations;

use RuntimeException;

class ZernioApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function isStaleProfileError(): bool
    {
        if ($this->httpStatus !== 404) {
            return false;
        }

        $message = strtolower($this->getMessage());

        return str_contains($message, 'profile not found')
            || str_contains($message, 'access denied');
    }

    public function isDuplicateProfileError(): bool
    {
        if ($this->httpStatus !== 400) {
            return false;
        }

        $message = strtolower($this->getMessage());

        return str_contains($message, 'already exists')
            || str_contains($message, 'duplicate');
    }
}

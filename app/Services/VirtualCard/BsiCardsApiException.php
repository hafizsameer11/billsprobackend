<?php

namespace App\Services\VirtualCard;

use RuntimeException;

class BsiCardsApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 500,
        private readonly ?array $context = null
    ) {
        parent::__construct($message);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getContext(): ?array
    {
        return $this->context;
    }
}

<?php

namespace App\Integrations\Idcsmart;

use RuntimeException;

class FinanceException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?int $applicationStatus = null,
        private readonly array $safeContext = [],
        private readonly bool $outcomeAmbiguous = false,
    ) {
        parent::__construct($message);
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function applicationStatus(): ?int
    {
        return $this->applicationStatus;
    }

    public function safeContext(): array
    {
        return $this->safeContext;
    }

    public function outcomeIsAmbiguous(): bool
    {
        return $this->outcomeAmbiguous;
    }
}

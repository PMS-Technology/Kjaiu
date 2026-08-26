<?php

namespace App\Integrations\Idcsmart;

use JsonSerializable;

class FinanceResponse implements JsonSerializable
{
    public function __construct(
        public readonly int $status,
        public readonly string $message,
        public readonly array $data,
        private readonly array $envelope,
    ) {}

    public function envelope(): array
    {
        return $this->envelope;
    }

    public function jsonSerialize(): array
    {
        return $this->envelope;
    }
}

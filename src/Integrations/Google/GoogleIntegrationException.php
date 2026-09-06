<?php

declare(strict_types=1);

final class GoogleIntegrationException extends RuntimeException
{
    public function __construct(private readonly string $field, string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function validationErrors(): array
    {
        return [$this->field => $this->getMessage()];
    }
}

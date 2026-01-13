<?php

namespace Eric0324\AIDBQuery\Exceptions;

class LLMException extends SmartQueryException
{
    protected string $driver = '';

    protected ?array $response = null;

    public static function connectionFailed(string $driver, string $message): self
    {
        $exception = new self("Failed to connect to {$driver}: {$message}");
        $exception->driver = $driver;

        return $exception;
    }

    public static function invalidResponse(string $driver, string $message, ?array $response = null): self
    {
        $exception = new self("Invalid response from {$driver}: {$message}");
        $exception->driver = $driver;
        $exception->response = $response;

        return $exception;
    }

    public static function apiError(string $driver, string $message, ?array $response = null): self
    {
        $exception = new self("API error from {$driver}: {$message}");
        $exception->driver = $driver;
        $exception->response = $response;

        return $exception;
    }

    public static function configurationError(string $driver, string $message): self
    {
        $exception = new self("Configuration error for {$driver}: {$message}");
        $exception->driver = $driver;

        return $exception;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getResponse(): ?array
    {
        return $this->response;
    }
}

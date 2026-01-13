<?php

namespace Eric0324\AIDBQuery\Exceptions;

class SchemaException extends SmartQueryException
{
    protected string $table = '';

    public static function tableNotFound(string $table): self
    {
        $exception = new self("Table '{$table}' not found in database");
        $exception->table = $table;

        return $exception;
    }

    public static function connectionFailed(string $message): self
    {
        return new self("Failed to retrieve schema: {$message}");
    }

    public static function indexNotFound(): self
    {
        return new self('Schema index not found. Run `php artisan smart-query:index` to build the index.');
    }

    public static function indexCorrupted(string $message): self
    {
        return new self("Schema index is corrupted: {$message}");
    }

    public function getTable(): string
    {
        return $this->table;
    }
}

<?php

namespace Eric0324\AIDBQuery\Exceptions;

class UnsafeQueryException extends SmartQueryException
{
    protected string $sql = '';

    protected string $violation = '';

    public static function nonSelectQuery(string $sql): self
    {
        $exception = new self('Only SELECT queries are allowed');
        $exception->sql = $sql;
        $exception->violation = 'non_select';

        return $exception;
    }

    public static function forbiddenTable(string $sql, string $table): self
    {
        $exception = new self("Access to table '{$table}' is forbidden");
        $exception->sql = $sql;
        $exception->violation = 'forbidden_table';

        return $exception;
    }

    public static function dangerousPattern(string $sql, string $pattern): self
    {
        $exception = new self("Dangerous SQL pattern detected: {$pattern}");
        $exception->sql = $sql;
        $exception->violation = 'dangerous_pattern';

        return $exception;
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getViolation(): string
    {
        return $this->violation;
    }
}

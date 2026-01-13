<?php

namespace Eric0324\AIDBQuery\Exceptions;

use Exception;

class SmartQueryException extends Exception
{
    protected string $context = '';

    public function setContext(string $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function getContext(): string
    {
        return $this->context;
    }
}

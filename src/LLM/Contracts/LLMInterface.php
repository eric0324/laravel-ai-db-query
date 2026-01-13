<?php

namespace Eric0324\AIDBQuery\LLM\Contracts;

interface LLMInterface
{
    /**
     * Send a completion request to the LLM.
     *
     * @param  string  $system  The system prompt
     * @param  string  $prompt  The user prompt
     * @return string The LLM response
     */
    public function complete(string $system, string $prompt): string;

    /**
     * Get the name of the LLM driver.
     */
    public function getName(): string;
}

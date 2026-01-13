<?php

namespace Eric0324\AIDBQuery\LLM;

use Eric0324\AIDBQuery\Exceptions\LLMException;
use Eric0324\AIDBQuery\LLM\Contracts\LLMInterface;
use Eric0324\AIDBQuery\LLM\Drivers\AnthropicDriver;
use Eric0324\AIDBQuery\LLM\Drivers\OllamaDriver;
use Eric0324\AIDBQuery\LLM\Drivers\OpenAIDriver;
use Illuminate\Support\Manager;

class LLMManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('smart-query.llm.default', 'openai');
    }

    /**
     * Create the OpenAI driver.
     */
    protected function createOpenaiDriver(): LLMInterface
    {
        $config = $this->config->get('smart-query.llm.drivers.openai', []);

        if (empty($config['api_key'])) {
            throw LLMException::configurationError('openai', 'API key is required');
        }

        return new OpenAIDriver($config);
    }

    /**
     * Create the Anthropic driver.
     */
    protected function createAnthropicDriver(): LLMInterface
    {
        $config = $this->config->get('smart-query.llm.drivers.anthropic', []);

        if (empty($config['api_key'])) {
            throw LLMException::configurationError('anthropic', 'API key is required');
        }

        return new AnthropicDriver($config);
    }

    /**
     * Create the Ollama driver.
     */
    protected function createOllamaDriver(): LLMInterface
    {
        $config = $this->config->get('smart-query.llm.drivers.ollama', []);

        return new OllamaDriver($config);
    }

    /**
     * Get the LLM driver instance.
     */
    public function driver($driver = null): LLMInterface
    {
        return parent::driver($driver);
    }
}

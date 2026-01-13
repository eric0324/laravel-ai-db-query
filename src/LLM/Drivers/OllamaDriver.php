<?php

namespace Eric0324\AIDBQuery\LLM\Drivers;

use Eric0324\AIDBQuery\Exceptions\LLMException;
use Eric0324\AIDBQuery\LLM\Contracts\LLMInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OllamaDriver implements LLMInterface
{
    protected Client $client;

    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new Client([
            'base_uri' => rtrim($config['base_url'] ?? 'http://localhost:11434', '/') . '/',
            'timeout' => $config['timeout'] ?? 120,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function complete(string $system, string $prompt): string
    {
        try {
            $response = $this->client->post('api/chat', [
                'json' => [
                    'model' => $this->config['model'] ?? 'llama3.2',
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'stream' => false,
                    'options' => [
                        'temperature' => 0,
                    ],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (! isset($body['message']['content'])) {
                throw LLMException::invalidResponse(
                    'ollama',
                    'Missing content in response',
                    $body
                );
            }

            return trim($body['message']['content']);
        } catch (GuzzleException $e) {
            throw LLMException::connectionFailed('ollama', $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'ollama';
    }
}

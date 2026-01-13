<?php

namespace Eric0324\AIDBQuery\LLM\Drivers;

use Eric0324\AIDBQuery\Exceptions\LLMException;
use Eric0324\AIDBQuery\LLM\Contracts\LLMInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class AnthropicDriver implements LLMInterface
{
    protected Client $client;

    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new Client([
            'base_uri' => rtrim($config['base_url'] ?? 'https://api.anthropic.com/v1', '/') . '/',
            'timeout' => $config['timeout'] ?? 30,
            'headers' => [
                'x-api-key' => $config['api_key'],
                'Content-Type' => 'application/json',
                'anthropic-version' => '2023-06-01',
            ],
        ]);
    }

    public function complete(string $system, string $prompt): string
    {
        try {
            $response = $this->client->post('messages', [
                'json' => [
                    'model' => $this->config['model'] ?? 'claude-sonnet-4-20250514',
                    'max_tokens' => 1000,
                    'system' => $system,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (! isset($body['content'][0]['text'])) {
                throw LLMException::invalidResponse(
                    'anthropic',
                    'Missing text in response',
                    $body
                );
            }

            return trim($body['content'][0]['text']);
        } catch (GuzzleException $e) {
            throw LLMException::connectionFailed('anthropic', $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'anthropic';
    }
}

<?php

namespace Eric0324\AIDBQuery\LLM\Drivers;

use Eric0324\AIDBQuery\Exceptions\LLMException;
use Eric0324\AIDBQuery\LLM\Contracts\LLMInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class OpenAIDriver implements LLMInterface
{
    protected Client $client;

    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new Client([
            'base_uri' => rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/',
            'timeout' => $config['timeout'] ?? 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $config['api_key'],
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function complete(string $system, string $prompt): string
    {
        try {
            $response = $this->client->post('chat/completions', [
                'json' => [
                    'model' => $this->config['model'] ?? 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0,
                    'max_tokens' => 1000,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (! isset($body['choices'][0]['message']['content'])) {
                throw LLMException::invalidResponse(
                    'openai',
                    'Missing content in response',
                    $body
                );
            }

            return trim($body['choices'][0]['message']['content']);
        } catch (GuzzleException $e) {
            throw LLMException::connectionFailed('openai', $e->getMessage());
        }
    }

    public function getName(): string
    {
        return 'openai';
    }
}

<?php

namespace Eric0324\AIDBQuery\LLM;

use Eric0324\AIDBQuery\Exceptions\LLMException;
use Eric0324\AIDBQuery\LLM\Contracts\EmbeddingInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class EmbeddingService implements EmbeddingInterface
{
    protected Client $client;

    protected array $config;

    protected string $model;

    protected int $dimension;

    /**
     * Model dimensions mapping.
     */
    protected const MODEL_DIMENSIONS = [
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
    ];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->model = $config['model'] ?? 'text-embedding-3-small';
        $this->dimension = self::MODEL_DIMENSIONS[$this->model] ?? 1536;

        $apiKey = $config['api_key'] ?? config('smart-query.llm.drivers.openai.api_key');

        if (empty($apiKey)) {
            throw LLMException::configurationError('embedding', 'OpenAI API key is required for embeddings');
        }

        $this->client = new Client([
            'base_uri' => rtrim($config['base_url'] ?? 'https://api.openai.com/v1', '/') . '/',
            'timeout' => $config['timeout'] ?? 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Generate embeddings for the given texts.
     *
     * @param  array<string>  $texts  The texts to embed
     * @return array<array<float>> The embedding vectors
     */
    public function embed(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        try {
            $response = $this->client->post('embeddings', [
                'json' => [
                    'model' => $this->model,
                    'input' => $texts,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (! isset($body['data'])) {
                throw LLMException::invalidResponse('embedding', 'Missing data in response', $body);
            }

            // Sort by index to ensure correct order
            $embeddings = $body['data'];
            usort($embeddings, fn ($a, $b) => $a['index'] <=> $b['index']);

            return array_map(fn ($item) => $item['embedding'], $embeddings);
        } catch (GuzzleException $e) {
            throw LLMException::connectionFailed('embedding', $e->getMessage());
        }
    }

    /**
     * Generate embedding for a single text.
     *
     * @return array<float> The embedding vector
     */
    public function embedSingle(string $text): array
    {
        $result = $this->embed([$text]);

        return $result[0] ?? [];
    }

    /**
     * Get the dimension of the embedding vectors.
     */
    public function getDimension(): int
    {
        return $this->dimension;
    }

    /**
     * Get the name of the embedding provider.
     */
    public function getName(): string
    {
        return 'openai';
    }

    /**
     * Get the model name.
     */
    public function getModel(): string
    {
        return $this->model;
    }
}

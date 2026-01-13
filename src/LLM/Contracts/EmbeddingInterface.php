<?php

namespace Eric0324\AIDBQuery\LLM\Contracts;

interface EmbeddingInterface
{
    /**
     * Generate embeddings for the given texts.
     *
     * @param  array<string>  $texts  The texts to embed
     * @return array<array<float>> The embedding vectors
     */
    public function embed(array $texts): array;

    /**
     * Get the dimension of the embedding vectors.
     */
    public function getDimension(): int;

    /**
     * Get the name of the embedding provider.
     */
    public function getName(): string;
}

<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Model;

/**
 * Embedding response
 *
 * @api This class is part of the public API
 */
class EmbeddingResponse extends LlmResponse
{
    public function __construct(
        private array $embeddings,
        private ?string $model = null,
        ?TokenUsage $usage = null,
        ?array $metadata = null
    ) {
        parent::__construct(
            '',  // Embeddings have no text content
            $usage,
            $metadata,
            'complete'
        );
    }

    /**
     * Get all embeddings
     *
     * @return array Array of embedding vectors
     */
    public function getEmbeddings(): array
    {
        return $this->embeddings;
    }

    /**
     * Get single embedding (if only one input)
     *
     * @return array Single embedding vector
     * @throws \RuntimeException If multiple embeddings exist
     */
    public function getEmbedding(): array
    {
        if (count($this->embeddings) !== 1) {
            throw new \RuntimeException(
                'Multiple embeddings exist, use getEmbeddings() instead'
            );
        }

        return $this->embeddings[0];
    }

    /**
     * Get embedding dimensions
     */
    public function getDimensions(): int
    {
        if (empty($this->embeddings)) {
            return 0;
        }

        return count($this->embeddings[0]);
    }

    /**
     * Get model used for embeddings
     */
    public function getModel(): ?string
    {
        return $this->model;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'embeddings' => $this->embeddings,
            'model' => $this->model,
            'dimensions' => $this->getDimensions()
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\DTO;

/**
 * DTO for detected provider information from endpoint URL.
 */
final readonly class DetectedProvider
{
    /**
     * @param string $adapterType The adapter type identifier (openai, anthropic, gemini, etc.)
     * @param string $suggestedName Suggested display name for the provider
     * @param string $endpoint Normalized endpoint URL
     * @param float $confidence Detection confidence (0.0 to 1.0)
     * @param array<string, mixed> $metadata Additional provider-specific metadata
     */
    public function __construct(
        public string $adapterType,
        public string $suggestedName,
        public string $endpoint,
        public float $confidence = 1.0,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'adapterType' => $this->adapterType,
            'suggestedName' => $this->suggestedName,
            'endpoint' => $this->endpoint,
            'confidence' => $this->confidence,
            'metadata' => $this->metadata,
        ];
    }
}

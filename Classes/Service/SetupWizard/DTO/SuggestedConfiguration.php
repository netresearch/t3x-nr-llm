<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\DTO;

/**
 * DTO for a suggested LLM configuration use case.
 */
final readonly class SuggestedConfiguration
{
    /**
     * @param string               $identifier         Configuration identifier (e.g., blog-summarizer)
     * @param string               $name               Human-readable name
     * @param string               $description        What this configuration is for
     * @param string               $systemPrompt       The system prompt for this use case
     * @param string               $recommendedModelId Which model to use
     * @param float                $temperature        Temperature setting (0.0-2.0)
     * @param int                  $maxTokens          Maximum tokens for response
     * @param array<string, mixed> $additionalSettings Any other settings
     */
    public function __construct(
        public string $identifier,
        public string $name,
        public string $description,
        public string $systemPrompt,
        public string $recommendedModelId,
        public float $temperature = 0.7,
        public int $maxTokens = 4096,
        public array $additionalSettings = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'name' => $this->name,
            'description' => $this->description,
            'systemPrompt' => $this->systemPrompt,
            'recommendedModelId' => $this->recommendedModelId,
            'temperature' => $this->temperature,
            'maxTokens' => $this->maxTokens,
            'additionalSettings' => $this->additionalSettings,
        ];
    }
}

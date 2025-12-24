<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

/**
 * Base exception for all LLM-related errors
 *
 * @api This class is part of the public API
 */
class LlmException extends \RuntimeException
{
    public function __construct(
        string $message,
        private ?string $providerName = null,
        private ?array $context = null,
        private ?string $suggestion = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Get provider name that caused the exception
     */
    public function getProviderName(): ?string
    {
        return $this->providerName;
    }

    /**
     * Get exception context
     */
    public function getContext(): array
    {
        return $this->context ?? [];
    }

    /**
     * Get suggestion for resolution
     */
    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    /**
     * Convert to array for logging
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'provider' => $this->providerName,
            'context' => $this->context,
            'suggestion' => $this->suggestion,
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ];
    }
}

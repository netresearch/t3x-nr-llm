<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Security;

/**
 * Result object for sanitization operations
 */
class SanitizationResult
{
    private string $originalPrompt;
    private string $sanitizedPrompt;
    private array $warnings = [];
    private bool $blocked = false;

    public function __construct(string $originalPrompt)
    {
        $this->originalPrompt = $originalPrompt;
        $this->sanitizedPrompt = $originalPrompt;
    }

    public function setSanitizedPrompt(string $prompt): void
    {
        $this->sanitizedPrompt = $prompt;
    }

    public function getSanitizedPrompt(): string
    {
        return $this->sanitizedPrompt;
    }

    public function getOriginalPrompt(): string
    {
        return $this->originalPrompt;
    }

    public function addWarning(string $code, string $message, array $details = []): void
    {
        $this->warnings[] = [
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ];
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function setBlocked(bool $blocked): void
    {
        $this->blocked = $blocked;
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function isSafe(): bool
    {
        return !$this->blocked && empty($this->warnings);
    }

    public function wasModified(): bool
    {
        return $this->originalPrompt !== $this->sanitizedPrompt;
    }
}

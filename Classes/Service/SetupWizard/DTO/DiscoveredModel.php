<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\DTO;

/**
 * DTO for a discovered model from API.
 */
final readonly class DiscoveredModel
{
    /**
     * @param string        $modelId         The API model identifier (e.g., gpt-5.2, claude-opus-4-5)
     * @param string        $name            Human-readable name
     * @param string        $description     Model description
     * @param array<string> $capabilities    List of capabilities (chat, vision, tools, etc.)
     * @param int           $contextLength   Context window size in tokens
     * @param int           $maxOutputTokens Maximum output tokens
     * @param int           $costInput       Cost per 1M input tokens in cents
     * @param int           $costOutput      Cost per 1M output tokens in cents
     * @param bool          $recommended     Whether this model is recommended for general use
     */
    public function __construct(
        public string $modelId,
        public string $name,
        public string $description = '',
        public array $capabilities = ['chat'],
        public int $contextLength = 0,
        public int $maxOutputTokens = 0,
        public int $costInput = 0,
        public int $costOutput = 0,
        public bool $recommended = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'modelId' => $this->modelId,
            'name' => $this->name,
            'description' => $this->description,
            'capabilities' => $this->capabilities,
            'contextLength' => $this->contextLength,
            'maxOutputTokens' => $this->maxOutputTokens,
            'costInput' => $this->costInput,
            'costOutput' => $this->costOutput,
            'recommended' => $this->recommended,
        ];
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Option;

use Netresearch\NrLlm\Service\Option\AbstractOptions;
use Netresearch\NrLlm\Service\Option\BudgetAwareOptionsInterface;
use Netresearch\NrLlm\Service\Option\BudgetFieldsTrait;

/**
 * Options for speech-to-text transcription (Whisper).
 */
final class TranscriptionOptions extends AbstractOptions implements BudgetAwareOptionsInterface
{
    use BudgetFieldsTrait;

    private const VALID_FORMATS = ['json', 'text', 'srt', 'vtt', 'verbose_json'];

    /**
     * @param string|null $configuration Optional LlmConfiguration identifier
     *                                   (tx_nrllm_configuration) this call is
     *                                   attributed to — pure usage metadata for
     *                                   the per-configuration Analytics
     *                                   breakdowns. It does NOT change the
     *                                   model: the consumer resolves the model
     *                                   via `WhisperTranscriptionService::resolveModelForConfiguration()`
     *                                   BEFORE constructing the options.
     */
    public function __construct(
        public readonly ?string $model = 'whisper-1',
        public readonly ?string $language = null,
        public readonly ?string $format = 'json',
        public readonly ?string $prompt = null,
        public readonly ?float $temperature = null,
        public readonly ?string $configuration = null,
        ?int $beUserUid = null,
        ?float $plannedCost = null,
    ) {
        $this->setBudgetFields($beUserUid, $plannedCost);
        $this->validate();
    }

    private function validate(): void
    {
        if ($this->format !== null) {
            self::validateEnum($this->format, self::VALID_FORMATS, 'format');
        }
        if ($this->temperature !== null) {
            self::validateRange($this->temperature, 0.0, 1.0, 'temperature');
        }
        $this->validateBudgetFields();
    }

    public function toArray(): array
    {
        // `configuration` and the budget fields are deliberately absent:
        // they are consumer metadata for usage attribution, not
        // transcription API parameters, and must never reach the provider.
        return $this->filterNull([
            'model' => $this->model,
            'language' => $this->language,
            'response_format' => $this->format,
            'prompt' => $this->prompt,
            'temperature' => $this->temperature,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): static
    {
        $model = $options['model'] ?? null;
        $language = $options['language'] ?? null;
        $format = $options['format'] ?? $options['response_format'] ?? null;
        $prompt = $options['prompt'] ?? null;
        $temperature = $options['temperature'] ?? null;
        $configuration = $options['configuration'] ?? null;
        $beUserUid = $options['beUserUid'] ?? null;
        $plannedCost = $options['plannedCost'] ?? null;

        return new self(
            model: is_string($model) ? $model : null,
            language: is_string($language) ? $language : null,
            format: is_string($format) ? $format : null,
            prompt: is_string($prompt) ? $prompt : null,
            temperature: is_float($temperature) || is_int($temperature) ? (float)$temperature : null,
            configuration: is_string($configuration) ? $configuration : null,
            beUserUid: is_int($beUserUid) ? $beUserUid : null,
            plannedCost: is_float($plannedCost) || is_int($plannedCost) ? (float)$plannedCost : null,
        );
    }

    /**
     * Create options for verbose JSON output with timestamps.
     */
    public static function verbose(?string $language = null): self
    {
        return new self(format: 'verbose_json', language: $language);
    }

    /**
     * Create options for SRT subtitle format.
     */
    public static function subtitles(?string $language = null): self
    {
        return new self(format: 'srt', language: $language);
    }
}

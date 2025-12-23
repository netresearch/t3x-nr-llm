<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Option;

use Netresearch\NrLlm\Service\Option\AbstractOptions;

/**
 * Options for speech-to-text transcription (Whisper).
 */
final class TranscriptionOptions extends AbstractOptions
{
    private const VALID_FORMATS = ['json', 'text', 'srt', 'vtt', 'verbose_json'];

    public function __construct(
        public readonly ?string $model = 'whisper-1',
        public readonly ?string $language = null,
        public readonly ?string $format = 'json',
        public readonly ?string $prompt = null,
        public readonly ?float $temperature = null,
    ) {
        if ($this->format !== null) {
            self::validateEnum($this->format, self::VALID_FORMATS, 'format');
        }
        if ($this->temperature !== null) {
            self::validateRange($this->temperature, 0.0, 1.0, 'temperature');
        }
    }

    public function toArray(): array
    {
        return $this->filterNull([
            'model' => $this->model,
            'language' => $this->language,
            'response_format' => $this->format,
            'prompt' => $this->prompt,
            'temperature' => $this->temperature,
        ]);
    }

    public static function fromArray(array $options): static
    {
        return new self(
            model: $options['model'] ?? null,
            language: $options['language'] ?? null,
            format: $options['format'] ?? $options['response_format'] ?? null,
            prompt: $options['prompt'] ?? null,
            temperature: isset($options['temperature']) ? (float) $options['temperature'] : null,
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

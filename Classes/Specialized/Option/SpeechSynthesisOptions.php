<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Option;

use Netresearch\NrLlm\Service\Option\AbstractOptions;

/**
 * Options for text-to-speech synthesis (OpenAI TTS).
 */
final class SpeechSynthesisOptions extends AbstractOptions
{
    private const VALID_VOICES = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
    private const VALID_MODELS = ['tts-1', 'tts-1-hd'];
    private const VALID_FORMATS = ['mp3', 'opus', 'aac', 'flac', 'wav', 'pcm'];

    public function __construct(
        public readonly ?string $model = 'tts-1',
        public readonly ?string $voice = 'alloy',
        public readonly ?string $format = 'mp3',
        public readonly ?float $speed = 1.0,
    ) {
        if ($this->model !== null) {
            self::validateEnum($this->model, self::VALID_MODELS, 'model');
        }
        if ($this->voice !== null) {
            self::validateEnum($this->voice, self::VALID_VOICES, 'voice');
        }
        if ($this->format !== null) {
            self::validateEnum($this->format, self::VALID_FORMATS, 'format');
        }
        if ($this->speed !== null) {
            self::validateRange($this->speed, 0.25, 4.0, 'speed');
        }
    }

    public function toArray(): array
    {
        return $this->filterNull([
            'model' => $this->model,
            'voice' => $this->voice,
            'response_format' => $this->format,
            'speed' => $this->speed,
        ]);
    }

    public static function fromArray(array $options): static
    {
        return new self(
            model: $options['model'] ?? null,
            voice: $options['voice'] ?? null,
            format: $options['format'] ?? $options['response_format'] ?? null,
            speed: isset($options['speed']) ? (float) $options['speed'] : null,
        );
    }

    /**
     * Create options for high-definition audio.
     */
    public static function hd(string $voice = 'alloy'): self
    {
        return new self(model: 'tts-1-hd', voice: $voice);
    }

    /**
     * Create options for fast, lower quality audio.
     */
    public static function fast(string $voice = 'alloy'): self
    {
        return new self(model: 'tts-1', voice: $voice);
    }

    /**
     * Get list of available voices.
     *
     * @return array<int, string>
     */
    public static function getAvailableVoices(): array
    {
        return self::VALID_VOICES;
    }
}

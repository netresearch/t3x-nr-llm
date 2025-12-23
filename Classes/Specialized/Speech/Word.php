<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Speech;

/**
 * A word from speech transcription with timing.
 */
final readonly class Word
{
    public function __construct(
        public string $word,
        public float $start,
        public float $end,
    ) {}

    /**
     * Get word duration in seconds.
     */
    public function getDuration(): float
    {
        return $this->end - $this->start;
    }

    /**
     * Create from Whisper API response word.
     *
     * @param array<string, mixed> $data Whisper word data
     */
    public static function fromWhisperResponse(array $data): self
    {
        return new self(
            word: $data['word'] ?? '',
            start: (float) ($data['start'] ?? 0.0),
            end: (float) ($data['end'] ?? 0.0),
        );
    }
}

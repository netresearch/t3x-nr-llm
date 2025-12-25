<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Speech;

/**
 * A segment from speech transcription with timestamps.
 *
 * Used for word-level or sentence-level timing information.
 */
final readonly class Segment
{
    /**
     * @param string                $text       The segment text
     * @param float                 $start      Start time in seconds
     * @param float                 $end        End time in seconds
     * @param float|null            $confidence Segment confidence score (0.0-1.0)
     * @param array<int, Word>|null $words      Word-level details (if available)
     */
    public function __construct(
        public string $text,
        public float $start,
        public float $end,
        public ?float $confidence = null,
        public ?array $words = null,
    ) {}

    /**
     * Get segment duration in seconds.
     */
    public function getDuration(): float
    {
        return $this->end - $this->start;
    }

    /**
     * Check if segment has word-level details.
     */
    public function hasWords(): bool
    {
        return $this->words !== null && $this->words !== [];
    }

    /**
     * Create from Whisper API response segment.
     *
     * @param array<string, mixed> $data Whisper segment data
     */
    public static function fromWhisperResponse(array $data): self
    {
        $words = null;
        if (isset($data['words']) && is_array($data['words'])) {
            $words = array_map(
                Word::fromWhisperResponse(...),
                $data['words'],
            );
        }

        return new self(
            text: $data['text'] ?? '',
            start: (float)($data['start'] ?? 0.0),
            end: (float)($data['end'] ?? 0.0),
            confidence: isset($data['avg_logprob']) ? exp((float)$data['avg_logprob']) : null,
            words: $words,
        );
    }
}

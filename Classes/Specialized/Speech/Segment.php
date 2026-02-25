<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

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
        /** @var list<Word>|null $words */
        $words = null;
        if (isset($data['words']) && is_array($data['words'])) {
            $words = [];
            foreach ($data['words'] as $wordData) {
                if (is_array($wordData)) {
                    /** @var array<string, mixed> $wordData */
                    $words[] = Word::fromWhisperResponse($wordData);
                }
            }
        }

        $text = isset($data['text']) && is_string($data['text']) ? $data['text'] : '';
        $start = isset($data['start']) && (is_float($data['start']) || is_int($data['start']))
            ? (float)$data['start']
            : 0.0;
        $end = isset($data['end']) && (is_float($data['end']) || is_int($data['end']))
            ? (float)$data['end']
            : 0.0;

        $confidence = null;
        if (isset($data['avg_logprob']) && (is_float($data['avg_logprob']) || is_int($data['avg_logprob']))) {
            $confidence = exp((float)$data['avg_logprob']);
        }

        return new self(
            text: $text,
            start: $start,
            end: $end,
            confidence: $confidence,
            words: $words,
        );
    }
}

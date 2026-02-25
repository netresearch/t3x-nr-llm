<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Speech;

/**
 * Result from speech-to-text transcription (Whisper).
 */
final readonly class TranscriptionResult
{
    /**
     * @param string                    $text       The transcribed text
     * @param string                    $language   Detected or specified language code
     * @param float|null                $duration   Audio duration in seconds
     * @param array<int, Segment>|null  $segments   Word/segment-level timestamps (verbose mode)
     * @param float|null                $confidence Overall transcription confidence (0.0-1.0)
     * @param array<string, mixed>|null $metadata   Additional metadata
     */
    public function __construct(
        public string $text,
        public string $language,
        public ?float $duration = null,
        public ?array $segments = null,
        public ?float $confidence = null,
        public ?array $metadata = null,
    ) {}

    /**
     * Check if result includes segment-level timestamps.
     */
    public function hasSegments(): bool
    {
        return $this->segments !== null && $this->segments !== [];
    }

    /**
     * Get formatted duration string (e.g., "2:34").
     */
    public function getFormattedDuration(): ?string
    {
        if ($this->duration === null) {
            return null;
        }

        $totalSeconds = (int)$this->duration;
        $minutes = intdiv($totalSeconds, 60);
        $seconds = $totalSeconds % 60;

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Get confidence as percentage string.
     */
    public function getConfidencePercent(): ?string
    {
        if ($this->confidence === null) {
            return null;
        }

        return number_format($this->confidence * 100, 1) . '%';
    }

    /**
     * Get word count of transcribed text.
     */
    public function getWordCount(): int
    {
        return str_word_count($this->text);
    }

    /**
     * Export as SRT subtitle format.
     *
     * @return string|null SRT formatted subtitles or null if no segments
     */
    public function toSrt(): ?string
    {
        if (!$this->hasSegments()) {
            return null;
        }

        $srt = '';
        $index = 1;

        foreach ($this->segments ?? [] as $segment) {
            $srt .= sprintf(
                "%d\n%s --> %s\n%s\n\n",
                $index++,
                $this->formatSrtTime($segment->start),
                $this->formatSrtTime($segment->end),
                $segment->text,
            );
        }

        return $srt;
    }

    /**
     * Export as VTT subtitle format.
     *
     * @return string|null VTT formatted subtitles or null if no segments
     */
    public function toVtt(): ?string
    {
        if (!$this->hasSegments()) {
            return null;
        }

        $vtt = "WEBVTT\n\n";

        foreach ($this->segments ?? [] as $segment) {
            $vtt .= sprintf(
                "%s --> %s\n%s\n\n",
                $this->formatVttTime($segment->start),
                $this->formatVttTime($segment->end),
                $segment->text,
            );
        }

        return $vtt;
    }

    /**
     * Format time for SRT (00:00:00,000).
     */
    private function formatSrtTime(float $seconds): string
    {
        $totalSeconds = (int)floor($seconds);
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $secs = $totalSeconds % 60;
        $ms = (int)round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $ms);
    }

    /**
     * Format time for VTT (00:00:00.000).
     */
    private function formatVttTime(float $seconds): string
    {
        $totalSeconds = (int)floor($seconds);
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $secs = $totalSeconds % 60;
        $ms = (int)round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $ms);
    }
}

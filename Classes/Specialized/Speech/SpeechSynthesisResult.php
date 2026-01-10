<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Speech;

/**
 * Result from text-to-speech synthesis.
 */
final readonly class SpeechSynthesisResult
{
    /**
     * @param string                    $audioContent   Binary audio content
     * @param string                    $format         Audio format (mp3, opus, aac, flac, wav, pcm)
     * @param string                    $model          Model used for synthesis
     * @param string                    $voice          Voice used for synthesis
     * @param int                       $characterCount Number of characters processed
     * @param array<string, mixed>|null $metadata       Additional metadata
     */
    public function __construct(
        public string $audioContent,
        public string $format,
        public string $model,
        public string $voice,
        public int $characterCount,
        public ?array $metadata = null,
    ) {}

    /**
     * Get audio content size in bytes.
     */
    public function getSize(): int
    {
        return strlen($this->audioContent);
    }

    /**
     * Get audio content size formatted (e.g., "1.5 MB").
     */
    public function getFormattedSize(): string
    {
        $bytes = $this->getSize();

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }

    /**
     * Get appropriate MIME type for the audio format.
     */
    public function getMimeType(): string
    {
        return match ($this->format) {
            'mp3' => 'audio/mpeg',
            'opus' => 'audio/opus',
            'aac' => 'audio/aac',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            'pcm' => 'audio/pcm',
            default => 'application/octet-stream',
        };
    }

    /**
     * Get file extension for the audio format.
     */
    public function getFileExtension(): string
    {
        return match ($this->format) {
            'mp3' => 'mp3',
            'opus' => 'opus',
            'aac' => 'aac',
            'flac' => 'flac',
            'wav' => 'wav',
            'pcm' => 'pcm',
            default => 'bin',
        };
    }

    /**
     * Save audio content to file.
     *
     * @param string $path File path to save to
     *
     * @return bool Success status
     */
    public function saveToFile(string $path): bool
    {
        $result = @file_put_contents($path, $this->audioContent);
        return $result !== false;
    }

    /**
     * Get audio content as base64 encoded string.
     */
    public function toBase64(): string
    {
        return base64_encode($this->audioContent);
    }

    /**
     * Get audio content as data URL.
     */
    public function toDataUrl(): string
    {
        return sprintf('data:%s;base64,%s', $this->getMimeType(), $this->toBase64());
    }

    /**
     * Check if using HD model.
     */
    public function isHd(): bool
    {
        return str_contains($this->model, 'hd');
    }
}

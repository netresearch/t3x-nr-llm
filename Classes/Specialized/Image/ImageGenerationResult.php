<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Image;

/**
 * Result from image generation services (DALL-E, FAL, etc.).
 */
final readonly class ImageGenerationResult
{
    /**
     * @param string                    $url           URL to the generated image (temporary, typically expires in 1 hour)
     * @param string|null               $base64        Base64-encoded image data (if requested)
     * @param string                    $prompt        The prompt used for generation
     * @param string|null               $revisedPrompt The revised/enhanced prompt (DALL-E 3)
     * @param string                    $model         Model used for generation
     * @param string                    $size          Image dimensions (e.g., "1024x1024")
     * @param string                    $provider      Provider identifier (dall-e, fal)
     * @param array<string, mixed>|null $metadata      Additional metadata
     */
    public function __construct(
        public string $url,
        public ?string $base64,
        public string $prompt,
        public ?string $revisedPrompt,
        public string $model,
        public string $size,
        public string $provider,
        public ?array $metadata = null,
    ) {}

    /**
     * Check if base64 data is available.
     */
    public function hasBase64(): bool
    {
        return $this->base64 !== null && $this->base64 !== '';
    }

    /**
     * Get image as binary content.
     *
     * @return string|null Binary image data
     */
    public function getBinaryContent(): ?string
    {
        if ($this->hasBase64()) {
            return base64_decode((string)$this->base64, true) ?: null;
        }

        return null;
    }

    /**
     * Get image as data URL for inline use.
     *
     * @param string $mimeType MIME type (default: image/png)
     *
     * @return string|null Data URL or null if no base64
     */
    public function toDataUrl(string $mimeType = 'image/png'): ?string
    {
        if (!$this->hasBase64()) {
            return null;
        }

        return sprintf('data:%s;base64,%s', $mimeType, $this->base64);
    }

    /**
     * Save image to file.
     *
     * @param string $path File path to save to
     *
     * @return bool Success status
     */
    public function saveToFile(string $path): bool
    {
        $content = $this->getBinaryContent();

        if ($content === null) {
            // Try to download from URL
            $content = @file_get_contents($this->url);
            if ($content === false) {
                return false;
            }
        }

        $result = file_put_contents($path, $content);
        return $result !== false;
    }

    /**
     * Download image from URL and return binary content.
     *
     * @return string|null Binary image data
     */
    public function downloadFromUrl(): ?string
    {
        $content = @file_get_contents($this->url);
        return $content !== false ? $content : null;
    }

    /**
     * Get image dimensions as array.
     *
     * @return array{width: int, height: int}
     */
    public function getDimensions(): array
    {
        $parts = explode('x', $this->size);
        // explode always returns at least one element
        $width = $parts[0];
        $height = $parts[1] ?? $width;

        return [
            'width' => (int)$width,
            'height' => (int)$height,
        ];
    }

    /**
     * Get image width.
     */
    public function getWidth(): int
    {
        return $this->getDimensions()['width'];
    }

    /**
     * Get image height.
     */
    public function getHeight(): int
    {
        return $this->getDimensions()['height'];
    }

    /**
     * Check if image is landscape orientation.
     */
    public function isLandscape(): bool
    {
        $dims = $this->getDimensions();
        return $dims['width'] > $dims['height'];
    }

    /**
     * Check if image is portrait orientation.
     */
    public function isPortrait(): bool
    {
        $dims = $this->getDimensions();
        return $dims['height'] > $dims['width'];
    }

    /**
     * Check if image is square.
     */
    public function isSquare(): bool
    {
        $dims = $this->getDimensions();
        return $dims['width'] === $dims['height'];
    }

    /**
     * Check if prompt was revised by the model.
     */
    public function wasPromptRevised(): bool
    {
        return $this->revisedPrompt !== null
            && $this->revisedPrompt !== ''
            && $this->revisedPrompt !== $this->prompt;
    }

    /**
     * Get the effective prompt (revised if available, original otherwise).
     */
    public function getEffectivePrompt(): string
    {
        return $this->revisedPrompt ?? $this->prompt;
    }
}

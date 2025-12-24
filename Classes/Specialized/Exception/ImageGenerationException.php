<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

/**
 * Exception thrown when image generation fails.
 *
 * This covers errors from DALL-E and other image generation services,
 * including content policy violations and generation failures.
 */
final class ImageGenerationException extends SpecializedServiceException {}

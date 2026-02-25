<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

/**
 * Exception thrown when image generation fails.
 *
 * This covers errors from DALL-E and other image generation services,
 * including content policy violations and generation failures.
 */
final class ImageGenerationException extends SpecializedServiceException {}

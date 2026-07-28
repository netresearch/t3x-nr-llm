<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Image;

use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;

/**
 * The one call every image service has in common: a prompt in, an image out.
 *
 * The concrete services take different second parameters — `DallEImageService`
 * an `ImageGenerationOptions` object, `FalImageService` a model identifier —
 * because their providers model the request differently. That divergence is
 * deliberate and stays; a caller that wants provider-specific control depends
 * on the concrete class.
 *
 * This interface exists for the callers that do not: hand over a prompt, take
 * whatever the service's own defaults produce, and treat the two
 * interchangeably. Both implementations already default every parameter after
 * the prompt, so neither changes behaviour by implementing it.
 */
interface ImageGeneratorInterface
{
    /**
     * Generate a single image from a prompt using the service's defaults.
     *
     * @throws ServiceUnavailableException when the service has no usable credential
     */
    public function generate(string $prompt): ImageGenerationResult;
}

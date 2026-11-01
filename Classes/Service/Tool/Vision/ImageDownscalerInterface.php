<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Vision;

use TYPO3\CMS\Core\Resource\File;

/**
 * Makes an image small enough to send to a language model (ADR-235).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface ImageDownscalerInterface
{
    /**
     * A data URI of the file with its longest edge at `$maxEdge` at most, or
     * null when the file cannot be brought there. A file that is only sent
     * smaller or not at all: the original is never the fallback.
     */
    public function dataUri(File $file, int $maxEdge): ?string;
}

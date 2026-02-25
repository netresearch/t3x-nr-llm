<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Netresearch\NrLlm\Domain\Model\VisionResponse;

interface VisionCapableInterface
{
    /**
     * @param array<int, array{type: string, image_url?: array{url: string}, text?: string}> $content
     * @param array<string, mixed>                                                           $options
     */
    public function analyzeImage(array $content, array $options = []): VisionResponse;

    public function supportsVision(): bool;

    /**
     * @return array<string>
     */
    public function getSupportedImageFormats(): array;

    public function getMaxImageSize(): int;
}

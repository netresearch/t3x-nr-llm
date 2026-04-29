<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;

interface VisionCapableInterface
{
    /**
     * Implementations may assume every `$content` entry is a `VisionContent` —
     * `LlmServiceManager::vision()` normalises any legacy array fixture
     * via `VisionContent::fromArray()` before forwarding the call.
     *
     * @param list<VisionContent>  $content
     * @param array<string, mixed> $options
     */
    public function analyzeImage(array $content, array $options = []): VisionResponse;

    public function supportsVision(): bool;

    /**
     * @return array<string>
     */
    public function getSupportedImageFormats(): array;

    public function getMaxImageSize(): int;
}

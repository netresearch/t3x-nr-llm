<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

/**
 * The token counts an image response reported, or all zeros when it reported
 * none.
 *
 * `gpt-image-*` responses carry a `usage` object; `dall-e-2` and `dall-e-3`
 * never do. Zero everywhere therefore means "no token accounting for this
 * call" rather than "a call that consumed nothing", and the cost calculator
 * reads it that way: it falls back to per-image list pricing instead of
 * pricing zero tokens at zero.
 *
 * The three counts travel together everywhere they travel at all, and are
 * carried as one value so that
 * {@see \Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface::estimateImageCost()}
 * stays readable at its call sites.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class ImageTokenUsage
{
    public function __construct(
        /** `usage.input_tokens`, 0 when the response carried none. */
        public int $inputTokens = 0,
        /** `usage.output_tokens`, 0 when the response carried none. */
        public int $outputTokens = 0,
        /** `usage.input_tokens_details.image_tokens`, 0 when absent. */
        public int $imageInputTokens = 0,
    ) {}

    /**
     * Whether the response accounted for any tokens at all.
     *
     * False is the dall-e case: nothing to price by token, so the caller
     * prices by image.
     */
    public function isEmpty(): bool
    {
        return $this->inputTokens <= 0 && $this->outputTokens <= 0;
    }
}

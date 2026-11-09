<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenAi;

/**
 * A model record's reasoning profile, with the source of each field
 * (ADR-275).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ResolvedModelProfile
{
    public function __construct(
        public OpenAiModelProfile $profile,
        public ProfileFieldSource $reasoningSource,
        public ProfileFieldSource $effortsSource,
        public ProfileFieldSource $toolRouteSource,
    ) {}

    /**
     * For templates: whether the resolved profile is a reasoning model.
     */
    public function isReasoningModel(): bool
    {
        return $this->profile->isReasoningModel;
    }

    /**
     * For templates: the source of the reasoning flag as its stored value.
     */
    public function getReasoningSourceValue(): string
    {
        return $this->reasoningSource->value;
    }
}

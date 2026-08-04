<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\Discovery;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;

/**
 * What one provider discovery produced, and whether it is live API data.
 *
 * Before the split this was a bool on the ModelDiscovery facade, set by a
 * helper deep inside each provider path. A discoverer in its own class cannot
 * reach that flag, so fallback-ness travels with the models instead — which is
 * also the honest shape: whether a catalog is live or canned is a property of
 * the result, not of the service that produced it.
 */
final readonly class DiscoveryResult
{
    /**
     * @param array<DiscoveredModel> $models
     */
    private function __construct(
        public array $models,
        public bool $usedFallback,
    ) {}

    /**
     * Models read from the provider's live API — including a legitimately
     * empty list, which is an answer, not a failure.
     *
     * @param array<DiscoveredModel> $models
     */
    public static function live(array $models): self
    {
        return new self($models, false);
    }

    /**
     * A static fallback catalog substituted for live data (failed request,
     * unexpected status, or malformed/empty response).
     *
     * @param array<DiscoveredModel> $models
     */
    public static function fallback(array $models): self
    {
        return new self($models, true);
    }
}

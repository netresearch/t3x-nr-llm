<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\Discovery;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;

/**
 * What one provider discovery produced, and whether a static fallback catalog
 * was substituted for it.
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
     * No static catalog was substituted. Usually live API data — but also the
     * providers that deliberately answer a failure with an empty list rather
     * than a canned catalog (Ollama, OpenRouter, Groq): an empty result from
     * them is reported as-is, exactly as the inline code did.
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

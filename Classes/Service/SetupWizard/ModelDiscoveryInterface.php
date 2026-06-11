<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard;

use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;

/**
 * Interface for model discovery from LLM providers.
 */
interface ModelDiscoveryInterface
{
    /**
     * Test connection to provider.
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(DetectedProvider $provider, string $apiKey): array;

    /**
     * Discover models from provider.
     *
     * @return array<DiscoveredModel>
     */
    public function discover(DetectedProvider $provider, string $apiKey): array;

    /**
     * Whether the most recent discover() call substituted a static fallback
     * catalog for live API data (failed request, unexpected HTTP status, or
     * malformed/empty response).
     *
     * Returns false when no live discovery was attempted at all (e.g. for
     * adapter types without API-based discovery).
     */
    public function wasLastDiscoveryFromFallback(): bool;
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\Discovery;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Throwable;

/**
 * The model the local decision sidecar loaded (`Build/decision`, ADR-211).
 *
 * `GET /models` answers `{"models": [{"name"}]}`; the sidecar serves one. It
 * needs no key and carries no price — a local model has no provider bill, and
 * an unpriced model records no cost rather than a cost of zero.
 */
final class DecisionSidecarModelDiscoverer extends AbstractModelDiscoverer
{
    private const ADAPTER = 'decision_sidecar';

    public function discover(string $endpoint, string $apiKey): DiscoveryResult
    {
        try {
            $response = $this->dispatch($this->requestFactory->createRequest('GET', $endpoint . self::MODELS_PATH), self::VAULT_DISPATCH_REASON);
            if ($response->getStatusCode() !== 200) {
                $this->logDiscoveryHttpError(self::ADAPTER, $response->getStatusCode());

                return DiscoveryResult::live([]);
            }

            $decoded = $this->decodeModelListBody(self::ADAPTER, $response->getBody()->getContents());

            $models = [];
            foreach (is_array($decoded['models'] ?? null) ? $decoded['models'] : [] as $entry) {
                if (!is_array($entry) || !is_string($entry['name'] ?? null) || $entry['name'] === '') {
                    continue;
                }

                $models[] = new DiscoveredModel(
                    modelId: $entry['name'],
                    name: $entry['name'],
                    description: "Local zero-shot NLI model; probabilities are the model's own, not calibrated",
                    capabilities: [ModelCapability::DECISION->value],
                    recommended: true,
                );
            }

            return DiscoveryResult::live($models);
        } catch (Throwable $e) {
            $this->logDiscoveryFailure(self::ADAPTER, $e);

            return DiscoveryResult::live([]);
        }
    }
}

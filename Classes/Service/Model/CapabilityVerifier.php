<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Model;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;

/**
 * Asks a model's provider what that model can do, and records the answer as
 * capability provenance (ADR-160).
 *
 * This is the only writer of `tx_nrllm_model.capabilities_*`. The setup
 * wizard deliberately is NOT one: by the time it persists the selected
 * models, the discovery it displayed has happened in an earlier request and
 * it can no longer tell a live answer from the substituted static catalog.
 * Stamping "confirmed by discovery" there would manufacture exactly the
 * false confidence provenance exists to remove.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class CapabilityVerifier
{
    public function __construct(
        private ModelDiscoveryInterface $modelDiscovery,
    ) {}

    /**
     * Run discovery for the model's provider and record what it reported for
     * this model.
     *
     * Returns false — writing nothing — when there is no answer to record:
     * the model has no provider, or the provider's catalog does not list this
     * model id. "The provider does not know it" is not a confirmation, and
     * must not be stored as one.
     *
     * The declared capability set is left alone. A capability an operator
     * added by hand survives verification and is afterwards attributed to
     * them, which is the whole point: the operator surface can now separate
     * what the provider said from what somebody assumed.
     */
    public function verify(Model $model, DateTimeImmutable $now): bool
    {
        $provider = $model->getProvider();
        if (!$provider instanceof Provider) {
            return false;
        }

        $discovered = $this->findModel($provider, $model->getModelId());
        if (!$discovered instanceof DiscoveredModel) {
            return false;
        }

        $model->recordCapabilityDiscovery(
            array_values(array_filter($discovered->capabilities, is_string(...))),
            $this->modelDiscovery->wasLastDiscoveryFromFallback()
                ? CapabilitySource::Catalog
                : CapabilitySource::Discovery,
            $now,
        );

        return true;
    }

    private function findModel(Provider $provider, string $modelId): ?DiscoveredModel
    {
        if ($modelId === '') {
            return null;
        }

        $detected = new DetectedProvider(
            adapterType: $provider->getAdapterType(),
            suggestedName: $provider->getName(),
            endpoint: $provider->getEffectiveEndpointUrl(),
        );

        foreach ($this->modelDiscovery->discover($detected, $provider->getDecryptedApiKey()) as $candidate) {
            if ($candidate->modelId === $modelId) {
                return $candidate;
            }
        }

        return null;
    }
}

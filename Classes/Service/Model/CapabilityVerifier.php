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
 * This is the only writer of `tx_nrllm_model.capabilities_*`, and of the
 * discovered reasoning profile `tx_nrllm_model.reasoning_*` (ADR-274). The setup
 * wizard deliberately is NOT one: by the time it persists the selected
 * models, the discovery it displayed has happened in an earlier request and
 * it can no longer tell a live answer from the substituted static catalog.
 * Stamping "confirmed by discovery" there would manufacture exactly the
 * false confidence provenance exists to remove.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class CapabilityVerifier
{
    /**
     * The adapter type whose models the third-party catalogue describes. Other
     * OpenAI-dialect types (Azure, custom gateways) name deployments, which
     * `openai/<id>` would not match.
     */
    private const CATALOGUE_ADAPTER_TYPE = 'openai';

    public function __construct(
        private ModelDiscoveryInterface $modelDiscovery,
        private ?ThirdPartyReasoningCatalog $reasoningCatalog = null,
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
            $this->sourceOf($discovered),
            $now,
        );

        $this->recordReasoning($model, $provider, $discovered, $now);

        return true;
    }

    /**
     * Record what this run stated about reasoning (ADR-274), and nothing when
     * it stated nothing: a missing answer must not overwrite an earlier one
     * or turn into "no". The operator fields are never written here.
     *
     * The provider's own listing wins: it describes the endpoint the request
     * goes to. Only where it is silent, and only for a direct OpenAI provider,
     * does the opt-in third-party catalogue get asked — for reasoning yes/no,
     * never for a scale.
     */
    private function recordReasoning(Model $model, Provider $provider, DiscoveredModel $discovered, DateTimeImmutable $now): void
    {
        if ($discovered->reasoning !== null && !$this->modelDiscovery->wasLastDiscoveryFromFallback()) {
            $model->recordReasoningDiscovery(
                $discovered->reasoning,
                $discovered->reasoningEfforts,
                CapabilitySource::Discovery,
                $now,
            );

            return;
        }

        if ($provider->getAdapterType() !== self::CATALOGUE_ADAPTER_TYPE
            || !$this->reasoningCatalog instanceof ThirdPartyReasoningCatalog) {
            return;
        }

        $stated = $this->reasoningCatalog->reasoningFor($model->getModelId());
        if ($stated !== null) {
            $model->recordReasoningDiscovery($stated, [], CapabilitySource::ThirdPartyCatalog, $now);
        }
    }

    /**
     * `Discovery` means the provider's endpoint reported these capability
     * TOKENS — not that the model LIST was live. The two come apart routinely:
     * OpenAI's and Anthropic's model endpoints return an id and nothing else,
     * so their discoverers read the tokens out of the bundled catalog on the
     * live path exactly as on the fallback path, and Gemini's curated table
     * wins over the listing for every model it knows. Deciding the source from
     * `wasLastDiscoveryFromFallback()` alone would stamp "confirmed by the
     * provider" on all of those.
     *
     * A model list that fell back to the catalog is `Catalog` regardless of
     * the flag, because those DTOs are the catalog.
     */
    private function sourceOf(DiscoveredModel $discovered): CapabilitySource
    {
        return $this->modelDiscovery->wasLastDiscoveryFromFallback() || !$discovered->capabilitiesFromApi
            ? CapabilitySource::Catalog
            : CapabilitySource::Discovery;
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

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\TelemetrySignals;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;

/**
 * Plans a configuration-driven call: which Model, which adapter, which
 * effective options. Extracted verbatim from LlmServiceManager (ADR-059
 * stage 2, the "per-configuration preamble" seam).
 *
 * Owns the three invariants that used to be reachable only through five
 * dispatch closures: criteria-mode resolution through ModelSelectionService,
 * the deliberate refusal to call setLlmModel() on a repository-managed
 * entity, and the max_tokens precedence chain (#390).
 *
 * Built by the manager from its own dependencies, not injected — the
 * manager's constructor is pinned by the shared test factory, and the
 * planner is an implementation detail of its dispatch.
 */
final readonly class ConfigurationCallPlanner
{
    public function __construct(
        private ProviderAdapterRegistryInterface $adapterRegistry,
        private ?ModelSelectionServiceInterface $modelSelectionService = null,
        private ?ConfigurationSnippetResolver $snippetResolver = null,
    ) {}

    /**
     * The adapter a configuration-driven call runs against — the single
     * adapter choke point (ADR-066).
     *
     * `$operation` is threaded through to the resolution so that two adapter
     * lookups for the same call cannot land on different models (ADR-138).
     */
    public function adapterFor(LlmConfiguration $configuration, ?ProviderOperation $operation): ProviderInterface
    {
        return $this->adapterRegistry->createAdapterFromModel($this->resolveModel($configuration, $operation));
    }

    /**
     * Resolve the concrete Model entity an LlmConfiguration call runs against.
     *
     * `$operation` names the call being planned. In criteria mode it narrows
     * the selection to models that declare the matching capability (ADR-138);
     * pass null only where there is genuinely no operation. Two resolutions of
     * the SAME call must pass the SAME operation — a cache key derived from one
     * resolution and a terminal that resolves again would otherwise be able to
     * disagree about which model the call runs on.
     *
     * `$signals` is the running call's telemetry scratchpad. Given one, the
     * reasoning behind a criteria-mode selection is recorded on it for the
     * telemetry row (ADR-156); the resolution is unchanged either way, and the
     * paths with no pipeline context (a bare adapter lookup) pass null.
     */
    public function resolveModel(
        LlmConfiguration $configuration,
        ?ProviderOperation $operation,
        ?TelemetrySignals $signals = null,
    ): Model {
        // Criteria-mode configurations carry no direct model relation (model_uid = 0);
        // their model is selected at call time from the stored criteria. Resolve
        // through ModelSelectionService — which returns the directly configured model
        // unchanged for fixed-mode configs — so both selection modes reach a concrete
        // model here. Without this, every *ForConfiguration() call on a criteria-mode
        // configuration threw "has no model assigned".
        $llmModel = $configuration->getLlmModel();
        if ($this->modelSelectionService instanceof ModelSelectionServiceInterface) {
            // One evaluation answers both questions — which model, and why. See
            // ModelSelectionServiceInterface::resolveModelForCall() for why this
            // is not resolveModel() plus explainRouting().
            $resolution = $this->modelSelectionService->resolveModelForCall($configuration, $operation);
            $llmModel   = $resolution->model;
            $signals?->recordRoutingSummary($resolution->routingSummary);
        }

        if (!$llmModel instanceof Model) {
            throw new ProviderException(
                sprintf('Configuration "%s" has no model assigned', $configuration->getIdentifier()),
                1735300100,
            );
        }

        // Intentionally NOT calling $configuration->setLlmModel($llmModel): the
        // configuration is a repository-managed Extbase entity, so mutating it
        // would mark it dirty and Extbase would persist model_uid at end of
        // request — silently converting a criteria-mode record into a fixed-mode
        // one. Per-model cost analytics for criteria configs (UsageMiddleware
        // reads getLlmModel() directly) remain a separate, non-destructive
        // follow-up.
        return $llmModel;
    }

    /**
     * Merge a configuration's stored option defaults with per-call overrides
     * and fill `max_tokens` from the resolved model when neither set it (#390).
     *
     * Precedence: explicit per-call option > configuration max_tokens (> 0)
     * > model max_output_tokens (> 0) > provider default (4096 for
     * OpenAI/Claude-shaped payloads; Ollama omits num_predict so the server
     * default applies).
     *
     * @param array<string, mixed> $optionOverrides
     *
     * @return array<string, mixed>
     */
    public function callOptions(LlmConfiguration $config, Model $model, array $optionOverrides): array
    {
        $options = array_merge($config->toOptionsArray(), $optionOverrides);
        unset($options['provider']);

        $options = $this->withSnippetTags($options, $config);

        // An explicit non-positive max_tokens override means "unset" too —
        // passing 0 through would fail provider-side validation.
        if (!isset($options['max_tokens']) || (is_int($options['max_tokens']) && $options['max_tokens'] <= 0)) {
            if ($model->getMaxOutputTokens() > 0) {
                $options['max_tokens'] = $model->getMaxOutputTokens();
            } else {
                unset($options['max_tokens']);
            }
        }

        return $options;
    }

    /**
     * Compose the configuration's tag-selected prompt snippets (ADR-031) into
     * the merged call options' `system_prompt`.
     *
     * This is the single insertion point that reaches chat, completion,
     * streaming and the agent loop: every configuration-driven entry point on
     * {@see LlmServiceManager} builds its options here, and the value is read
     * downstream by {@see MessageShaper::applySystemPrompt()} for the message
     * paths and by {@see \Netresearch\NrLlm\Provider\AbstractProvider::complete()}
     * for the single-prompt path.
     *
     * The snippets are appended to whatever system prompt is effective AFTER
     * the per-call overrides are merged, so a caller-supplied system prompt
     * still wins on the text while the configuration's editorial context is
     * kept. A configuration without tags returns the options untouched.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function withSnippetTags(array $options, LlmConfiguration $config): array
    {
        if (!$this->snippetResolver instanceof ConfigurationSnippetResolver) {
            return $options;
        }

        $current = $options['system_prompt'] ?? '';
        $current = is_string($current) ? $current : '';

        $effective = $this->snippetResolver->appendTo($current, $config);
        if ($effective === $current) {
            return $options;
        }

        $options['system_prompt'] = $effective;

        return $options;
    }

    /**
     * The configuration the pipeline threaded onto the context — never null on
     * the configuration-driven paths, which always enter through
     * {@see ProviderCallContext::forConfiguration()} and whose fallback swaps
     * carry a non-null sibling. The guard makes that invariant explicit for the
     * type checker; it never fires in practice.
     */
    public function requireConfiguration(ProviderCallContext $context): LlmConfiguration
    {
        $configuration = $context->configuration;
        if (!$configuration instanceof LlmConfiguration) {
            throw new ProviderException('The pipeline context carried no configuration on a configuration-driven call.', 1784600700);
        }

        return $configuration;
    }

}

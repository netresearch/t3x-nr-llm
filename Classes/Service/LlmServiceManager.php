<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Generator;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\EmbeddingResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ContextFitResult;
use Netresearch\NrLlm\Domain\ValueObject\InjectedContext;
use Netresearch\NrLlm\Domain\ValueObject\RequestFacts;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Domain\ValueObject\VisionContent;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\TelemetrySignals;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Complexity\RequestComplexityEstimator;
use Netresearch\NrLlm\Service\Complexity\RequestFactsCollector;
use Netresearch\NrLlm\Service\Context\ContextWindowManagerInterface;
use Netresearch\NrLlm\Service\Context\InputContextTrustGate;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Option\EmbeddingOptions;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * @api
 */
final readonly class LlmServiceManager implements LlmServiceManagerInterface, SingletonInterface
{
    public function __construct(
        private ProviderAdapterRegistryInterface $adapterRegistry,
        private MiddlewarePipeline $pipeline,
        private KeyedProviderRegistry $providerRegistry,
        private ConfigurationResolver $configurationResolver,
        private MessageShaper $messageShaper,
        private EmbedCacheKeyBuilder $embedCacheKeyBuilder,
        private ?SkillInjectionService $skillInjection = null,
        private ?ModelSelectionServiceInterface $modelSelectionService = null,
        private ?StreamingDispatcher $streaming = null,
        // Input-side guardrails (ADR-087): screen/redact the outgoing prompt on
        // the send path (the pipeline cannot reach the payload). Required, not
        // optional: a screener that silently disappears is a fail-open control.
        // The default is an empty screener — no guardrails registered means
        // nothing to screen, which is honest; a null screener meant "screening
        // was requested and skipped", which was not.
        private InputGuardrailScreener $inputScreener = new InputGuardrailScreener([]),
        // Composes a configuration's tag-selected prompt snippets (ADR-031)
        // into the effective system prompt. Optional so the unit-test
        // constructions that omit it keep the pre-snippet behaviour verbatim;
        // production wiring is autowired.
        private ?ConfigurationSnippetResolver $snippetResolver = null,
        // Bounds what goes on the wire against the window of the model that
        // will actually serve the send (ADR-107, ADR-143). Optional so the
        // constructions that omit it keep the pre-binding behaviour verbatim —
        // and so the shared test factory's pinned signature keeps working;
        // production wiring is autowired. A null means the send is bounded by
        // the provider, which is what every generic path did before.
        private ?ContextWindowManagerInterface $contextWindow = null,
        // Reports the one condition the bound above cannot fix: a payload that
        // overflows even at its floor. Optional like the collaborators above.
        private ?LoggerInterface $logger = null,
        // Refuses a call whose injected context is classified above the trust
        // zone it can reach (ADR-144). Optional like the collaborators above;
        // a null means no input classification is applied, which is what every
        // path did before the axis existed.
        private ?InputContextTrustGate $inputContextGate = null,
    ) {
        // Built here from the manager's own dependencies, not injected: the
        // constructor signature is pinned by the shared test factory, and both
        // are implementation details of this facade's dispatch (ADR-059
        // stage 2).
        $this->planner  = new ConfigurationCallPlanner($this->adapterRegistry, $this->modelSelectionService, $this->snippetResolver);
        $this->metadata = new CallMetadataFactory();
    }

    /** Plans a configuration-driven call: model, adapter, effective options. */
    private ConfigurationCallPlanner $planner;

    /** Builds the pipeline metadata the middlewares read. */
    private CallMetadataFactory $metadata;

    /**
     * Prepend the resolved configuration's attached skills to a plain prompt.
     *
     * Used only by the configuration-driven entry points (complete()/chat()/
     * streamChat()) once a backend-managed default configuration has been
     * resolved — never by embed()/vision()/speech, keeping skill injection
     * scoped to text generation. A no-op when no SkillInjectionService is
     * wired (unit-test constructions that omit it).
     */
    private function injectConfigSkillsIntoPrompt(string $prompt, LlmConfiguration $configuration): string
    {
        if (!$this->skillInjection instanceof SkillInjectionService) {
            return $prompt;
        }

        return $this->skillInjection->augmentPrompt(
            $prompt,
            SkillInjectionService::toList($configuration->getSkills()),
        );
    }

    /**
     * Prepend the resolved configuration's attached skills to the first
     * user-role message (system role untouched). See
     * {@see self::injectConfigSkillsIntoPrompt()} for the scope rationale.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function injectConfigSkillsIntoMessages(array $messages, LlmConfiguration $configuration): array
    {
        if (!$this->skillInjection instanceof SkillInjectionService) {
            return $messages;
        }

        return $this->skillInjection->augmentMessages(
            $messages,
            SkillInjectionService::toList($configuration->getSkills()),
        );
    }

    /**
     * Screen (and redact) the outgoing messages through the input guardrails
     * before they reach a provider (ADR-087). With no guardrails registered the
     * screener passes the messages through unchanged. Throws
     * {@see \Netresearch\NrLlm\Exception\GuardrailViolationException} /
     * {@see \Netresearch\NrLlm\Exception\GuardrailApprovalRequiredException} on a
     * DENY / REQUIRE_APPROVAL verdict — at call time, so an over-policy prompt
     * never opens a stream.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function screenInput(array $messages): array
    {
        return $this->inputScreener->screen($messages);
    }

    /**
     * Screen a raw string prompt through the input guardrails (ADR-087) — the
     * ``complete`` entry points take a bare string, not a message list, so they
     * cannot use {@see self::screenInput()}. Wraps the prompt as a single user
     * message, screens it (so a REDACT rewrites it and a DENY / REQUIRE_APPROVAL
     * throws), and returns the redacted text.
     */
    private function screenInputPrompt(string $prompt): string
    {
        $screened = $this->inputScreener->screen([ChatMessage::user($prompt)]);
        $message  = $screened[0] ?? null;

        return $message instanceof ChatMessage ? $message->content : $prompt;
    }

    /**
     * Apply the system prompt to the outgoing messages, then screen the final
     * assembled list through the input guardrails (ADR-089) — so a secret in the
     * system prompt is redacted before the provider sees it, closing the gap
     * where the same secret was masked in a user turn but leaked in the system
     * turn. Re-screening the already-screened user turns is an idempotent no-op.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $options
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function applyAndScreenSystemPrompt(array $messages, array $options): array
    {
        return $this->screenInput($this->messageShaper->applySystemPrompt($messages, $options));
    }

    /**
     * Apply the input-context trust gate for a configuration-driven call.
     *
     * A no-op when no gate is wired, and a no-op when nothing the call injects
     * carries a declared class — which is every installation that has not
     * classified anything.
     *
     * `$operation` is the one the call will run under. It is threaded through
     * so the model this gate judges is the model the terminal will resolve
     * (ADR-138: two resolutions of one call pass the same operation).
     *
     * `$injectedContext` carries the sources this ONE run injects on top of the
     * configuration (ADR-164). They bind against the same ceiling: the tool
     * loop is the only caller that has them, and it forwards its own.
     *
     * @param array<string, mixed> $metadata
     * @param int                  $agentRunUid the run this call belongs to (ADR-153), stamped onto the
     *                                          refusal row; 0 when the call is not part of a run
     */
    private function assertContextPermitted(
        LlmConfiguration $configuration,
        array $metadata,
        ProviderOperation $operation,
        int $agentRunUid = 0,
        ?InjectedContext $injectedContext = null,
    ): void {
        if (!$this->inputContextGate instanceof InputContextTrustGate) {
            return;
        }

        $beUser = $metadata['beUser'] ?? 0;

        $this->inputContextGate->assertPermitted(
            $configuration,
            is_int($beUser) ? $beUser : 0,
            $this->servingModelForGate($configuration, $operation),
            $agentRunUid,
            $injectedContext->snippets ?? [],
            $injectedContext->skills ?? [],
        );
    }

    /**
     * The model the gate should read the trust zone from (ADR-149).
     *
     * Only criteria mode asks anything of routing. In fixed mode the answer is
     * already the configuration's own relation — `getProvider()` reads through
     * it — so resolving would cost a call to return what the gate would have
     * used anyway, and skipping it keeps fixed-mode behaviour structurally
     * unchanged rather than incidentally so.
     *
     * A routing failure is not a context failure. Criteria that match nothing,
     * or match only models that cannot serve this operation, throw here — and
     * that exception belongs to the dispatch that follows, which resolves again
     * and raises it with its own semantics. Swallowing it leaves the gate with
     * no serving provider, which is the honest, fail-closed `EXTERNAL_GLOBAL`
     * this path already answered before the model was threaded in.
     */
    private function servingModelForGate(LlmConfiguration $configuration, ProviderOperation $operation): ?Model
    {
        if (!$configuration->usesCriteriaSelection()) {
            return null;
        }

        try {
            return $this->planner->resolveModel($configuration, $operation);
        } catch (Throwable $e) {
            // Swallowed on purpose, logged on purpose: the gate degrades to the
            // fail-closed zone, and an operator seeing a trust-zone refusal
            // needs to be able to tell "routing picked an external model" from
            // "routing threw and we assumed the worst".
            $this->logger?->warning(
                'Could not resolve the serving model for the input-context gate; falling back to the least trusted zone',
                [
                    'configuration' => $configuration->getIdentifier(),
                    'operation'     => $operation->value,
                    'exception'     => $e::class,
                ],
            );

            return null;
        }
    }

    /**
     * Bound a configuration-driven send against the window of the model that
     * will serve it (ADR-143).
     *
     * Placed here, inside the pipeline terminal, because this is the first
     * point that knows the RESOLVED model: a criteria-mode configuration
     * carries no model relation, so anything earlier would be sizing against
     * an unknown window. For a stream this runs before the opener returns,
     * which is what makes the bound hold before the first chunk.
     *
     * Skills are NOT passed as injected text on this path: the configuration
     * entry points inject them into the message list before the send, so they
     * are already counted in `$messages`. Passing them again would charge them
     * twice. `$lastUsage` is null because each send here is standalone — there
     * is no loop whose previous call could calibrate this one, and the null
     * also resets the manager's per-run state, which matters because this
     * facade is a singleton.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $options
     * @param list<array<string, mixed>>             $toolSpecs the tool schemas this send carries, empty for a plain chat
     * @param ?TelemetrySignals                      $signals   the running call's scratchpad; given one, this send's
     *                                                          complexity is measured onto it (ADR-156). The fit is the
     *                                                          only place that knows the token estimate AND the budget,
     *                                                          which is why the observation hangs here rather than on a
     *                                                          pass of its own over the same messages.
     *
     * @return list<ChatMessage|array<string, mixed>>
     */
    private function fitToContextWindow(
        array $messages,
        LlmConfiguration $configuration,
        Model $llmModel,
        array $options,
        array $toolSpecs = [],
        ?TelemetrySignals $signals = null,
    ): array {
        if (!$this->contextWindow instanceof ContextWindowManagerInterface) {
            // Still measurable: size, tool count and shape do not need a fit.
            // Only the token and utilisation figures do, and they stay null
            // rather than being guessed from the byte count.
            $this->measureComplexity($signals, $messages, count($toolSpecs), null);

            return $messages;
        }

        $maxTokens    = $options['max_tokens'] ?? null;
        $chatOptions  = is_int($maxTokens) && $maxTokens > 0 ? (new ChatOptions())->withMaxTokens($maxTokens) : null;
        $systemPrompt = $options['system_prompt'] ?? null;

        $fit = $this->contextWindow->fit(
            $messages,
            $configuration,
            $chatOptions,
            null,
            $toolSpecs,
            '',
            is_string($systemPrompt) ? $systemPrompt : null,
            $llmModel,
        );

        // Measured against what actually goes on the wire — the fit's own
        // message list, post-pruning — because that is the list its token
        // estimate describes. Measuring the input would report a size the
        // provider never saw.
        $this->measureComplexity($signals, $fit->messages, count($toolSpecs), $fit);

        if ($fit->overflowAtFloor) {
            // Send it anyway, exactly as ConversationService does: the estimate
            // errs high, so this may well succeed, and if it does not the
            // provider's own error is what the caller would have got before
            // this bound existed. Refusing here would turn a call that might
            // have worked into one that certainly does not.
            $this->logger?->warning('Send does not fit the model context window even at its floor; sending it unpruned', [
                'configuration'   => $configuration->getIdentifier(),
                'model'           => $llmModel->getModelId(),
                'estimatedTokens' => $fit->estimatedTokens,
                'budget'          => $fit->budget,
            ]);
        }

        return $fit->messages;
    }

    /**
     * The completion path's half of the same bound (ADR-143).
     *
     * A raw prompt is a single unit. There are no older turns to drop, so the
     * fit can only ever report — pruning a caller's prompt behind their back
     * would silently change what they asked for, and the caller is the only
     * one who knows which part of it is expendable. What this does deliver is
     * the decision being EXPLICIT: an overflowing completion is named, with the
     * model and the budget it exceeded, instead of surfacing later as an opaque
     * provider error.
     *
     * @param array<string, mixed> $options
     * @param ?TelemetrySignals    $signals as on {@see self::fitToContextWindow()}
     */
    private function reportPromptOverflow(
        string $prompt,
        LlmConfiguration $configuration,
        Model $llmModel,
        array $options,
        ?TelemetrySignals $signals = null,
    ): void {
        $promptMessages = [ChatMessage::user($prompt)];

        if (!$this->contextWindow instanceof ContextWindowManagerInterface) {
            $this->measureComplexity($signals, $promptMessages, 0, null);

            return;
        }

        $maxTokens   = $options['max_tokens'] ?? null;
        $chatOptions = is_int($maxTokens) && $maxTokens > 0 ? (new ChatOptions())->withMaxTokens($maxTokens) : null;
        $system      = $options['system_prompt'] ?? null;

        $fit = $this->contextWindow->fit(
            $promptMessages,
            $configuration,
            $chatOptions,
            null,
            [],
            '',
            is_string($system) ? $system : null,
            $llmModel,
        );

        $this->measureComplexity($signals, $fit->messages, 0, $fit);

        if (!$fit->overflowAtFloor) {
            return;
        }

        $this->logger?->warning('Completion prompt exceeds the model context window; sending it unchanged', [
            'configuration'   => $configuration->getIdentifier(),
            'model'           => $llmModel->getModelId(),
            'estimatedTokens' => $fit->estimatedTokens,
            'budget'          => $fit->budget,
        ]);
    }

    /**
     * Record how involved this send was, for the telemetry row (ADR-156).
     *
     * OBSERVATION. The figure reaches one column and no decision: nothing in
     * {@see \Netresearch\NrLlm\Service\Routing\CandidateRanker},
     * {@see \Netresearch\NrLlm\Service\Routing\EligibilityEvaluator} or
     * {@see \Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode} reads it, and
     * ADR-156 states the three conditions that must hold before anything may.
     *
     * Fail-soft, like every other observation on this path: a measurement error
     * must not turn a working call into a failed one, so it is logged and
     * swallowed.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    private function measureComplexity(
        ?TelemetrySignals $signals,
        array $messages,
        int $toolCount,
        ?ContextFitResult $fit,
    ): void {
        if (!$signals instanceof TelemetrySignals) {
            return;
        }

        try {
            // Constructed here rather than injected: the estimator is a
            // stateless pure function object, and this manager's constructor is
            // pinned by the shared test factory.
            $signals->recordComplexity((new RequestComplexityEstimator())->estimate($messages, $toolCount, $fit));
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to measure request complexity; the call is unaffected', ['exception' => $e]);
        }
    }

    /**
     * Describe the request before anything chooses a model for it (ADR-174).
     *
     * The counterpart of {@see self::measureComplexity()}, and deliberately not
     * the same measurement. That one hangs off the context fit, which needs the
     * resolved model's budget; this one runs on the caller's own thread before
     * {@see self::runThroughPipeline()} builds a context, so no resolution can
     * have happened yet. Both records land on one row, which is what lets a
     * later analysis ask whether the model-independent half predicts anything
     * about the decision the other half describes.
     *
     * OBSERVATION. Nothing reads it inside the decision path; :ref:`ADR-156
     * <adr-156>` keeps its observer-only status and states what must hold first.
     *
     * Fail-soft like every other observation here: a measurement error must not
     * turn a working call into a failed one.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<array<string, mixed>>             $toolSpecs
     */
    private function collectRequestFacts(array $messages, array $toolSpecs = []): ?RequestFacts
    {
        try {
            // Constructed here rather than injected, like the complexity
            // estimator above and for the same reason: it is a stateless pure
            // function object, and this manager's constructor is pinned by the
            // shared test factory.
            return (new RequestFactsCollector())->collect($messages, $toolSpecs);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to collect request facts; the call is unaffected', ['exception' => $e]);

            return null;
        }
    }

    /**
     * Resolve the effective configuration for a configuration-driven completion.
     *
     * Delegates to {@see ConfigurationResolver}; retained on the manager
     * because it is part of {@see LlmServiceManagerInterface}.
     */
    public function resolveEffectiveConfiguration(?LlmConfiguration $configuration = null): ?LlmConfiguration
    {
        return $this->configurationResolver->resolveEffectiveConfiguration($configuration);
    }

    public function registerProvider(ProviderInterface $provider): void
    {
        $this->providerRegistry->registerProvider($provider);
    }

    public function getProvider(?string $identifier = null): ProviderInterface
    {
        return $this->providerRegistry->getProvider($identifier);
    }

    /**
     * @return array<string, ProviderInterface>
     */
    public function getAvailableProviders(): array
    {
        return $this->providerRegistry->getAvailableProviders();
    }

    /**
     * Check if at least one provider is available.
     */
    public function hasAvailableProvider(): bool
    {
        return $this->providerRegistry->hasAvailableProvider();
    }

    /**
     * @return array<string, string>
     */
    public function getProviderList(): array
    {
        return $this->providerRegistry->getProviderList();
    }

    /**
     * Send a chat completion request.
     *
     * Legacy array-shaped messages are accepted for back-compat and
     * normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    public function chat(array $messages, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());

        // Single source of truth: with no explicit provider pinned, prefer the
        // backend-module-managed default DB configuration so it drives generation.
        // The per-call options override the configuration's stored defaults. When
        // no default configuration resolves and no provider is pinned, the call
        // throws (no extension-config fallback; see ADR-034).
        $defaultConfiguration = $this->configurationResolver->resolveDefaultConfiguration($providerKey);
        if ($defaultConfiguration instanceof LlmConfiguration) {
            return $this->chatWithConfiguration(
                $this->injectConfigSkillsIntoMessages($messages, $defaultConfiguration),
                $defaultConfiguration,
                $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->idempotency($options->getIdempotencyKey()) + $this->metadata->requestCount($options) + $this->metadata->callerSource($options),
                $optionsArray,
            );
        }

        // Ad-hoc pinned-provider path: screen the prompt too (ADR-087), matching
        // the configuration-driven entry points.
        $messages           = $this->screenInput($messages);
        $normalisedMessages = $this->messageShaper->normalise($messages);

        return $this->runThroughPipeline(
            $this->synthesizeTransientConfiguration(ProviderOperation::Chat, $providerKey),
            ProviderOperation::Chat,
            fn(): CompletionResponse => $this->getProvider($providerKey)->chatCompletion($this->applyAndScreenSystemPrompt($normalisedMessages, $optionsArray), $optionsArray),
            $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->idempotency($options->getIdempotencyKey()) + $this->metadata->requestCount($options) + $this->metadata->callerSource($options),
        );
    }

    /**
     * Send a simple completion request.
     */
    public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());

        // Single source of truth: prefer the default DB configuration (see chat()).
        $defaultConfiguration = $this->configurationResolver->resolveDefaultConfiguration($providerKey);
        if ($defaultConfiguration instanceof LlmConfiguration) {
            return $this->completeWithConfiguration(
                $this->injectConfigSkillsIntoPrompt($prompt, $defaultConfiguration),
                $defaultConfiguration,
                $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->idempotency($options->getIdempotencyKey()) + $this->metadata->callerSource($options),
                $optionsArray,
            );
        }

        // Ad-hoc pinned-provider path: screen the raw prompt too (ADR-087).
        $prompt = $this->screenInputPrompt($prompt);

        return $this->runThroughPipeline(
            $this->synthesizeTransientConfiguration(ProviderOperation::Completion, $providerKey),
            ProviderOperation::Completion,
            fn(): CompletionResponse => $this->getProvider($providerKey)->complete($prompt, $optionsArray),
            $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->idempotency($options->getIdempotencyKey()) + $this->metadata->callerSource($options),
        );
    }

    /**
     * Generate embeddings for text.
     *
     * @param string|array<int, string> $input
     */
    public function embed(string|array $input, ?EmbeddingOptions $options = null): EmbeddingResponse
    {
        $options ??= new EmbeddingOptions();
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());
        [$configuration, $providerKey, $optionsArray] = $this->applyDefaultConfiguration($providerKey, $optionsArray);

        // Cache metadata: EmbedCacheKeyBuilder returns an empty array when
        // cache_ttl <= 0 (the EmbeddingOptions::noCache() contract), so the key
        // is left out and CacheMiddleware becomes a no-op for this call. The
        // ad-hoc path keys by provider identifier.
        $cacheTtl = is_int($optionsArray['cache_ttl'] ?? null) ? $optionsArray['cache_ttl'] : 0;
        $metadata = $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->idempotency($options->getIdempotencyKey()) + $this->metadata->callerSource($options);
        $resolvedProvider = $providerKey ?? 'default';
        $metadata += $this->embedCacheKeyBuilder->build(
            $cacheTtl,
            $resolvedProvider,
            ['input' => $input, 'options' => $optionsArray],
            'nrllm_provider_' . $resolvedProvider,
        );

        // Terminal returns an array-shaped payload so CacheMiddleware (which
        // persists `array<string, mixed>`) can round-trip through the TYPO3
        // cache frontend. The typed response is reconstructed at this layer.
        $raw = $this->pipeline->run(
            ProviderCallContext::forConfiguration(
                ProviderOperation::Embedding,
                $configuration ?? $this->synthesizeTransientConfiguration(ProviderOperation::Embedding, $providerKey),
                $metadata,
            ),
            function () use ($input, $optionsArray, $providerKey): array {
                $provider = $this->getProvider($providerKey);
                if (!$provider->supportsFeature('embeddings')) {
                    throw new UnsupportedFeatureException(
                        sprintf('Provider "%s" does not support embeddings', $provider->getIdentifier()),
                        8701213030,
                    );
                }

                return $provider->embeddings($input, $optionsArray)->toArray();
            },
        );

        if (!is_array($raw)) {
            throw new ProviderException(
                'Embedding pipeline returned non-array payload — expected array<string, mixed>',
                2746395810,
            );
        }

        return EmbeddingResponse::fromArray($raw);
    }

    /**
     * Analyze an image with vision capabilities.
     *
     * Accepts either typed `VisionContent` instances or legacy array
     * fixtures (`{type: 'text'|'image_url', ...}`) for back-compat —
     * array entries are normalised via `VisionContent::fromArray()` so
     * the downstream provider always receives `list<VisionContent>` and
     * never has to defend against mixed input.
     *
     * @param list<VisionContent|array<string, mixed>> $content
     */
    public function vision(array $content, ?VisionOptions $options = null): VisionResponse
    {
        $options ??= new VisionOptions();
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());
        [$configuration, $providerKey, $optionsArray] = $this->applyDefaultConfiguration($providerKey, $optionsArray);

        $normalisedContent = array_values(array_map(
            static function (VisionContent|array $item): VisionContent {
                if ($item instanceof VisionContent) {
                    return $item;
                }

                /** @var array{type?: string, text?: string, image_url?: array{url?: string}|string} $item */
                return VisionContent::fromArray($item);
            },
            $content,
        ));

        // Screen the text prompt(s) sent alongside the images (ADR-089): a pasted
        // secret in the vision prompt is redacted / a denied prompt blocked before
        // it reaches the provider, matching the chat and tool send paths.
        $normalisedContent = array_map(
            function (VisionContent $item): VisionContent {
                if (!$item->isText() || $item->text === null) {
                    return $item;
                }

                $screened = $this->screenInputPrompt($item->text);

                return $screened === $item->text ? $item : VisionContent::text($screened);
            },
            $normalisedContent,
        );

        return $this->runThroughPipeline(
            $configuration ?? $this->synthesizeTransientConfiguration(ProviderOperation::Vision, $providerKey),
            ProviderOperation::Vision,
            function () use ($normalisedContent, $optionsArray, $providerKey): VisionResponse {
                $provider = $this->getProvider($providerKey);
                if (!$provider instanceof VisionCapableInterface) {
                    throw new UnsupportedFeatureException(
                        sprintf('Provider "%s" does not support vision', $provider->getIdentifier()),
                        5549344501,
                    );
                }

                return $provider->analyzeImage($normalisedContent, $optionsArray);
            },
            $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->idempotency($options->getIdempotencyKey()) + $this->metadata->callerSource($options),
        );
    }

    /**
     * Stream a chat completion response.
     *
     * Legacy array-shaped messages are accepted for back-compat and
     * normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChat(array $messages, ?ChatOptions $options = null): Generator
    {
        $options ??= new ChatOptions();
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());

        // Single source of truth: prefer the default DB configuration (see chat()),
        // so streaming and non-streaming calls resolve the same provider/model.
        // Budget attribution is forwarded so the streaming lifecycle can gate the
        // same over-budget users the non-streaming path rejects.
        $defaultConfiguration = $this->configurationResolver->resolveDefaultConfiguration($providerKey);
        if ($defaultConfiguration instanceof LlmConfiguration) {
            return $this->streamChatWithConfiguration(
                $this->injectConfigSkillsIntoMessages($messages, $defaultConfiguration),
                $defaultConfiguration,
                $optionsArray,
                $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->callerSource($options),
            );
        }

        // Ad-hoc: a pinned provider with no configuration entity — no fallback
        // chain, provider resolved by key. Screen the prompt before the opener
        // captures it (ADR-087), so a redaction reaches the provider and a DENY
        // throws at call time.
        $messages = $this->screenInput($messages);
        $open     = function () use ($messages, $optionsArray, $providerKey): Generator {
            $provider = $this->getProvider($providerKey);
            $this->assertStreamingCapable($provider, 1581627129);

            return $provider->streamChatCompletion(
                $this->applyAndScreenSystemPrompt($this->messageShaper->normalise($messages), $optionsArray),
                $optionsArray,
            );
        };

        $configuration = $this->synthesizeTransientConfiguration(ProviderOperation::Stream, $providerKey);

        if (!$this->streaming instanceof StreamingDispatcher) {
            return $open();
        }

        // Check capability eagerly so an unsupported provider throws at call time
        // (as the legacy path and non-streaming calls do), not lazily on the
        // first iteration inside the dispatcher.
        $this->assertStreamingCapable($this->getProvider($providerKey), 1581627129);

        $metadata = $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->callerSource($options);
        $metadata[StreamingDispatcher::METADATA_PROVIDER]     = $providerKey ?? 'default';
        $metadata[StreamingDispatcher::METADATA_PROMPT_CHARS] = $this->estimatePromptChars($messages);

        return $this->streaming->stream(
            ProviderCallContext::for(ProviderOperation::Stream, $metadata),
            $configuration,
            $open,
        );
    }

    /**
     * Chat completion with tool calling.
     *
     * Accepts both typed `ChatMessage` / `ToolSpec` instances and legacy
     * array fixtures for back-compat — each non-typed entry is routed
     * through the matching `fromArray()` factory so the downstream
     * provider always receives `list<ChatMessage>` + `list<ToolSpec>`.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec|array<string, mixed>>    $tools
     */
    public function chatWithTools(array $messages, array $tools, ?ToolOptions $options = null): CompletionResponse
    {
        $options ??= new ToolOptions();
        [$providerKey, $optionsArray] = $this->splitProviderKey($options->toArray());

        // Ad-hoc pinned-provider path: screen the prompt too (ADR-087).
        $messages           = $this->screenInput($messages);
        $normalisedMessages = $this->messageShaper->normalise($messages);
        $normalisedTools    = array_values(array_map(
            static function (ToolSpec|array $tool): ToolSpec {
                if ($tool instanceof ToolSpec) {
                    return $tool;
                }

                /** @var array{type?: string, function: array{name: string, description?: string, parameters?: array<string, mixed>}} $tool */
                return ToolSpec::fromArray($tool);
            },
            $tools,
        ));

        return $this->runThroughPipeline(
            $this->synthesizeTransientConfiguration(ProviderOperation::Tools, $providerKey),
            ProviderOperation::Tools,
            function () use ($normalisedMessages, $normalisedTools, $optionsArray, $providerKey): CompletionResponse {
                $provider = $this->getProvider($providerKey);
                if (!$provider instanceof ToolCapableInterface) {
                    throw new UnsupportedFeatureException(
                        sprintf('Provider "%s" does not support tool calling', $provider->getIdentifier()),
                        9324699785,
                    );
                }

                return $provider->chatCompletionWithTools($this->applyAndScreenSystemPrompt($normalisedMessages, $optionsArray), $normalisedTools, $optionsArray);
            },
            $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->idempotency($options->getIdempotencyKey()) + $this->metadata->callerSource($options),
        );
    }

    /**
     * Chat completion with tool calling against a specific LLM configuration.
     *
     * Mirrors {@see self::chatWithConfiguration()} — resolves the adapter via
     * {@see self::getAdapterFromConfiguration()} (so the configuration's vault
     * key + model + params drive the call) and runs through the middleware
     * pipeline (so Budget/Usage see the real Model and record real cost) — but
     * guards `ToolCapableInterface` and dispatches `chatCompletionWithTools()`.
     *
     * This is the keystone for the tool runtime: the keyed
     * {@see self::chatWithTools()} path cannot reach a DB-backed configuration's
     * vault key/model/pricing (it resolves a provider from ExtensionConfiguration
     * against a model-less transient configuration), so a tool loop that must
     * run on a selected configuration uses this entry point instead.
     *
     * The per-call {@see ToolOptions} take precedence over the configuration's
     * stored defaults, matching `chatWithConfiguration()`'s override semantics.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param list<ToolSpec|array<string, mixed>>    $tools
     * @param ?AgentRunReference                     $run             the agent run driving this round (ADR-153); null outside a run
     * @param ?InjectedContext                       $injectedContext sources this run injects on top of the configuration (ADR-164);
     *                                                                the ADR-144 ceiling binds against them too
     */
    public function chatWithToolsForConfiguration(array $messages, array $tools, LlmConfiguration $configuration, ?ToolOptions $options = null, ?AgentRunReference $run = null, ?InjectedContext $injectedContext = null): CompletionResponse
    {
        $options ??= new ToolOptions();
        $optionOverrides = $options->toArray();
        unset($optionOverrides['provider']);

        $messages           = $this->screenInput($messages);
        $normalisedMessages = $this->messageShaper->normalise($messages);
        $normalisedTools    = array_values(array_map(
            static function (ToolSpec|array $tool): ToolSpec {
                if ($tool instanceof ToolSpec) {
                    return $tool;
                }

                /** @var array{type?: string, function: array{name: string, description?: string, parameters?: array<string, mixed>}} $tool */
                return ToolSpec::fromArray($tool);
            },
            $tools,
        ));

        return $this->runThroughPipeline(
            $configuration,
            ProviderOperation::Tools,
            function (ProviderCallContext $ctx) use ($normalisedMessages, $normalisedTools, $optionOverrides): CompletionResponse {
                $config   = $this->planner->requireConfiguration($ctx);
                $llmModel = $this->planner->resolveModel($config, ProviderOperation::Tools, $ctx->telemetrySignals);
                $adapter  = $this->adapterRegistry->createAdapterFromModel($llmModel);
                if (!$adapter instanceof ToolCapableInterface) {
                    throw new UnsupportedFeatureException(
                        sprintf('Provider "%s" does not support tool calling', $adapter->getIdentifier()),
                        1782748801,
                    );
                }

                $callOptions = $this->planner->callOptions($config, $llmModel, $optionOverrides);
                // The tool schemas are on the wire for THIS send, so they are
                // counted against the same budget rather than left out of it
                // (ADR-107's $toolSpecs).
                $bounded = $this->fitToContextWindow(
                    $normalisedMessages,
                    $config,
                    $llmModel,
                    $callOptions,
                    // The estimator counts what goes on the wire, so the specs
                    // are handed over in their serialised shape rather than as
                    // value objects.
                    array_map(static fn(ToolSpec $spec): array => $spec->toArray(), $normalisedTools),
                    $ctx->telemetrySignals,
                );

                return $adapter->chatCompletionWithTools(
                    $this->applyAndScreenSystemPrompt($bounded, $callOptions),
                    $normalisedTools,
                    $callOptions,
                );
            },
            $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->idempotency($options->getIdempotencyKey()) + $this->metadata->callerSource($options),
            $run,
            $injectedContext,
            $this->collectRequestFacts(
                $normalisedMessages,
                array_map(static fn(ToolSpec $spec): array => $spec->toArray(), $normalisedTools),
            ),
        );
    }

    /**
     * Generate embeddings against a specific LLM configuration.
     *
     * Mirrors {@see self::chatWithToolsForConfiguration()} — resolves the
     * adapter via {@see self::getAdapterFromConfiguration()} (so the
     * configuration's vault key + model + params drive the call) and runs
     * through the middleware pipeline (so Budget/Usage see the real Model and
     * record real cost) — but guards the `embeddings` feature the same way
     * {@see self::embed()} does and dispatches `embeddings()`.
     *
     * This closes the gap where embedding consumers that persist vectors had
     * to duplicate provider/model settings into their own extension
     * configuration: the DB-backed configuration now carries them, and the
     * per-call {@see EmbeddingOptions} take precedence over the
     * configuration's stored defaults (an options `model` overrides the
     * configuration's model id), matching `chatWithConfiguration()`'s
     * override semantics.
     *
     * Caching mirrors {@see self::embed()}: a positive `cache_ttl` puts a
     * cache key on the call context so `CacheMiddleware` can short-circuit.
     * The key is derived from the configuration identifier plus the
     * *effective* model (options override or the configuration's model id),
     * so two configurations pointing at different models never share entries.
     *
     * @param string|array<int, string> $input
     */
    public function embedForConfiguration(string|array $input, LlmConfiguration $configuration, ?EmbeddingOptions $options = null): EmbeddingResponse
    {
        $options ??= new EmbeddingOptions();
        $optionOverrides = $options->toArray();
        unset($optionOverrides['provider']);

        $metadata = $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->idempotency($options->getIdempotencyKey()) + $this->metadata->callerSource($options);

        // Cache metadata mirrors embed(), but the configuration path keys by
        // configuration identifier plus the effective model (options override
        // or the configuration's model id) so two configurations pointing at
        // different models never share entries. EmbedCacheKeyBuilder returns an
        // empty array for cache_ttl <= 0 (EmbeddingOptions::noCache()).
        $cacheTtl = is_int($optionOverrides['cache_ttl'] ?? null) ? $optionOverrides['cache_ttl'] : 0;
        // Criteria-mode configurations have no direct model relation, so their
        // getModelId() is '' — resolve the concrete model for the key in that
        // case, or entries keyed under the empty id would be shared across
        // whatever model the criteria select over time. Fixed-mode configs
        // keep the relation's id without the extra resolution.
        //
        // This resolution sits OUTSIDE the terminal, which resolves again a few
        // lines below. Both MUST pass the same ProviderOperation: if they could
        // disagree, the key would name model A while the call ran on model B and
        // cache entries would be served across models (ADR-138).
        $effectiveModel = is_string($optionOverrides['model'] ?? null)
            ? $optionOverrides['model']
            : ($configuration->getModelId() !== ''
                ? $configuration->getModelId()
                : $this->planner->resolveModel($configuration, ProviderOperation::Embedding)->getModelId());
        // EmbedCacheKeyBuilder sanitizes the scope tag: configuration
        // identifiers use the dotted preset scheme (nr_ai_search.embeddings),
        // and the cache frontend rejects a tag containing a dot with an
        // InvalidArgumentException on set().
        $metadata += $this->embedCacheKeyBuilder->build(
            $cacheTtl,
            $configuration->getIdentifier(),
            ['input' => $input, 'options' => $optionOverrides, 'model' => $effectiveModel],
            'nrllm_configuration_' . $configuration->getIdentifier(),
        );

        // Terminal returns an array-shaped payload so CacheMiddleware (which
        // persists `array<string, mixed>`) can round-trip through the TYPO3
        // cache frontend. The typed response is reconstructed at this layer.
        $raw = $this->pipeline->run(
            ProviderCallContext::forConfiguration(ProviderOperation::Embedding, $configuration, $metadata),
            function (ProviderCallContext $ctx) use ($input, $optionOverrides): array {
                $config   = $this->planner->requireConfiguration($ctx);
                $llmModel = $this->planner->resolveModel($config, ProviderOperation::Embedding, $ctx->telemetrySignals);
                $adapter  = $this->adapterRegistry->createAdapterFromModel($llmModel);
                if (!$adapter->supportsFeature('embeddings')) {
                    throw new UnsupportedFeatureException(
                        sprintf('Provider "%s" does not support embeddings', $adapter->getIdentifier()),
                        7093846251,
                    );
                }

                $callOptions = $this->planner->callOptions($config, $llmModel, $optionOverrides);

                // Embedding-only default: fill the vector size from the model
                // when the caller's EmbeddingOptions left it unset (#390).
                if (!isset($callOptions['dimensions']) && $llmModel->getDimensions() > 0) {
                    $callOptions['dimensions'] = $llmModel->getDimensions();
                }

                return $adapter->embeddings($input, $callOptions)->toArray();
            },
        );

        if (!is_array($raw)) {
            throw new ProviderException(
                'Embedding pipeline returned non-array payload — expected array<string, mixed>',
                6482915370,
            );
        }

        return EmbeddingResponse::fromArray($raw);
    }

    /**
     * Check if a specific feature is supported by a provider.
     */
    public function supportsFeature(string $feature, ?string $provider = null): bool
    {
        return $this->providerRegistry->supportsFeature($feature, $provider);
    }

    /**
     * Get configuration for a provider.
     *
     * @return array<string, mixed>
     */
    public function getProviderConfiguration(string $identifier): array
    {
        return $this->providerRegistry->getProviderConfiguration($identifier);
    }

    /**
     * Dynamically configure a provider.
     *
     * @param array<string, mixed> $config
     */
    public function configureProvider(string $identifier, array $config): void
    {
        $this->providerRegistry->configureProvider($identifier, $config);
    }

    // ========================================
    // Database-Backed Provider Methods
    // ========================================

    /**
     * Get adapter instance from a database Model entity.
     *
     * This creates a configured adapter using the Provider and Model from the database.
     */
    public function getAdapterFromModel(Model $model): ProviderInterface
    {
        return $this->adapterRegistry->createAdapterFromModel($model);
    }

    /**
     * Get adapter instance from an LlmConfiguration entity.
     */
    public function getAdapterFromConfiguration(LlmConfiguration $configuration): ProviderInterface
    {
        return $this->planner->adapterFor($configuration, null);
    }

    /**
     * Execute chat completion using an LlmConfiguration entity.
     *
     * If the configuration has a fallback chain, retryable provider errors
     * on the primary (network, 5xx, 429) transparently re-run the request
     * against each fallback configuration in order.
     *
     * Legacy array-shaped messages are accepted for back-compat and
     * normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $metadata
     * @param array<string, mixed>                   $optionOverrides per-call options that take precedence over the configuration's stored defaults
     * @param ?AgentRunReference                     $run             the agent run driving this call (ADR-153); null outside a run
     * @param ?InjectedContext                       $injectedContext sources this run injects on top of the configuration (ADR-164);
     *                                                                the ADR-144 ceiling binds against them too
     */
    public function chatWithConfiguration(array $messages, LlmConfiguration $configuration, array $metadata = [], array $optionOverrides = [], ?AgentRunReference $run = null, ?InjectedContext $injectedContext = null): CompletionResponse
    {
        $messages           = $this->screenInput($messages);
        $normalisedMessages = $this->messageShaper->normalise($messages);

        return $this->runThroughPipeline(
            $configuration,
            ProviderOperation::Chat,
            function (ProviderCallContext $ctx) use ($normalisedMessages, $optionOverrides): CompletionResponse {
                $config   = $this->planner->requireConfiguration($ctx);
                $llmModel = $this->planner->resolveModel($config, ProviderOperation::Chat, $ctx->telemetrySignals);
                $adapter  = $this->adapterRegistry->createAdapterFromModel($llmModel);
                $options  = $this->planner->callOptions($config, $llmModel, $optionOverrides);
                $bounded  = $this->fitToContextWindow($normalisedMessages, $config, $llmModel, $options, [], $ctx->telemetrySignals);

                return $adapter->chatCompletion($this->applyAndScreenSystemPrompt($bounded, $options), $options);
            },
            $metadata,
            $run,
            $injectedContext,
            $this->collectRequestFacts($normalisedMessages),
        );
    }

    /**
     * Execute completion using an LlmConfiguration entity.
     *
     * Fallback chain is applied when configured; see chatWithConfiguration().
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $optionOverrides per-call options that take precedence over the configuration's stored defaults
     */
    public function completeWithConfiguration(string $prompt, LlmConfiguration $configuration, array $metadata = [], array $optionOverrides = []): CompletionResponse
    {
        $prompt = $this->screenInputPrompt($prompt);

        return $this->runThroughPipeline(
            $configuration,
            ProviderOperation::Completion,
            function (ProviderCallContext $ctx) use ($prompt, $optionOverrides): CompletionResponse {
                $config   = $this->planner->requireConfiguration($ctx);
                $llmModel = $this->planner->resolveModel($config, ProviderOperation::Completion, $ctx->telemetrySignals);
                $adapter  = $this->adapterRegistry->createAdapterFromModel($llmModel);
                $options  = $this->planner->callOptions($config, $llmModel, $optionOverrides);
                $this->reportPromptOverflow($prompt, $config, $llmModel, $options, $ctx->telemetrySignals);

                return $adapter->complete($prompt, $options);
            },
            $metadata,
            null,
            null,
            // A raw prompt is one user turn — the same list
            // reportPromptOverflow() measures on the way through, so the two
            // records describe the same send.
            $this->collectRequestFacts([ChatMessage::user($prompt)]),
        );
    }

    /**
     * Complete a prompt against a specific configuration from a ChatOptions object.
     *
     * The named-configuration counterpart to chat(): it takes the same route as
     * chat()'s default-configuration branch — build the system/user messages,
     * inject the configuration's skills, thread the per-user budget and
     * idempotency metadata — but against the caller's chosen configuration
     * instead of the resolved instance default. A pinned provider on the options
     * is irrelevant on the configuration path and is dropped, matching chat().
     */
    /**
     * Chat against a specific configuration from a ChatOptions object.
     *
     * The message-list counterpart of {@see self::completeForConfiguration()};
     * see the interface for the contract.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    public function chatForConfiguration(array $messages, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();
        [, $optionsArray] = $this->splitProviderKey($options->toArray());

        return $this->chatWithConfiguration(
            $this->injectConfigSkillsIntoMessages($messages, $configuration),
            $configuration,
            $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->idempotency($options->getIdempotencyKey()) + $this->metadata->callerSource($options),
            $optionsArray,
        );
    }

    public function completeForConfiguration(string $prompt, LlmConfiguration $configuration, ?ChatOptions $options = null): CompletionResponse
    {
        $options ??= new ChatOptions();
        [, $optionsArray] = $this->splitProviderKey($options->toArray());

        $messages     = [];
        $systemPrompt = $optionsArray['system_prompt'] ?? null;
        if (is_string($systemPrompt) && $systemPrompt !== '') {
            $messages[] = ChatMessage::system($systemPrompt);
        }

        $messages[] = ChatMessage::user($prompt);

        return $this->chatWithConfiguration(
            $this->injectConfigSkillsIntoMessages($messages, $configuration),
            $configuration,
            $this->metadata->budget($options->getBeUserUid(), $options->getPlannedCost()) + $this->metadata->idempotency($options->getIdempotencyKey()) + $this->metadata->callerSource($options),
            $optionsArray,
        );
    }

    /**
     * Invoke the provider middleware pipeline for a per-configuration call.
     *
     * The pipeline composes every service tagged
     * `nr_llm.provider_middleware` (fallback, budget, usage, cache, …) around
     * the given terminal. Callers pass the current configuration, the
     * operation kind (so middleware can filter by operation) and the
     * terminal closure that performs the actual provider invocation.
     *
     * Optional `$metadata` is forwarded onto the `ProviderCallContext` so
     * cross-cutting middleware (BudgetMiddleware, CacheMiddleware, …) can
     * read what each entry-point knows. Entry points that have no extra
     * context (legacy callers, fixed-shape calls) pass an empty array.
     *
     * @template T
     *
     * A non-null `$run` (ADR-153) makes this call part of an agent run: the
     * context inherits the run's correlation id instead of minting a fresh one,
     * so every round of one run lands on the same trace, and the run's uid joins
     * the metadata for the governance rows the pipeline may write.
     *
     * @param callable(ProviderCallContext): T $terminal
     * @param array<string, mixed>             $metadata
     *
     * @return T
     */
    private function runThroughPipeline(
        LlmConfiguration $configuration,
        ProviderOperation $operation,
        callable $terminal,
        array $metadata = [],
        ?AgentRunReference $run = null,
        ?InjectedContext $injectedContext = null,
        ?RequestFacts $facts = null,
    ): mixed {
        // The run's own uid is authoritative and goes on the LEFT: `$metadata`
        // is caller-supplied on the public entry points, and `+` keeps the left
        // operand, so the other order would let a caller passing both a run and
        // its own `agentRunUid` attribute the governance row to a run that did
        // not make the call. The four producers in CallMetadataFactory are
        // disjoint among themselves; an arbitrary caller array is not.
        $metadata = $this->metadata->agentRun($run) + $metadata;

        // Before anything is dispatched: a configuration that may not carry
        // the context it injects does not get to try (ADR-144). Placed here
        // rather than in each terminal because it is a property of the
        // configuration, not of the payload, and every configuration-driven
        // operation runs through this pipeline.
        $this->assertContextPermitted($configuration, $metadata, $operation, $run->uid ?? 0, $injectedContext);

        // A run's own id wins: inside an agent run every call belongs to that
        // run's trace, and a caller-supplied id would split it. Outside one,
        // the caller's id is used so it can find its own call afterwards
        // (ADR-176); with neither, ProviderCallContext generates one.
        $context = ProviderCallContext::forConfiguration(
            $operation,
            $configuration,
            $metadata,
            $run?->correlationId() ?? $this->callerCorrelationId($metadata),
        );

        // Recorded here rather than inside the terminal, and that placement is
        // the entire point (ADR-174): the terminal is where resolveModel() runs,
        // so anything measured in there is measured after a model was chosen.
        // This is the last moment that is still unambiguously before it.
        if ($facts instanceof RequestFacts) {
            $context->telemetrySignals->recordRequestFacts($facts);
        }

        return $this->pipeline->run($context, $terminal);
    }

    /**
     * Assert a resolved adapter can stream, else throw the typed
     * UnsupportedFeatureException. Shared by both streaming entry points so the
     * eager (call-time) check and the per-fallback opener check raise the same
     * error; the `@phpstan-assert` narrows the adapter for the caller.
     *
     * @phpstan-assert StreamingCapableInterface $adapter
     */
    private function assertStreamingCapable(ProviderInterface $adapter, int $code): void
    {
        if (!$adapter instanceof StreamingCapableInterface) {
            throw new UnsupportedFeatureException(
                sprintf('Provider "%s" does not support streaming', $adapter->getIdentifier()),
                $code,
            );
        }
    }

    /**
     * Sum the character length of a message list's textual content, for the
     * streaming lifecycle's prompt-token estimate (ADR-062). Computed here
     * because the manager holds the messages; the dispatcher only sees the
     * count, never the payload. Non-string (multimodal) content contributes
     * nothing — the estimate is deliberately rough, matching the ≈4 chars/token
     * heuristic the dispatcher applies to it.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     */
    private function estimatePromptChars(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            if ($message instanceof ChatMessage) {
                $chars += strlen($message->content);

                continue;
            }

            $content = $message['content'] ?? '';
            if (is_string($content)) {
                $chars += strlen($content);
            }
        }

        return $chars;
    }

    /**
     * Split the pinned provider key out of a call's options array.
     *
     * Every generic (provider-agnostic) entry point — chat(), complete(),
     * embed(), vision(), streamChat(), chatWithTools() — reads the pinned
     * provider from the options, then strips it so the remaining options can be
     * forwarded to the adapter. Returns the nullable provider key and the
     * options array with `provider` removed.
     *
     * @param array<string, mixed> $optionsArray
     *
     * @return array{0: ?string, 1: array<string, mixed>}
     */
    private function splitProviderKey(array $optionsArray): array
    {
        $providerKey = isset($optionsArray['provider']) && is_string($optionsArray['provider'])
            ? $optionsArray['provider']
            : null;
        unset($optionsArray['provider']);

        return [$providerKey, $optionsArray];
    }

    /**
     * Let the backend-managed default configuration drive an entry point that
     * takes no configuration argument.
     *
     * `chat()` has done this since ADR-034: with no provider pinned it resolves
     * the default configuration rather than handing `null` to the provider
     * registry, which throws. `vision()` and `embed()` did hand it `null`, so
     * the usage the feature services invite — options without a provider,
     * because model selection is nr-llm's job — failed with "No provider
     * specified and no default provider configured" on an installation that
     * has a perfectly good default. nr-llm-compat's ai_filemetadata bridge is
     * exactly that caller, and an image upload on a site running it died with
     * a 500 rather than a missing alt text.
     *
     * The caller's own choices win: an explicitly pinned provider skips this
     * entirely (the resolver returns null for a non-null key), and a model
     * named in the options is left alone.
     *
     * @param array<string, mixed> $optionsArray
     *
     * @return array{0: LlmConfiguration|null, 1: string|null, 2: array<string, mixed>}
     *                                                                                  the resolved configuration (null when none applies), the provider key to use, and the options
     */
    private function applyDefaultConfiguration(?string $providerKey, array $optionsArray): array
    {
        if ($providerKey !== null) {
            return [null, $providerKey, $optionsArray];
        }

        $configuration = $this->configurationResolver->resolveDefaultConfiguration(null);
        if (!$configuration instanceof LlmConfiguration) {
            return [null, null, $optionsArray];
        }

        // The resolver only returns a configuration that HAS a model, so both
        // lookups below are answered — but a model without a provider record
        // would leave the key null, and then the registry throws as before
        // rather than this method inventing a provider.
        $model      = $configuration->getLlmModel();
        $identifier = $model?->getProvider()?->getIdentifier();
        if ($identifier === null || $identifier === '') {
            return [null, null, $optionsArray];
        }

        $modelId = $model?->getModelId() ?? '';
        if ($modelId !== '' && ($optionsArray['model'] ?? null) === null) {
            $optionsArray['model'] = $modelId;
        }

        return [$configuration, $identifier, $optionsArray];
    }

    /**
     * Build a transient LlmConfiguration for direct (ad-hoc) provider calls.
     *
     * Direct API methods — `chat()`, `complete()`, `embed()`, `vision()`,
     * `chatWithTools()` — do not carry an LlmConfiguration entity, but the
     * pipeline's interface requires one. The synthesized instance is
     * unpersisted (no uid, never written), carries an empty fallback chain
     * (so FallbackMiddleware passes through) and has a human-readable
     * identifier so log / trace labels can distinguish ad-hoc traffic from
     * configuration-backed calls.
     *
     * Middleware that needs more context (beUserUid for BudgetMiddleware,
     * cache keys for CacheMiddleware, etc.) reads it from the
     * ProviderCallContext metadata — not from the configuration.
     */
    private function synthesizeTransientConfiguration(
        ProviderOperation $operation,
        ?string $providerKey,
    ): LlmConfiguration {
        $identifier = sprintf(
            'ad-hoc:%s:%s',
            $operation->value,
            $providerKey ?? 'default',
        );

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier($identifier);

        return $configuration;
    }

    /**
     * Stream chat completion using an LlmConfiguration entity.
     *
     * Routed through the streaming lifecycle (ADR-062): budget pre-flight before
     * the first chunk, usage + telemetry settlement at stream end. Fallback IS
     * applied, but only in the pre-first-chunk window — once a chunk has been
     * yielded a provider swap is impossible, so a mid-stream failure surfaces to
     * the caller rather than re-routing. Use chatWithConfiguration() for full
     * mid-call fallback protection.
     *
     * Legacy array-shaped messages are accepted for back-compat and
     * normalised via `ChatMessage::fromArray()` before dispatch.
     *
     * `$metadata` is threaded onto the streaming ProviderCallContext (budget
     * attribution, task uid); it is the trailing parameter so the pre-existing
     * three-argument callers stay source-compatible. Direct callers that omit it
     * get an empty map, which the budget gate reads as "no budget owner —
     * skip the check", matching chatWithConfiguration()'s contract.
     *
     * @param list<ChatMessage|array<string, mixed>> $messages
     * @param array<string, mixed>                   $optionOverrides per-call options that take precedence over the configuration's stored defaults
     * @param array<string, mixed>                   $metadata        cross-cutting streaming context (budget attribution, task uid)
     *
     * @return Generator<int, string, mixed, void>
     */
    public function streamChatWithConfiguration(array $messages, LlmConfiguration $configuration, array $optionOverrides = [], array $metadata = []): Generator
    {
        // Screen the prompt before it is captured by the opener or measured for
        // token estimation, so a redaction reaches the provider and a DENY throws
        // at call time rather than on first iteration (ADR-087).
        $messages = $this->screenInput($messages);

        // Streaming does not run through runThroughPipeline(), so the gate is
        // applied here — at call time, before a stream can open, matching how
        // the input guardrails already behave on this path (ADR-087).
        $this->assertContextPermitted($configuration, $metadata, ProviderOperation::Stream);

        $metadata[StreamingDispatcher::METADATA_PROMPT_CHARS] = $this->estimatePromptChars($messages);

        // Built before the opener rather than at the stream() call, so the
        // opener can capture it: the routing decision and the complexity
        // measurement both happen inside the opener, and the scratchpad on this
        // context is what carries them out to
        // StreamingDispatcher::recordTelemetry() (ADR-156). The metadata is
        // complete at this point — the context copies it, so a later write
        // would not reach the context anyway.
        $streamContext = ProviderCallContext::for(ProviderOperation::Stream, $metadata);

        // Before the opener, which is where the model gets resolved (ADR-174).
        // Measured on the normalised list because that is what the opener sends;
        // the collector reads either shape, but the byte count would differ.
        $facts = $this->collectRequestFacts($this->messageShaper->normalise($messages));
        if ($facts instanceof RequestFacts) {
            $streamContext->telemetrySignals->recordRequestFacts($facts);
        }

        $open = function (LlmConfiguration $config) use ($messages, $optionOverrides, $streamContext): Generator {
            $signals  = $streamContext->telemetrySignals;
            $llmModel = $this->planner->resolveModel($config, ProviderOperation::Stream, $signals);
            $adapter  = $this->adapterRegistry->createAdapterFromModel($llmModel);
            $options  = $this->planner->callOptions($config, $llmModel, $optionOverrides);

            $this->assertStreamingCapable($adapter, 1735300101);

            // Before the first chunk, deliberately: once the stream is open
            // there is nothing left to prune.
            $bounded = $this->fitToContextWindow($this->messageShaper->normalise($messages), $config, $llmModel, $options, [], $signals);

            return $adapter->streamChatCompletion(
                $this->applyAndScreenSystemPrompt($bounded, $options),
                $options,
            );
        };

        if (!$this->streaming instanceof StreamingDispatcher) {
            return $open($configuration);
        }

        // Check the PRIMARY provider's capability eagerly so an unsupported
        // provider throws at call time, not lazily on the first iteration inside
        // the dispatcher; fallback candidates are still checked per-attempt in
        // the opener above.
        // Deliberately NOT getAdapterFromConfiguration(): that is the generic,
        // operation-less entry point, and resolving without the operation here
        // could pick a different model than the opener above resolves with
        // ProviderOperation::Stream — the eager check would then assert against
        // an adapter the stream never runs on (ADR-138).
        $this->assertStreamingCapable($this->planner->adapterFor($configuration, ProviderOperation::Stream), 1735300101);

        return $this->streaming->stream(
            $streamContext,
            $configuration,
            $open,
        );
    }

    /**
     * Get provider adapter registry.
     */
    public function getAdapterRegistry(): ProviderAdapterRegistryInterface
    {
        return $this->adapterRegistry;
    }

    /**
     * The correlation id a caller chose, if it passed one.
     *
     * Read out of the metadata array rather than taken as a parameter: seven
     * call sites reach runThroughPipeline(), and metadata is the channel this
     * codebase already uses for call-scoped side-band values (idempotency,
     * budget, the agent run). A parameter would have been an eighth argument
     * that six of the seven never set.
     *
     * @param array<string, mixed> $metadata
     */
    private function callerCorrelationId(array $metadata): ?string
    {
        $value = $metadata[ProviderCallContext::METADATA_CORRELATION_ID] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

}

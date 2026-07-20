<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\FallbackChainExhaustedException;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;

/**
 * Walks the primary configuration's fallback chain on retryable failure.
 *
 * Retryable is decided by the shared {@see FailureClassifier} (ADR-095): a
 * connection failure, a 429 rate limit, a 5xx server error or an open circuit
 * routes to the next configuration. Non-retryable errors bubble up immediately
 * (auth, misconfiguration, unsupported feature, client-side 4xx) — fallback
 * won't fix those.
 *
 * Streaming calls (Generator returning) should not be routed through this
 * middleware: once chunks have been emitted to the caller, we cannot swap
 * providers mid-stream. Build a pipeline without FallbackMiddleware for
 * streaming, or use ProviderCallContext::operation === Stream as a
 * short-circuit condition if a mixed pipeline is ever needed.
 *
 * Fallback is shallow: a fallback configuration's own chain is ignored to
 * prevent recursion and cycles.
 *
 * The registered pipeline order, pinned by tag priority:
 *
 *   TelemetryMiddleware        <-- outermost; pure observer          (priority 110)
 *     IdempotencyMiddleware    <-- replays a stored result by key    (priority 105)
 *       CacheMiddleware        <-- short-circuits on hit             (priority 100)
 *         GuardrailMiddleware  <-- screens/redacts the response      (priority 90)
 *           BudgetMiddleware   <-- pre-flight denial                 (priority 75)
 *             FallbackMiddleware <-- swaps config on retryable failure (priority 50)
 *               UsageMiddleware  <-- records the call that ran       (priority 25)
 *                 CircuitBreaker <-- guards the provider call        (priority 20)
 *                   <terminal>
 */
#[AutoconfigureTag(name: ProviderMiddlewareInterface::TAG_NAME, attributes: ['priority' => 50])]
final readonly class FallbackMiddleware implements ProviderMiddlewareInterface
{
    public function __construct(
        private LlmConfigurationRepository $repository,
        private LoggerInterface $logger,
        private ProviderHealthServiceInterface $health,
    ) {}

    /**
     * @param callable(ProviderCallContext): mixed $next
     *
     * @throws FallbackChainExhaustedException when primary and all fallbacks fail
     * @throws Throwable                       on the first non-retryable failure
     */
    public function handle(
        ProviderCallContext $context,
        callable $next,
    ): mixed {
        // A call with no configuration entity (a specialized service) has no
        // fallback chain — there is nothing to swap to, so pass it straight
        // through, exactly as an empty chain does.
        $configuration = $context->configuration;
        if ($configuration === null || $configuration->getFallbackChainDTO()->isEmpty()) {
            return $next($context);
        }

        /** @var list<array{configuration: string, error: Throwable}> $attempts */
        $attempts = [];

        try {
            return $next($context);
        } catch (Throwable $e) {
            if (!$this->isRetryable($e)) {
                throw $e;
            }
            $attempts[] = [
                'configuration' => $configuration->getIdentifier(),
                'error'         => $e,
            ];
            $this->logger->warning(
                'LLM primary configuration failed, trying fallback chain',
                [
                    'configuration'  => $configuration->getIdentifier(),
                    'operation'      => $context->operation->value,
                    'correlationId'  => $context->correlationId,
                    'exception'      => $e,
                    'exceptionClass' => $e::class,
                ],
            );
        }

        // Edge case: a chain that contains only the primary identifier becomes
        // empty after filtering. No fallback was actually attempted — rethrow
        // the primary's error verbatim instead of wrapping one attempt as
        // "every configuration failed".
        $chain = $configuration->getFallbackChainDTO()->without($configuration->getIdentifier());
        if ($chain->isEmpty()) {
            $this->logger->warning(
                'LLM primary configuration failed; fallback chain contained only the primary, nothing left to try',
                [
                    'configuration'    => $configuration->getIdentifier(),
                    'operation'        => $context->operation->value,
                    'correlationId'    => $context->correlationId,
                    'configured_chain' => $configuration->getFallbackChainDTO()->configurationIdentifiers,
                ],
            );
            throw $attempts[0]['error'];
        }

        // Optionally prefer healthier providers among the fallback candidates
        // (ADR-063). A stable no-op by default: the health service returns the
        // chain untouched unless the operator opted into health-aware reorder.
        $chain = $this->health->reorder($chain);

        foreach ($chain->configurationIdentifiers as $identifier) {
            $fallback = $this->repository->findOneByIdentifier($identifier);
            if ($fallback === null) {
                $this->logger->warning(
                    'LLM fallback configuration not found, skipping',
                    [
                        'configuration' => $identifier,
                        'operation'     => $context->operation->value,
                        'correlationId' => $context->correlationId,
                    ],
                );
                continue;
            }
            if (!$fallback->isActive()) {
                $this->logger->warning(
                    'LLM fallback configuration is inactive, skipping',
                    [
                        'configuration' => $identifier,
                        'operation'     => $context->operation->value,
                        'correlationId' => $context->correlationId,
                    ],
                );
                continue;
            }

            // Count this as a fallback attempt for the outer TelemetryMiddleware
            // (ADR-058): a real dispatch to a sibling configuration, after the
            // not-found / inactive skips above. The primary attempt is not
            // counted.
            $context->telemetrySignals->recordFallbackAttempt();

            try {
                $result = $next($context->withConfiguration($fallback));
                $this->logger->info(
                    'LLM fallback configuration succeeded',
                    [
                        'primary'          => $configuration->getIdentifier(),
                        'fallback'         => $identifier,
                        'operation'        => $context->operation->value,
                        'correlationId'    => $context->correlationId,
                        'skipped_attempts' => \count($attempts),
                    ],
                );

                return $result;
            } catch (Throwable $e) {
                if (!$this->isRetryable($e)) {
                    throw $e;
                }
                $attempts[] = [
                    'configuration' => $identifier,
                    'error'         => $e,
                ];
                $this->logger->warning(
                    'LLM fallback configuration failed',
                    [
                        'configuration'  => $identifier,
                        'operation'      => $context->operation->value,
                        'correlationId'  => $context->correlationId,
                        'exception'      => $e,
                        'exceptionClass' => $e::class,
                    ],
                );
            }
        }

        throw FallbackChainExhaustedException::fromAttempts($attempts);
    }

    private function isRetryable(Throwable $e): bool
    {
        // One shared taxonomy (ADR-095): connection, rate-limit, 5xx and an open
        // circuit route to the next configuration; auth, client and config
        // errors would fail the same way everywhere. Previously an inline ladder
        // here retried only connection/429/circuit and never a 5xx.
        return FailureClassifier::classify($e)->isRetryable();
    }
}

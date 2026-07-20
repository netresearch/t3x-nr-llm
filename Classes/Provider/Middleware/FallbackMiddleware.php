<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\CircuitOpenException;
use Netresearch\NrLlm\Provider\Exception\FallbackChainExhaustedException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;

/**
 * Walks the primary configuration's fallback chain on retryable failure.
 *
 * Retryable = the request might succeed against a different provider:
 *  - ProviderConnectionException (network / timeout / 5xx / retries exhausted)
 *  - ProviderResponseException with HTTP code 429 (rate-limited here)
 *
 * Non-retryable errors bubble up immediately (misconfiguration, unsupported
 * feature, client-side 4xx, etc.) — fallback won't fix those.
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
     * @param callable(LlmConfiguration): mixed $next
     *
     * @throws FallbackChainExhaustedException when primary and all fallbacks fail
     * @throws Throwable                       on the first non-retryable failure
     */
    public function handle(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $next,
    ): mixed {
        if ($configuration->getFallbackChainDTO()->isEmpty()) {
            return $next($configuration);
        }

        /** @var list<array{configuration: string, error: Throwable}> $attempts */
        $attempts = [];

        try {
            return $next($configuration);
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
                $result = $next($fallback);
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
        if ($e instanceof ProviderConnectionException) {
            return true;
        }
        if ($e instanceof CircuitOpenException) {
            // An open circuit (ADR-063) means the provider just failed
            // repeatedly and is being skipped; routing to the next configuration
            // is exactly what tripping fast is for.
            return true;
        }
        if ($e instanceof ProviderResponseException) {
            // Rate limit: a different provider might not be throttled
            return $e->getCode() === 429;
        }

        return false;
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\FallbackChainExhaustedException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes a provider operation against a primary LlmConfiguration and,
 * on retryable failure, walks the configuration's fallback chain in order.
 *
 * Retryable = the request MIGHT succeed against a different provider:
 *  - ProviderConnectionException (network / timeout / 5xx / retries exhausted)
 *  - ProviderResponseException with HTTP code 429 (rate-limited by this provider)
 *
 * Non-retryable errors bubble up immediately (misconfiguration, unsupported
 * feature, client-side 4xx, etc.) — fallback won't fix those.
 *
 * Streaming (Generator returning) calls should NOT be wrapped: once chunks
 * have been emitted to the caller we can't swap providers mid-stream.
 *
 * Fallback is shallow: a fallback configuration's own chain is ignored to
 * prevent recursion and cycles.
 */
final readonly class FallbackChainExecutor
{
    public function __construct(
        private LlmConfigurationRepository $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @template T
     *
     * @param callable(LlmConfiguration): T $execute
     *
     * @throws FallbackChainExhaustedException when primary and all fallbacks fail
     * @throws Throwable                       on the first non-retryable failure
     *
     * @return T
     */
    public function execute(LlmConfiguration $primary, callable $execute): mixed
    {
        if ($primary->getFallbackChainDTO()->isEmpty()) {
            return $execute($primary);
        }

        /** @var list<array{configuration: string, error: Throwable}> $attempts */
        $attempts = [];

        try {
            return $execute($primary);
        } catch (Throwable $e) {
            if (!$this->isRetryable($e)) {
                throw $e;
            }
            $attempts[] = [
                'configuration' => $primary->getIdentifier(),
                'error' => $e,
            ];
            $this->logger->warning(
                'LLM primary configuration failed, trying fallback chain',
                [
                    'configuration' => $primary->getIdentifier(),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ],
            );
        }

        $chain = $primary->getFallbackChainDTO()->without($primary->getIdentifier());

        foreach ($chain->configurationIdentifiers as $identifier) {
            $fallback = $this->repository->findOneByIdentifier($identifier);
            if ($fallback === null) {
                $this->logger->warning(
                    'LLM fallback configuration not found, skipping',
                    ['configuration' => $identifier],
                );
                continue;
            }
            if (!$fallback->isActive()) {
                $this->logger->warning(
                    'LLM fallback configuration is inactive, skipping',
                    ['configuration' => $identifier],
                );
                continue;
            }

            try {
                $result = $execute($fallback);
                $this->logger->info(
                    'LLM fallback configuration succeeded',
                    [
                        'primary' => $primary->getIdentifier(),
                        'fallback' => $identifier,
                        'skipped_attempts' => count($attempts),
                    ],
                );
                return $result;
            } catch (Throwable $e) {
                if (!$this->isRetryable($e)) {
                    throw $e;
                }
                $attempts[] = [
                    'configuration' => $identifier,
                    'error' => $e,
                ];
                $this->logger->warning(
                    'LLM fallback configuration failed',
                    [
                        'configuration' => $identifier,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
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
        if ($e instanceof ProviderResponseException) {
            // Rate limit: a different provider might not be throttled
            return $e->getCode() === 429;
        }
        return false;
    }
}

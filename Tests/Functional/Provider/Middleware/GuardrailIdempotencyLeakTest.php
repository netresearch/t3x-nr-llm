<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\IdempotencyMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;

/**
 * Regression proof for the guardrail/idempotency ordering fix (ADR-085): the
 * value {@see IdempotencyMiddleware} persists must be the guardrail-REDACTED
 * response, never the plaintext secret the model echoed.
 *
 * It drives the real DI-resolved {@see MiddlewarePipeline} and inspects the
 * ACTUAL nrllm_idempotency cache entry — not the value returned to the caller,
 * which the pre-fix layout redacted on the way out while still storing the raw
 * secret inside. On the pre-fix layout (guardrail priority 115, outside
 * Idempotency 105) the stored value is the plaintext secret and this test fails;
 * with the guardrail moved inside the persistence layers it stores `sk-***`.
 */
#[CoversClass(GuardrailMiddleware::class)]
#[CoversClass(IdempotencyMiddleware::class)]
final class GuardrailIdempotencyLeakTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function idempotencyPersistsTheGuardrailRedactedResponseNotThePlaintextSecret(): void
    {
        $pipeline = $this->get(MiddlewarePipeline::class);
        self::assertInstanceOf(MiddlewarePipeline::class, $pipeline);

        $cacheManager = $this->get(Typo3CacheManager::class);
        self::assertInstanceOf(Typo3CacheManager::class, $cacheManager);
        $cache = $cacheManager->getCache('nrllm_idempotency');

        $key    = 'leak-regression-key';
        $secret = 'sk-ABCDEFGHIJKLMNOP0123456789';
        // Mirror IdempotencyMiddleware::entryIdentifier() so the test can read the
        // value that was actually PERSISTED (the replay path would re-redact on
        // the pre-fix layout and hide the leak).
        $entryId = 'idem_' . substr((string)preg_replace('/[^a-zA-Z0-9_-]/', '_', $key), 0, 64)
            . '_' . substr(hash('sha256', $key), 0, 16);
        $cache->remove($entryId);

        $context = new ProviderCallContext(
            ProviderOperation::Chat,
            'corr-leak',
            [IdempotencyMiddleware::METADATA_IDEMPOTENCY_KEY => $key],
        );

        $result = $pipeline->run(
            $context,
            new LlmConfiguration(),
            static fn(LlmConfiguration $c): CompletionResponse => new CompletionResponse(
                content: 'your token is ' . $secret . ' keep it safe',
                model: 'test-model',
                usage: UsageStatistics::fromTokens(1, 1),
                provider: 'test',
            ),
        );

        // The caller receives the redacted response ...
        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertStringNotContainsString($secret, $result->content);
        self::assertStringContainsString('sk-***', $result->content);

        // ... AND the value persisted in the idempotency cache is redacted too.
        $stored = $cache->get($entryId);
        self::assertInstanceOf(CompletionResponse::class, $stored);
        self::assertStringNotContainsString($secret, $stored->content);
        self::assertStringContainsString('sk-***', $stored->content);

        $cache->remove($entryId);
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Service\Privacy\RunPrivacyLevelsInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * The answer cache for chat and completion (ADR-249): a CompletionResponse is
 * stored and replayed only for a call the manager marked, and only where the
 * privacy level of the provider that served it keeps content verbatim.
 */
#[CoversClass(CacheMiddleware::class)]
final class CacheMiddlewareAnswerTest extends AbstractUnitTestCase
{
    /** @var array<string, array{data: array<string, mixed>, ttl: int, tags: list<string>}> */
    private array $store = [];

    /** @var list<string> */
    private array $askedProviders = [];

    #[Test]
    public function aMarkedCallIsAnsweredFromTheCacheTheSecondTime(): void
    {
        $runs     = 0;
        $terminal = static function () use (&$runs): CompletionResponse {
            ++$runs;

            return self::answer('Hallo Welt');
        };

        $first   = $this->pipeline(PrivacyLevel::FULL)->run($this->context(), $terminal);
        $context = $this->context();
        $second  = $this->pipeline(PrivacyLevel::FULL)->run($context, $terminal);

        self::assertSame(1, $runs, 'The second call must not reach the provider.');
        self::assertInstanceOf(CompletionResponse::class, $second);
        self::assertSame('Hallo Welt', $second->content);
        self::assertSame('gpt-x', $second->model);
        self::assertSame('openai', $second->provider);
        self::assertSame('stop', $second->finishReason);
        self::assertSame([GeneratorProvenance::METADATA_KEY => self::provenance('openai'), 'answerCache' => 'hit'], $second->metadata);
        self::assertSame('thought', $second->thinking);
        self::assertTrue($context->telemetrySignals->cacheHit);
        self::assertSame(0, $second->usage->totalTokens, 'A replay consumed nothing.');
        self::assertInstanceOf(CompletionResponse::class, $first);
        self::assertSame([GeneratorProvenance::METADATA_KEY => self::provenance('openai')], $first->metadata, 'The answer that was made is returned as made.');
        self::assertSame(['nrllm_answers', 'nrllm_answers_shop_writer'], $this->store['answer:1']['tags']);
        self::assertSame(600, $this->store['answer:1']['ttl']);
        // The serving provider on the store, the entry's provider on the replay.
        self::assertSame(['openai', 'openai'], $this->askedProviders);
    }

    /**
     * @return array<string, array{PrivacyLevel}>
     */
    public static function levelsThatKeepNoVerbatimContent(): array
    {
        return [
            'none'     => [PrivacyLevel::NONE],
            'metadata' => [PrivacyLevel::METADATA],
            'redacted' => [PrivacyLevel::REDACTED],
        ];
    }

    #[Test]
    #[DataProvider('levelsThatKeepNoVerbatimContent')]
    public function aLevelBelowFullNeitherStoresNorReplays(PrivacyLevel $level): void
    {
        $this->store['answer:1'] = ['data' => ['answer' => ['content' => 'old']], 'ttl' => 600, 'tags' => []];

        $result = $this->pipeline($level)->run($this->context(), static fn (): CompletionResponse => self::answer('neu'));

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame('neu', $result->content);
        self::assertSame(['data' => ['answer' => ['content' => 'old']], 'ttl' => 600, 'tags' => []], $this->store['answer:1'], 'Nothing may be written.');
    }

    #[Test]
    public function withoutAPrivacyResolverNothingIsCached(): void
    {
        $cache    = $this->cache();
        $runs     = 0;
        $terminal = static function () use (&$runs): CompletionResponse {
            ++$runs;

            return self::answer('x');
        };
        $pipeline = new MiddlewarePipeline([new CacheMiddleware($cache)]);
        $pipeline->run($this->context(), $terminal);
        $pipeline->run($this->context(), $terminal);

        self::assertSame(2, $runs);
        self::assertSame([], $this->store);
    }

    /**
     * @return array<string, array{CompletionResponse}>
     */
    public static function answersThatAreNotKept(): array
    {
        return [
            'truncated'  => [new CompletionResponse('half', 'gpt-x', new UsageStatistics(1, 1, 2), 'length', 'openai', null, self::served())],
            'filtered'   => [new CompletionResponse('', 'gpt-x', new UsageStatistics(1, 1, 2), 'content_filter', 'openai', null, self::served())],
            'empty'      => [new CompletionResponse('  ', 'gpt-x', new UsageStatistics(1, 1, 2), 'stop', 'openai', null, self::served())],
            'tool calls' => [new CompletionResponse('Moment', 'gpt-x', new UsageStatistics(1, 1, 2), 'stop', 'openai', [new ToolCall('c1', 'f', [])], self::served())],
        ];
    }

    #[Test]
    #[DataProvider('answersThatAreNotKept')]
    public function anAnswerThatIsNotACompleteTextIsNotKept(CompletionResponse $response): void
    {
        $this->pipeline(PrivacyLevel::FULL)->run($this->context(), static fn (): CompletionResponse => $response);

        self::assertSame([], $this->store);
    }

    #[Test]
    public function inJsonModeOnlyAnAnswerThatParsesIsKept(): void
    {
        $context = new ProviderCallContext(
            operation: ProviderOperation::Chat,
            correlationId: 'test',
            configuration: $this->configuration(),
            metadata: $this->answerMetadata() + [CacheMiddleware::METADATA_ANSWER_CACHE_JSON => true],
        );

        $this->pipeline(PrivacyLevel::FULL)->run($context, static fn (): CompletionResponse => self::answer('Here is it: {"a": 1'));
        self::assertSame([], $this->store);

        $this->pipeline(PrivacyLevel::FULL)->run($context, static fn (): CompletionResponse => self::answer('{"a": 1}'));
        self::assertArrayHasKey('answer:1', $this->store);
    }

    #[Test]
    public function anUnmarkedCallWithAKeyDoesNotKeepACompletion(): void
    {
        $context = new ProviderCallContext(
            operation: ProviderOperation::Chat,
            correlationId: 'test',
            configuration: $this->configuration(),
            metadata: [CacheMiddleware::METADATA_CACHE_KEY => 'answer:1'],
        );

        $this->pipeline(PrivacyLevel::FULL)->run($context, static fn (): CompletionResponse => self::answer('x'));

        self::assertSame([], $this->store);
    }

    #[Test]
    public function aStoredEntryThatIsNoAnswerIsNotReplayed(): void
    {
        $this->store['answer:1'] = ['data' => ['vector' => [0.1]], 'ttl' => 600, 'tags' => []];

        $result = $this->pipeline(PrivacyLevel::FULL)->run($this->context(), static fn (): CompletionResponse => self::answer('frisch'));

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame('frisch', $result->content);
    }

    #[Test]
    public function theLevelIsAskedForTheProviderThatServedNotTheConfigurations(): void
    {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('criteria');

        $context = new ProviderCallContext(
            operation: ProviderOperation::Chat,
            correlationId: 'test',
            configuration: $configuration,
            metadata: $this->answerMetadata(),
        );
        $this->pipeline(PrivacyLevel::FULL)->run($context, static fn (): CompletionResponse => self::answer('x'));

        // A configuration in criteria mode names no model; the provider that
        // served is the one asked.
        self::assertSame(['openai'], $this->askedProviders);
    }

    /**
     * A model chosen by criteria or a fallback can answer on a provider whose
     * level is tighter than the configuration's.
     */
    #[Test]
    public function anAnswerFromATighterServingProviderIsNotKept(): void
    {
        $this->pipeline(PrivacyLevel::FULL, ['local' => PrivacyLevel::NONE])
            ->run($this->context(), static fn (): CompletionResponse => self::answer('x', 'local'));

        self::assertSame([], $this->store);
    }

    #[Test]
    public function anAnswerThatDoesNotNameItsProviderIsNotKept(): void
    {
        $this->pipeline(PrivacyLevel::FULL)->run(
            $this->context(),
            static fn (): CompletionResponse => new CompletionResponse('x', 'gpt-x', new UsageStatistics(1, 1, 2), 'stop', 'openai'),
        );

        self::assertSame([], $this->store);
    }

    #[Test]
    public function anEntryIsNotReplayedOnceItsServingProviderWasTightened(): void
    {
        $this->pipeline(PrivacyLevel::FULL)->run($this->context(), static fn (): CompletionResponse => self::answer('alt', 'local'));
        self::assertArrayHasKey('answer:1', $this->store);

        $result = $this->pipeline(PrivacyLevel::FULL, ['local' => PrivacyLevel::METADATA])
            ->run($this->context(), static fn (): CompletionResponse => self::answer('neu'));

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame('neu', $result->content);
    }

    private static function answer(string $content, string $servedBy = 'openai'): CompletionResponse
    {
        return new CompletionResponse($content, 'gpt-x', new UsageStatistics(10, 5, 15, 0.002), 'stop', 'openai', null, [GeneratorProvenance::METADATA_KEY => self::provenance($servedBy)], 'thought');
    }

    /**
     * @return array<string, mixed>
     */
    private static function served(): array
    {
        return [GeneratorProvenance::METADATA_KEY => self::provenance('openai')];
    }

    /**
     * @return array<string, int|string>
     */
    private static function provenance(string $provider): array
    {
        return ['version' => 1, 'providerIdentifier' => $provider, 'modelId' => 'gpt-x', 'reportedModelId' => 'gpt-x'];
    }

    /**
     * @param array<string, PrivacyLevel> $perProvider levels that differ from $level
     */
    private function pipeline(PrivacyLevel $level, array $perProvider = []): MiddlewarePipeline
    {
        $levels = new class ($level, $perProvider, $this) implements RunPrivacyLevelsInterface {
            /**
             * @param array<string, PrivacyLevel> $perProvider
             */
            public function __construct(private readonly PrivacyLevel $level, private readonly array $perProvider, private readonly CacheMiddlewareAnswerTest $test) {}

            public function forRun(int $runUid): PrivacyLevel
            {
                return PrivacyLevel::NONE;
            }

            public function forProvider(string $providerIdentifier): PrivacyLevel
            {
                $this->test->recordAskedProvider($providerIdentifier);

                return $this->perProvider[$providerIdentifier] ?? $this->level;
            }
        };

        return new MiddlewarePipeline([new CacheMiddleware($this->cache(), $levels)]);
    }

    public function recordAskedProvider(string $provider): void
    {
        $this->askedProviders[] = $provider;
    }

    private function cache(): CacheManagerInterface
    {
        $cache = self::createStub(CacheManagerInterface::class);
        $cache->method('get')->willReturnCallback(fn (string $key): ?array => $this->store[$key]['data'] ?? null);
        $cache->method('set')->willReturnCallback(function (string $key, array $data, int $lifetime = 3600, array $tags = []): void {
            /** @var array<string, mixed> $data */
            /** @var list<string> $tags */
            $this->store[$key] = ['data' => $data, 'ttl' => $lifetime, 'tags' => $tags];
        });

        return $cache;
    }

    private function context(): ProviderCallContext
    {
        return new ProviderCallContext(
            operation: ProviderOperation::Chat,
            correlationId: 'test',
            configuration: $this->configuration(),
            metadata: $this->answerMetadata(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function answerMetadata(): array
    {
        return [
            CacheMiddleware::METADATA_CACHE_KEY    => 'answer:1',
            CacheMiddleware::METADATA_CACHE_TTL    => 600,
            CacheMiddleware::METADATA_CACHE_TAGS   => ['nrllm_answers', 'nrllm_answers_shop_writer'],
            CacheMiddleware::METADATA_ANSWER_CACHE => true,
        ];
    }

    private function configuration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setIdentifier('openai');

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('shop.writer');
        $configuration->setLlmModel($model);

        return $configuration;
    }

    #[Test]
    public function aCachedAnswerIsDeniedAfterMandatoryPolicyTightensWithoutAConfigurationEdit(): void
    {
        $policy = new class {
            public bool $tightened = false;
        };
        $dispatches = 0;
        $guardrail  = self::createStub(
            GuardrailInterface::class,
        );
        $guardrail->method('getIdentifier')->willReturn('mutable-terms');
        $guardrail->method('isMandatory')->willReturn(true);
        $guardrail
            ->method('checkOutput')
            ->willReturnCallback(
                static fn (): GuardrailResult => $policy->tightened ? GuardrailResult::deny(
                    'Current term policy denies the answer',
                ) : GuardrailResult::allow(),
            );
        $governance = self::createMock(
            GovernanceEventRepositoryInterface::class,
        );
        $governance
            ->expects(self::once())
            ->method('record')
            ->with(
                self::callback(
                    static fn (
                        GovernanceEvent $event,
                    ): bool => $event->decision === 'response_blocked' && $event->correlationId === 'test',
                ),
            );
        $levels = self::createStub(RunPrivacyLevelsInterface::class);
        $levels->method('forProvider')->willReturn(PrivacyLevel::FULL);
        $screening = new GuardrailMiddleware(
            [$guardrail],
            governanceEvents: $governance,
        );
        $middleware = [new CacheMiddleware($this->cache(), $levels, $screening), $screening];
        usort(
            $middleware,
            static fn (
                $a,
                $b,
            ): int => $b::getDefaultPriority() <=> $a::getDefaultPriority(),
        );
        $pipeline = new MiddlewarePipeline($middleware);
        $terminal = static function () use (&$dispatches): CompletionResponse {
            ++$dispatches;

            return self::answer('Previously permitted answer');
        };
        $pipeline->run($this->context(), $terminal);
        self::assertArrayHasKey('answer:1', $this->store);
        $policy->tightened = true;
        try {
            $pipeline->run($this->context(), $terminal);
            self::fail(
                'The current mandatory policy must screen cached replay.',
            );
        } catch (GuardrailViolationException) {
            self::assertSame(
                1,
                $dispatches,
                'Policy replay must not dispatch another provider call.',
            );
        }
    }

    #[Test]
    public function aRetryVerdictOnACachedAnswerAsksTheProviderOnce(): void
    {
        $policy = new class {
            public bool $tightened = false;
        };
        $guardrail = self::createStub(GuardrailInterface::class);
        $guardrail->method('getIdentifier')->willReturn('freshness');
        $guardrail->method('isMandatory')->willReturn(true);
        $guardrail
            ->method('checkOutput')
            ->willReturnCallback(
                static fn (CompletionResponse $response): GuardrailResult => $policy->tightened && $response->content === 'Stale answer'
                    ? GuardrailResult::retry('The answer is out of date')
                    : GuardrailResult::allow(),
            );
        $levels = self::createStub(RunPrivacyLevelsInterface::class);
        $levels->method('forProvider')->willReturn(PrivacyLevel::FULL);
        $screening  = new GuardrailMiddleware([$guardrail]);
        $middleware = [new CacheMiddleware($this->cache(), $levels, $screening), $screening];
        usort($middleware, static fn ($a, $b): int => $b::getDefaultPriority() <=> $a::getDefaultPriority());
        $pipeline   = new MiddlewarePipeline($middleware);
        $answers    = ['Stale answer', 'Fresh answer'];
        $dispatches = 0;
        $terminal   = static function () use (&$dispatches, $answers): CompletionResponse {
            return self::answer($answers[$dispatches++]);
        };

        $pipeline->run($this->context(), $terminal);
        $policy->tightened = true;
        $context           = $this->context();
        $result            = $pipeline->run($context, $terminal);

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame('Fresh answer', $result->content);
        self::assertSame(2, $dispatches, 'The retry must reach the provider exactly once.');
        self::assertFalse($context->telemetrySignals->cacheHit, 'A refetched answer is not a cache hit.');
        $stored = $this->store['answer:1']['data'] ?? null;
        self::assertIsArray($stored);
        self::assertSame('Fresh answer', $stored['answer']['content'] ?? null);
    }
}

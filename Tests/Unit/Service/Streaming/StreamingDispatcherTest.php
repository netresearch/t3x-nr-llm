<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Streaming;

use Generator;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;

#[CoversClass(StreamingDispatcher::class)]
final class StreamingDispatcherTest extends AbstractUnitTestCase
{
    private RecordingUsageTracker $usage;

    private InMemoryTelemetryRepository $telemetry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usage     = new RecordingUsageTracker();
        $this->telemetry = new InMemoryTelemetryRepository();
    }

    #[Test]
    public function drainsEveryChunkFromTheInnerStream(): void
    {
        $dispatcher = $this->dispatcher();

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context(),
            $this->configuration('primary'),
            $this->staticStream(['Hello', ' ', 'World']),
        ));

        self::assertSame(['Hello', ' ', 'World'], $chunks);
    }

    #[Test]
    public function rejectsAnOverBudgetCallerEagerlyBeforeOpeningTheStream(): void
    {
        $opened     = false;
        $dispatcher = $this->dispatcher($this->budget(BudgetCheckResult::denied('cost_per_day', 5.0, 4.0)));

        try {
            // Budget is checked in stream() itself, before a generator exists,
            // so the throw happens at call time — not on first iteration.
            $dispatcher->stream(
                $this->context([BudgetMiddleware::METADATA_BE_USER_UID => 7]),
                $this->configuration('primary'),
                function () use (&$opened): Generator {
                    $opened = true;
                    yield 'never';
                },
            );
            self::fail('Expected BudgetExceededException.');
        } catch (BudgetExceededException) {
            // expected
        }

        self::assertFalse($opened, 'The stream must not be opened for an over-budget caller.');
        self::assertSame([], $this->usage->calls);
        self::assertSame([], $this->telemetry->records);
    }

    #[Test]
    public function recordsEstimatedUsageAndTelemetryOnSuccess(): void
    {
        $model = $this->model(pricing: true, cost: 0.25);
        $dispatcher = $this->dispatcher();

        // 12 prompt chars => ceil(12/4) = 3 tokens; "Hello World" = 11 chars => ceil(11/4) = 3 tokens.
        iterator_to_array($dispatcher->stream(
            $this->context([
                BudgetMiddleware::METADATA_BE_USER_UID       => 42,
                StreamingDispatcher::METADATA_PROMPT_CHARS   => 12,
            ]),
            $this->configuration('primary', providerType: 'openai', modelId: 'gpt-4o', model: $model, uid: 9),
            $this->staticStream(['Hello', ' World']),
        ));

        self::assertCount(1, $this->usage->calls);
        $call = $this->usage->calls[0];
        self::assertSame('stream', $call['serviceType']);
        self::assertSame('openai', $call['provider']);
        self::assertSame(3, $call['metrics']['promptTokens']);
        self::assertSame(3, $call['metrics']['completionTokens']);
        self::assertSame(6, $call['metrics']['tokens']);
        self::assertSame(0.25, $call['metrics']['cost']);
        self::assertSame(9, $call['configurationUid']);
        self::assertSame('gpt-4o', $call['modelId']);
        self::assertSame(42, $call['beUserUid']);

        self::assertCount(1, $this->telemetry->records);
        $record = $this->telemetry->records[0];
        self::assertSame('stream', $record->operation);
        self::assertSame('corr-1', $record->correlationId);
        self::assertSame('primary', $record->configurationIdentifier);
        self::assertTrue($record->success);
        self::assertSame('', $record->errorClass);
        self::assertFalse($record->cacheHit);
        self::assertSame(0, $record->fallbackAttempts);
        self::assertNotNull($record->timeToFirstTokenMs);
        self::assertGreaterThanOrEqual(0, $record->timeToFirstTokenMs);
    }

    #[Test]
    public function omitsCostWhenTheServedModelHasNoPricing(): void
    {
        $dispatcher = $this->dispatcher();

        iterator_to_array($dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
            $this->configuration('primary', providerType: 'ollama', model: $this->model(pricing: false)),
            $this->staticStream(['hi']),
        ));

        self::assertCount(1, $this->usage->calls);
        self::assertArrayNotHasKey('cost', $this->usage->calls[0]['metrics']);
    }

    #[Test]
    public function fallsBackToTheAdHocProviderMetadataWhenTheConfigHasNoProviderType(): void
    {
        $dispatcher = $this->dispatcher();

        iterator_to_array($dispatcher->stream(
            $this->context([
                StreamingDispatcher::METADATA_PROMPT_CHARS => 4,
                StreamingDispatcher::METADATA_PROVIDER      => 'groq',
            ]),
            // Transient ad-hoc configuration: empty provider type.
            $this->configuration('ad-hoc:stream:groq'),
            $this->staticStream(['x']),
        ));

        self::assertSame('groq', $this->usage->calls[0]['provider']);
    }

    #[Test]
    public function settlesPartialUsageWhenTheConsumerBreaksEarly(): void
    {
        $dispatcher = $this->dispatcher();

        $generator = $dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 0]),
            $this->configuration('primary', providerType: 'openai'),
            $this->staticStream(['aaaa', 'bbbb', 'cccc']),
        );

        $seen = [];
        foreach ($generator as $chunk) {
            $seen[] = $chunk;
            break; // abandon after the first chunk
        }
        // Destroying the suspended generator runs its finally (settlement).
        unset($generator);

        self::assertSame(['aaaa'], $seen);
        self::assertCount(1, $this->usage->calls, 'Partial usage must be recorded on early break.');
        // Only the first 4-char chunk was drained => 1 completion token.
        self::assertSame(1, $this->usage->calls[0]['metrics']['completionTokens']);

        self::assertCount(1, $this->telemetry->records);
        $record = $this->telemetry->records[0];
        self::assertFalse($record->success, 'An abandoned stream did not complete.');
        self::assertSame('', $record->errorClass, 'An early break is not an exception.');
    }

    #[Test]
    public function recordsFailureTelemetryAndPartialUsageWhenTheStreamThrowsMidway(): void
    {
        $dispatcher = $this->dispatcher();

        $open = function (): Generator {
            yield 'partial';

            throw new RuntimeException('mid-stream boom', 1495872185);
        };

        $caught = false;
        try {
            iterator_to_array($dispatcher->stream(
                $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 0]),
                $this->configuration('primary', providerType: 'openai'),
                $open,
            ));
        } catch (RuntimeException $e) {
            $caught = true;
            self::assertSame('mid-stream boom', $e->getMessage());
        }

        self::assertTrue($caught, 'The original mid-stream exception must propagate.');

        // Output was produced before the failure => partial usage is billed.
        self::assertCount(1, $this->usage->calls);

        self::assertCount(1, $this->telemetry->records);
        $record = $this->telemetry->records[0];
        self::assertFalse($record->success);
        self::assertSame(RuntimeException::class, $record->errorClass);
    }

    #[Test]
    public function swapsToAFallbackConfigurationWhenThePrimaryFailsBeforeTheFirstChunk(): void
    {
        $fallback = $this->configuration('fallback', providerType: 'claude', modelId: 'claude', uid: 5);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($fallback);

        $dispatcher = $this->dispatcher(repository: $repository);

        $primary = $this->configuration(
            'primary',
            providerType: 'openai',
            modelId: 'gpt-4o',
            fallbackChain: new FallbackChain(['primary', 'fallback']),
        );

        // A generator that fails on priming (rewind) with a retryable error;
        // the fallback yields real chunks.
        $open = function (LlmConfiguration $config): Generator {
            if ($config->getIdentifier() === 'primary') {
                throw new ProviderConnectionException('primary down', 1495872186);
            }

            yield 'served';
            yield '-by-fallback';
        };

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
            $primary,
            $open,
        ));

        self::assertSame(['served', '-by-fallback'], $chunks);

        // Telemetry names the REQUESTED primary; the swap shows as a fallback attempt.
        self::assertCount(1, $this->telemetry->records);
        $record = $this->telemetry->records[0];
        self::assertSame('primary', $record->configurationIdentifier);
        self::assertSame('openai', $record->provider);
        self::assertSame(1, $record->fallbackAttempts);
        self::assertTrue($record->success);

        // Usage attributes to the configuration that actually SERVED.
        self::assertSame('claude', $this->usage->calls[0]['provider']);
        self::assertSame(5, $this->usage->calls[0]['configurationUid']);
    }

    #[Test]
    public function countsEveryDispatchedSiblingIncludingRetryablyFailedOnesBeforeSuccess(): void
    {
        $fb1 = $this->configuration('fb1', providerType: 'groq');
        $fb2 = $this->configuration('fb2', providerType: 'claude', modelId: 'claude', uid: 7);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturnMap([['fb1', $fb1], ['fb2', $fb2]]);

        $dispatcher = $this->dispatcher(repository: $repository);

        $primary = $this->configuration(
            'primary',
            providerType: 'openai',
            fallbackChain: new FallbackChain(['fb1', 'fb2']),
        );

        // Primary and fb1 fail priming retryably; fb2 serves. Both dispatched
        // siblings must be counted, not only the winner.
        $open = function (LlmConfiguration $config): Generator {
            if ($config->getIdentifier() === 'fb2') {
                yield 'served';

                return;
            }

            throw new ProviderConnectionException($config->getIdentifier() . ' down', 1495872190);
        };

        $chunks = iterator_to_array($dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
            $primary,
            $open,
        ));

        self::assertSame(['served'], $chunks);
        self::assertSame(2, $this->telemetry->records[0]->fallbackAttempts);
        self::assertTrue($this->telemetry->records[0]->success);
    }

    #[Test]
    public function countsEveryDispatchedSiblingOnTotalExhaustion(): void
    {
        $fb1 = $this->configuration('fb1', providerType: 'groq');
        $fb2 = $this->configuration('fb2', providerType: 'claude');

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturnMap([['fb1', $fb1], ['fb2', $fb2]]);

        $dispatcher = $this->dispatcher(repository: $repository);

        $primary = $this->configuration(
            'primary',
            providerType: 'openai',
            fallbackChain: new FallbackChain(['fb1', 'fb2']),
        );

        // Every candidate fails priming retryably: the chain is exhausted.
        $open = static function (LlmConfiguration $config): Generator {
            yield from [];

            throw new ProviderConnectionException($config->getIdentifier() . ' down', 1495872191);
        };

        $caught = false;
        try {
            iterator_to_array($dispatcher->stream(
                $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
                $primary,
                $open,
            ));
        } catch (ProviderConnectionException) {
            $caught = true;
        }

        self::assertTrue($caught, 'An exhausted streaming fallback chain must surface the last error.');
        self::assertSame([], $this->usage->calls, 'A stream that produced nothing bills no usage.');
        self::assertSame(2, $this->telemetry->records[0]->fallbackAttempts);
        self::assertFalse($this->telemetry->records[0]->success);
    }

    #[Test]
    public function doesNotFallBackAfterTheFirstChunkHasBeenYielded(): void
    {
        $fallback = $this->configuration('fallback', providerType: 'claude');

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($fallback);

        $dispatcher = $this->dispatcher(repository: $repository);

        $primary = $this->configuration(
            'primary',
            providerType: 'openai',
            fallbackChain: new FallbackChain(['primary', 'fallback']),
        );

        // Fails AFTER the first chunk: a provider swap is no longer possible.
        $open = function (LlmConfiguration $config): Generator {
            yield 'first';

            throw new ProviderConnectionException('dies mid-stream', 1495872187);
        };

        $seen   = [];
        $caught = false;
        try {
            foreach ($dispatcher->stream($this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 0]), $primary, $open) as $chunk) {
                $seen[] = $chunk;
            }
        } catch (ProviderConnectionException) {
            $caught = true;
        }

        self::assertTrue($caught, 'A post-first-chunk failure must surface, not fall back.');
        self::assertSame(['first'], $seen);
        self::assertSame(0, $this->telemetry->records[0]->fallbackAttempts);
        self::assertFalse($this->telemetry->records[0]->success);
    }

    #[Test]
    public function propagatesANonRetryableFirstChunkFailureWithoutFallbackOrUsage(): void
    {
        $dispatcher = $this->dispatcher();

        // 4xx rate-unrelated response => non-retryable. Thrown on priming
        // (rewind) — `yield from []` makes this a generator so the throw fires
        // when the stream is primed, exercising the isRetryable=false branch.
        $open = static function (): Generator {
            yield from [];

            throw new ProviderResponseException('bad request', 400);
        };

        $caught = false;
        try {
            iterator_to_array($dispatcher->stream(
                $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
                $this->configuration('primary', providerType: 'openai'),
                $open,
            ));
        } catch (ProviderResponseException) {
            $caught = true;
        }

        self::assertTrue($caught);
        self::assertSame([], $this->usage->calls, 'A stream that produced nothing bills no usage.');
        self::assertCount(1, $this->telemetry->records);
        self::assertFalse($this->telemetry->records[0]->success);
        self::assertSame(ProviderResponseException::class, $this->telemetry->records[0]->errorClass);
    }

    #[Test]
    public function stillRecordsUsageWhenTelemetryIsDisabled(): void
    {
        $dispatcher = $this->dispatcher(extensionConfiguration: $this->extensionConfiguration(false));

        iterator_to_array($dispatcher->stream(
            $this->context([StreamingDispatcher::METADATA_PROMPT_CHARS => 4]),
            $this->configuration('primary', providerType: 'openai'),
            $this->staticStream(['ok']),
        ));

        self::assertCount(1, $this->usage->calls, 'Usage/budget accounting is independent of the telemetry toggle.');
        self::assertSame([], $this->telemetry->records, 'Telemetry must honour the disabled setting.');
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    private function dispatcher(
        ?BudgetServiceInterface $budget = null,
        ?LlmConfigurationRepository $repository = null,
        ?ExtensionConfiguration $extensionConfiguration = null,
    ): StreamingDispatcher {
        return new StreamingDispatcher(
            $budget ?? $this->budget(BudgetCheckResult::allowed()),
            $this->usage,
            $this->telemetry,
            $repository ?? self::createStub(LlmConfigurationRepository::class),
            new NullLogger(),
            $this->contextWithAmbientUser(0),
            $extensionConfiguration ?? $this->extensionConfiguration(true),
        );
    }

    private function budget(BudgetCheckResult $result): BudgetServiceInterface
    {
        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn($result);

        return $budget;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function context(array $metadata = []): ProviderCallContext
    {
        return new ProviderCallContext(ProviderOperation::Stream, 'corr-1', $metadata);
    }

    /**
     * @param list<string> $chunks
     *
     * @return callable(LlmConfiguration): Generator<int, string, mixed, void>
     */
    private function staticStream(array $chunks): callable
    {
        return static function () use ($chunks): Generator {
            yield from $chunks;
        };
    }

    private function configuration(
        string $identifier,
        string $providerType = '',
        string $modelId = '',
        ?Model $model = null,
        ?int $uid = null,
        ?FallbackChain $fallbackChain = null,
    ): LlmConfiguration&MockObject {
        $configuration = $this->createMock(LlmConfiguration::class);
        $configuration->method('getIdentifier')->willReturn($identifier);
        $configuration->method('getProviderType')->willReturn($providerType);
        $configuration->method('getModelId')->willReturn($modelId);
        $configuration->method('getLlmModel')->willReturn($model);
        $configuration->method('getUid')->willReturn($uid);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('getFallbackChainDTO')->willReturn($fallbackChain ?? new FallbackChain());

        return $configuration;
    }

    private function model(bool $pricing, float $cost = 0.0): Model&MockObject
    {
        $model = $this->createMock(Model::class);
        $model->method('getUid')->willReturn(3);
        $model->method('hasPricing')->willReturn($pricing);
        $model->method('estimateCost')->willReturn($cost);

        return $model;
    }

    private function extensionConfiguration(bool $enabled): ExtensionConfiguration&MockObject
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')
            ->willReturn(['telemetry' => ['enabled' => $enabled ? '1' : '0']]);

        return $extensionConfiguration;
    }

    private function contextWithAmbientUser(int $id): Context
    {
        $aspect = self::createStub(AspectInterface::class);
        $aspect->method('get')->willReturn($id);

        $context = self::createStub(Context::class);
        $context->method('getAspect')->willReturn($aspect);

        return $context;
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Closure;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolStreamingCapableInterface;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\SecretRedactionGuardrail;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use Netresearch\NrLlm\Tests\Unit\Service\Streaming\Fixture\HoldingGuardrail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;

/**
 * Whether a tool round streams, decided at the provider call (ADR-240).
 */
#[CoversClass(LlmServiceManager::class)]
final class ToolRoundStreamingTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;

    /** @var list<array{string, bool}> */
    private array $received = [];

    private ?ProviderCallContext $seen = null;

    private int $streamed = 0;

    private int $blocking = 0;

    #[Test]
    public function aSinkOnAStreamingAdapterReceivesTheTextAndTheCallReturnsTheResponse(): void
    {
        $response = $this->send($this->streamingAdapter(), $this->sink());

        self::assertSame('Hallo', $response->content);
        self::assertSame(1, $this->streamed);
        self::assertSame(0, $this->blocking);
        self::assertSame([['Hal', false], ['lo', false]], $this->received);
    }

    #[Test]
    public function withoutASinkTheCallStaysBlocking(): void
    {
        $this->send($this->streamingAdapter(), null);

        self::assertSame(0, $this->streamed);
        self::assertSame(1, $this->blocking);
    }

    #[Test]
    public function anAdapterThatCannotStreamThisCallStaysBlocking(): void
    {
        $this->send($this->streamingAdapter(streams: false), $this->sink());

        self::assertSame(0, $this->streamed);
        self::assertSame(1, $this->blocking);
    }

    #[Test]
    public function anAdapterWithoutTheCapabilityStaysBlocking(): void
    {
        $adapter = self::createStubForIntersectionOfInterfaces([ProviderInterface::class, ToolCapableInterface::class]);
        $adapter->method('chatCompletionWithTools')->willReturn($this->response());

        self::assertSame('Hallo', $this->send($adapter, $this->sink())->content);
        self::assertSame([], $this->received);
    }

    #[Test]
    public function aGuardrailThatHoldsTheTextKeepsTheRoundBlocking(): void
    {
        $this->send($this->streamingAdapter(), $this->sink(), guardrail: new HoldingGuardrail(GuardrailResult::allow()));

        self::assertSame(0, $this->streamed);
        self::assertSame(1, $this->blocking);
        self::assertSame([], $this->received);
    }

    #[Test]
    public function whatTheRedactionHeldBackGoesOutWithTheEndOfTheRound(): void
    {
        $this->send($this->streamingAdapter(), $this->sink(), guardrail: new SecretRedactionGuardrail());

        self::assertSame('Hallo', implode('', array_column($this->received, 0)));
    }

    #[Test]
    public function aSecondAttemptInThePipelineDropsTheFirstAttemptsText(): void
    {
        $this->send($this->streamingAdapter(), $this->sink(), middleware: new class implements ProviderMiddlewareInterface {
            public function handle(ProviderCallContext $context, callable $next): mixed
            {
                $next($context);

                return $next($context);
            }
        });

        self::assertSame([['Hal', false], ['lo', false], ['', true], ['Hal', false], ['lo', false]], $this->received);
    }

    #[Test]
    public function aBlockingSecondAttemptDropsTheFirstAttemptsTextToo(): void
    {
        $adapter = self::createStubForIntersectionOfInterfaces([ProviderInterface::class, ToolStreamingCapableInterface::class]);
        $calls   = 0;
        $adapter->method('streamsToolCalls')->willReturnCallback(static function () use (&$calls): bool {
            return ++$calls === 1;
        });
        $adapter->method('chatCompletionWithToolsStreamingText')->willReturnCallback(
            function (array $messages, array $tools, array $options, Closure $onText): CompletionResponse {
                $onText('Hal');

                return $this->response();
            },
        );
        $adapter->method('chatCompletionWithTools')->willReturn($this->response());

        $this->send($adapter, $this->sink(), middleware: new class implements ProviderMiddlewareInterface {
            public function handle(ProviderCallContext $context, callable $next): mixed
            {
                $next($context);

                return $next($context);
            }
        });

        self::assertSame([['Hal', false], ['', true]], $this->received);
    }

    #[Test]
    public function theRequestedConfigurationDecidesAboutTheHoldNotTheServingOne(): void
    {
        $guardrail = new HoldingGuardrail(GuardrailResult::allow(), holds: false);
        $adapter   = $this->streamingAdapter();
        $swapped   = self::createStub(LlmConfiguration::class);
        $swapped->method('getLlmModel')->willReturn(self::createStub(Model::class));
        $swapped->method('getIdentifier')->willReturn('fallback');
        $swapped->method('toOptionsArray')->willReturn([]);

        $this->send($adapter, $this->sink(), guardrail: $guardrail, middleware: new class ($swapped) implements ProviderMiddlewareInterface {
            public function __construct(private LlmConfiguration $swapped) {}

            public function handle(ProviderCallContext $context, callable $next): mixed
            {
                return $next($context->withConfiguration($this->swapped));
            }
        });

        self::assertSame('stream', $guardrail->askedAbout?->getIdentifier());
    }

    #[Test]
    public function theTimeToTheFirstTextReachesTheTelemetrySignals(): void
    {
        $test = $this;
        $this->send($this->streamingAdapter(), $this->sink(), middleware: new class ($test) implements ProviderMiddlewareInterface {
            public function __construct(private ToolRoundStreamingTest $test) {}

            public function handle(ProviderCallContext $context, callable $next): mixed
            {
                $this->test->remember($context);

                return $next($context);
            }
        });

        self::assertNotNull($this->seen?->telemetrySignals->timeToFirstTokenMs);
    }

    public function remember(ProviderCallContext $context): void
    {
        $this->seen = $context;
    }

    /**
     * @return Closure(string, bool): void
     */
    private function sink(): Closure
    {
        return function (string $text, bool $restart): void {
            $this->received[] = [$text, $restart];
        };
    }

    private function streamingAdapter(bool $streams = true): ProviderInterface
    {
        $adapter = self::createStubForIntersectionOfInterfaces([ProviderInterface::class, ToolStreamingCapableInterface::class]);
        $adapter->method('streamsToolCalls')->willReturn($streams);
        $adapter->method('chatCompletionWithTools')->willReturnCallback(function (): CompletionResponse {
            ++$this->blocking;

            return $this->response();
        });
        $adapter->method('chatCompletionWithToolsStreamingText')->willReturnCallback(
            function (array $messages, array $tools, array $options, Closure $onText): CompletionResponse {
                ++$this->streamed;
                $onText('Hal');
                $onText('lo');

                return $this->response();
            },
        );

        return $adapter;
    }

    private function response(): CompletionResponse
    {
        return new CompletionResponse('Hallo', 'm', UsageStatistics::fromTokens(1, 1));
    }

    /**
     * @param (Closure(string, bool): void)|null $sink
     */
    private function send(
        ProviderInterface $adapter,
        ?Closure $sink,
        ?GuardrailInterface $guardrail = null,
        ?ProviderMiddlewareInterface $middleware = null,
    ): CompletionResponse {
        $model = self::createStub(Model::class);
        $model->method('getMaxOutputTokens')->willReturn(100);
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getLlmModel')->willReturn($model);
        $configuration->method('getIdentifier')->willReturn('stream');
        $configuration->method('toOptionsArray')->willReturn([]);

        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);

        $settings = self::createStub(ExtensionConfiguration::class);
        $settings->method('get')->willReturn(['providers' => []]);

        $manager = $this->createLlmServiceManager(
            $settings,
            new NullLogger(),
            $registry,
            new MiddlewarePipeline($middleware === null ? [] : [$middleware]),
            self::createStub(CacheManagerInterface::class),
            streaming: new StreamingDispatcher(
                self::createStub(BudgetServiceInterface::class),
                new RecordingUsageTracker(),
                new InMemoryTelemetryRepository(),
                new FallbackCandidateResolver(self::createStub(LlmConfigurationRepository::class)),
                new RecordingLogger(),
                new Context(),
                self::createStub(ExtensionConfiguration::class),
                guardrails: $guardrail === null ? [] : [$guardrail],
            ),
        );

        $options = new ToolOptions();

        return $manager->chatWithToolsForConfiguration(
            [['role' => 'user', 'content' => 'Hallo']],
            [new ToolSpec(name: 'echo', description: 'echoes input', parameters: [])],
            $configuration,
            $sink === null ? $options : $options->withTextSink($sink),
        );
    }
}

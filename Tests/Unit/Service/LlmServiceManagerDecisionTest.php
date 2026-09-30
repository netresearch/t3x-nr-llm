<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\DecisionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionAnswer;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionQuestion;
use Netresearch\NrLlm\Domain\ValueObject\Decision\DecisionSubject;
use Netresearch\NrLlm\Domain\ValueObject\Decision\ProbabilityKind;
use Netresearch\NrLlm\Domain\ValueObject\Decision\YesNoQuestion;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Contract\DecisionCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\UnsupportedFeatureException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Provider\Middleware\TelemetryMiddleware;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\InputGuardrailScreener;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\DecisionOptions;
use Netresearch\NrLlm\Tests\Fixture\GuardrailIdentityDoubleTrait;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

/**
 * {@see LlmServiceManager::decideForConfiguration()} — the native decision
 * call through the pipeline (ADR-211).
 */
#[CoversClass(LlmServiceManager::class)]
#[CoversClass(DecisionOptions::class)]
final class LlmServiceManagerDecisionTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;

    /** What the adapter received, per call. */
    private ?DecisionSubject $received = null;

    /** @var array<mixed> */
    private array $receivedOptions = [];

    /** The model the adapter was created for. */
    private ?Model $servingModel = null;

    /** @var list<array{operation: ProviderOperation, identifier: string, metadata: array<string, mixed>}> */
    private array $pipelineCalls = [];

    private static function model(float $costInput = 4.2, float $costOutput = 0.0): Model
    {
        $model = new Model();
        $model->setModelId('jev-1.13.0');
        $model->setCapabilities('decision');
        $model->setCostInput($costInput);
        $model->setCostOutput($costOutput);

        return $model;
    }

    private function configuration(Model $model): LlmConfiguration
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getLlmModel')->willReturn($model);
        $configuration->method('getIdentifier')->willReturn('judge');
        $configuration->method('toOptionsArray')->willReturn(['model' => 'jev-1.13.0', 'provider' => 'typesafe', 'timeout' => 45]);
        $configuration->method('getFallbackChainDTO')->willReturn(FallbackChain::fromArray([]));

        return $configuration;
    }

    /**
     * @return list<DecisionQuestion>
     */
    private function questions(): array
    {
        return [new YesNoQuestion('ok', 'Is it ok?')];
    }

    private function decisionAdapter(UsageStatistics $usage): ProviderInterface&DecisionCapableInterface
    {
        $adapter = self::createStubForIntersectionOfInterfaces([ProviderInterface::class, DecisionCapableInterface::class]);
        $adapter->method('getIdentifier')->willReturn('typesafe');
        $adapter->method('decide')->willReturnCallback(
            function (DecisionSubject $subject, array $questions, array $options) use ($usage): DecisionResponse {
                self::assertCount(1, $questions);
                $this->received = $subject;
                $this->receivedOptions = $options;

                return new DecisionResponse(
                    ['ok' => DecisionAnswer::yesNo('ok', 0.81)],
                    'jev-1.13.0',
                    $usage,
                    ProbabilityKind::Calibrated,
                    'typesafe',
                );
            },
        );

        return $adapter;
    }

    private function manager(ProviderInterface $adapter, ?InputGuardrailScreener $screener = null, ?LlmConfiguration $fallback = null): LlmServiceManager
    {
        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturnCallback(
            function (Model $model) use ($adapter): ProviderInterface {
                $this->servingModel = $model;

                return $adapter;
            },
        );

        $recorder = new class ($this) implements ProviderMiddlewareInterface {
            public function __construct(private readonly LlmServiceManagerDecisionTest $test) {}

            public function handle(ProviderCallContext $context, callable $next): mixed
            {
                $configuration = $context->configuration;
                assert($configuration instanceof LlmConfiguration);
                $this->test->recordPipelineCall($context->operation, $configuration->getIdentifier(), $context->metadata);

                return $next($context);
            }
        };

        return $this->createLlmServiceManager(
            $this->createExtensionConfigurationMock(['providers' => []]),
            self::createStub(LoggerInterface::class),
            $registry,
            new MiddlewarePipeline($fallback instanceof LlmConfiguration ? [$recorder, $this->fallbackTo($fallback)] : [$recorder]),
            self::createStub(CacheManagerInterface::class),
            inputScreener: $screener,
        );
    }

    /**
     * Stands in for FallbackMiddleware: the attempt runs on another configuration.
     */
    private function fallbackTo(LlmConfiguration $fallback): ProviderMiddlewareInterface
    {
        return new class ($fallback) implements ProviderMiddlewareInterface {
            public function __construct(private readonly LlmConfiguration $fallback) {}

            public function handle(ProviderCallContext $context, callable $next): mixed
            {
                return $next($context->withConfiguration($this->fallback));
            }
        };
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function recordPipelineCall(ProviderOperation $operation, string $identifier, array $metadata): void
    {
        $this->pipelineCalls[] = ['operation' => $operation, 'identifier' => $identifier, 'metadata' => $metadata];
    }

    private function screener(GuardrailResult $result): InputGuardrailScreener
    {
        return new InputGuardrailScreener([
            new class ($result) implements InputGuardrailInterface {
                use GuardrailIdentityDoubleTrait;

                public function __construct(private readonly GuardrailResult $result) {}

                public function checkInput(string $text): GuardrailResult
                {
                    return str_contains($text, 'SECRET') ? $this->result : GuardrailResult::allow();
                }
            },
        ]);
    }

    #[Test]
    public function aDecisionRunsThroughThePipelineOfTheConfigurationWithItsAttribution(): void
    {
        $model = self::model();
        $options = (new DecisionOptions(beUserUid: 7, plannedCost: 0.25))->withCallerSource('my_ext', 'judge');

        $response = $this->manager($this->decisionAdapter(new UsageStatistics(300, 0, 300)))
            ->decideForConfiguration(new DecisionSubject(candidate: 'A'), $this->questions(), $this->configuration($model), $options);

        self::assertSame(0.81, $response->answers['ok']->value);
        self::assertCount(1, $this->pipelineCalls);
        self::assertSame(ProviderOperation::Decision, $this->pipelineCalls[0]['operation']);
        self::assertSame('judge', $this->pipelineCalls[0]['identifier']);
        $metadata = $this->pipelineCalls[0]['metadata'];
        self::assertSame(7, $metadata[BudgetMiddleware::METADATA_BE_USER_UID] ?? null);
        self::assertSame(0.25, $metadata[BudgetMiddleware::METADATA_PLANNED_COST] ?? null);
        self::assertSame('my_ext', $metadata[TelemetryMiddleware::METADATA_SOURCE_EXTENSION] ?? null);
        self::assertSame('judge', $metadata[TelemetryMiddleware::METADATA_SOURCE_OPERATION] ?? null);
        // The configuration's call options, without the provider key.
        self::assertSame('jev-1.13.0', $this->receivedOptions['model'] ?? null);
        self::assertSame(45, $this->receivedOptions['timeout'] ?? null);
        self::assertArrayNotHasKey('provider', $this->receivedOptions);
    }

    #[Test]
    public function everySubjectFieldIsScreenedBeforeTheProviderSeesIt(): void
    {
        $this->manager($this->decisionAdapter(new UsageStatistics(1, 0, 1)), $this->screener(GuardrailResult::redact('[redacted]')))
            ->decideForConfiguration(
                new DecisionSubject(task: 'plain task', candidate: 'has SECRET', evidence: ['SECRET one', 'plain']),
                $this->questions(),
                $this->configuration(self::model()),
            );

        self::assertSame(
            ['task' => 'plain task', 'candidate' => '[redacted]', 'evidence' => ['[redacted]', 'plain']],
            $this->received?->toState(),
        );
    }

    #[Test]
    public function aDeniedSubjectNeverReachesThePipeline(): void
    {
        $manager = $this->manager($this->decisionAdapter(new UsageStatistics(1, 0, 1)), $this->screener(GuardrailResult::deny('secret found')));

        try {
            $manager->decideForConfiguration(new DecisionSubject(evidence: ['a SECRET']), $this->questions(), $this->configuration(self::model()));
            self::fail('expected a GuardrailViolationException');
        } catch (GuardrailViolationException) {
            self::assertSame([], $this->pipelineCalls);
            self::assertNull($this->received);
        }
    }

    #[Test]
    public function aModelWhoseProviderCannotDecideIsRefused(): void
    {
        $adapter = self::createStub(ProviderInterface::class);
        $adapter->method('getIdentifier')->willReturn('openai');

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionCode(1795211061);
        $this->expectExceptionMessage('"openai"');

        $this->manager($adapter)->decideForConfiguration(new DecisionSubject(candidate: 'A'), $this->questions(), $this->configuration(self::model()));
    }

    #[Test]
    public function aHandedOverRoutingDecisionServesThePrimaryConfiguration(): void
    {
        $checked = self::model();
        $checked->setModelId('the-checked-model');

        $this->manager($this->decisionAdapter(new UsageStatistics(1, 0, 1)))->decideForConfiguration(
            new DecisionSubject(candidate: 'A'),
            self::questions(),
            $this->configuration(self::model()),
            null,
            ModelResolution::withoutDecision($checked),
        );

        // Not the configuration's own model: the one the caller checked.
        self::assertSame($checked, $this->servingModel);
    }

    #[Test]
    public function aFallbackResolvesForItselfRatherThanReusingTheHandedOverDecision(): void
    {
        $checked = self::model();
        $checked->setModelId('the-checked-model');

        $fallbackModel = self::model();
        $fallbackModel->setModelId('the-fallback-model');

        $this->manager($this->decisionAdapter(new UsageStatistics(1, 0, 1)), fallback: $this->configuration($fallbackModel))->decideForConfiguration(
            new DecisionSubject(candidate: 'A'),
            self::questions(),
            $this->configuration(self::model()),
            null,
            ModelResolution::withoutDecision($checked),
        );

        self::assertSame($fallbackModel, $this->servingModel, 'the fallback attempt was served by the primary decision');
    }

    #[Test]
    public function theCostIsTheServingModelsNotTheConfigurationsOwn(): void
    {
        $own = self::model(4.2, 0.0);
        $served = self::model(80.0, 0.0);

        $response = $this->manager($this->decisionAdapter(new UsageStatistics(1000, 0, 1000)))->decideForConfiguration(
            new DecisionSubject(candidate: 'A'),
            self::questions(),
            $this->configuration($own),
            null,
            ModelResolution::withoutDecision($served),
        );

        self::assertEqualsWithDelta(1000 * 80.0 / 1_000_000 / 100, $response->usage->estimatedCost, 1e-15);
    }

    #[Test]
    public function withoutAHandedOverDecisionTheConfigurationsModelServes(): void
    {
        $own = self::model();

        $this->manager($this->decisionAdapter(new UsageStatistics(1, 0, 1)))
            ->decideForConfiguration(new DecisionSubject(candidate: 'A'), self::questions(), $this->configuration($own));

        self::assertSame($own, $this->servingModel);
    }

    /**
     * @return iterable<string, array{Model, UsageStatistics, float|null}>
     */
    public static function pricing(): iterable
    {
        // 300 input tokens at 4.2 cents per million, output free.
        yield 'priced by the serving model' => [self::model(4.2, 0.0), new UsageStatistics(300, 12, 312), (300 * 4.2 + 12 * 0.0) / 1_000_000 / 100];
        yield 'output priced too' => [self::model(4.2, 50.0), new UsageStatistics(300, 12, 312), (300 * 4.2 + 12 * 50.0) / 1_000_000 / 100];
        // A decision API reports no output tokens; the input alone is priced.
        yield 'input only, as a decision API reports it' => [self::model(4.2, 50.0), new UsageStatistics(300, 0, 300), 300 * 4.2 / 1_000_000 / 100];
        yield 'the provider priced it' => [self::model(), new UsageStatistics(300, 0, 300, 0.5), 0.5];
        yield 'an unpriced model leaves the cost unknown' => [self::model(0.0, 0.0), new UsageStatistics(300, 0, 300), null];
        yield 'unreported usage leaves the cost unknown' => [self::model(), new UsageStatistics(0, 0, 0), null];
    }

    #[Test]
    #[DataProvider('pricing')]
    public function theResponseCarriesTheCostOfTheModelThatServed(Model $model, UsageStatistics $usage, ?float $expected): void
    {
        $response = $this->manager($this->decisionAdapter($usage))
            ->decideForConfiguration(new DecisionSubject(candidate: 'A'), $this->questions(), $this->configuration($model));

        if ($expected === null) {
            self::assertNull($response->usage->estimatedCost);
        } else {
            self::assertEqualsWithDelta($expected, $response->usage->estimatedCost, 1e-15);
        }

        self::assertSame([$usage->promptTokens, $usage->completionTokens, $usage->totalTokens], [$response->usage->promptTokens, $response->usage->completionTokens, $response->usage->totalTokens]);
    }
}

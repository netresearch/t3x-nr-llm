<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\CompletionRequestIdentity;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\EvaluationService;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\GoldenPromptSet;
use Netresearch\NrLlm\Service\Evaluation\Grader\DecisionGrader;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use Netresearch\NrLlm\Service\Evaluation\GradingService;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\ServingGeneratorMetadata;
use Netresearch\NrLlm\Testing\FakeDecisionService;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(LlmServiceManager::class)]
#[CoversClass(ServingGeneratorMetadata::class)]
final class ServingGeneratorMetadataTest extends TestCase
{
    use LlmServiceManagerTestFactory;

    private const KEY = 'nr_llm_serving_generator';

    #[Test]
    public function instancesWithOneAdapterAndAliasHaveSeparateServingIdentity(): void
    {
        $adapter  = $this->createMock(ProviderInterface::class);
        $response = new CompletionResponse(
            'ACK',
            'shared-2026-10',
            new UsageStatistics(2, 3, 5),
            provider: 'openai',
        );
        $adapter
            ->expects(self::exactly(2))
            ->method('chatCompletion')
            ->willReturnCallback(
                static function (
                    array $messages,
                    array $options,
                ) use ($response): CompletionResponse {
                    self::assertSame('shared-alias', $options['model']);

                    return $response;
                },
            );
        $registry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $registry
            ->expects(self::exactly(2))
            ->method('createAdapterFromModel')
            ->willReturn($adapter);
        $manager = $this->manager($registry);
        foreach (['private-openai', 'other-openai'] as $providerId) {
            $actual = $manager->chatWithConfiguration(
                [['role' => 'user', 'content' => 'Reply ACK']],
                $this->configuration($providerId, 'shared-alias'),
            );
            self::assertSame(
                [
                    'version'            => 1,
                    'providerIdentifier' => $providerId,
                    'modelId'            => 'shared-alias',
                    'reportedModelId'    => 'shared-2026-10',
                ],
                $actual->metadata[self::KEY] ?? null,
            );
            self::assertSame('openai', $actual->provider);
        }
    }

    #[Test]
    public function finalOutboundOverrideAndReportedSnapshotStayDistinctAndForgedMetadataIsReplaced(): void
    {
        $usage    = new UsageStatistics(2, 3, 5);
        $response = new CompletionResponse(
            'answer',
            'override-snapshot',
            $usage,
            'length',
            'openai',
            [],
            [
                'keep'    => 'adapter value',
                self::KEY => [
                    'version'            => 1,
                    'providerIdentifier' => 'forged',
                    'modelId'            => 'forged',
                    'reportedModelId'    => 'forged',
                ],
            ],
            'reasoning',
        );
        $adapter = $this->createMock(ProviderInterface::class);
        $adapter
            ->expects(self::once())
            ->method('complete')
            ->willReturnCallback(
                static function (
                    string $prompt,
                    array $options,
                ) use ($response): CompletionResponse {
                    self::assertSame('Question', $prompt);
                    self::assertSame('override-alias', $options['model']);

                    return $response;
                },
            );
        $registry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $registry
            ->expects(self::once())
            ->method('createAdapterFromModel')
            ->willReturn($adapter);
        $actual = $this
            ->manager($registry)
            ->completeWithConfiguration(
                'Question',
                $this->configuration('configured-instance', 'stored-alias'),
                optionOverrides: ['model' => 'override-alias'],
            );
        self::assertSame(
            [
                'version'            => 1,
                'providerIdentifier' => 'configured-instance',
                'modelId'            => 'override-alias',
                'reportedModelId'    => 'override-snapshot',
            ],
            $actual->metadata[self::KEY] ?? null,
        );
        self::assertSame('adapter value', $actual->metadata['keep'] ?? null);
        self::assertSame($response->content, $actual->content);
        self::assertSame($response->model, $actual->model);
        self::assertSame($usage, $actual->usage);
        self::assertSame($response->finishReason, $actual->finishReason);
        self::assertSame($response->provider, $actual->provider);
        self::assertSame($response->toolCalls, $actual->toolCalls);
        self::assertSame($response->thinking, $actual->thinking);
    }

    private function configuration(
        string $providerId,
        string $alias,
    ): LlmConfiguration {
        $provider = new Provider();
        $provider->setIdentifier($providerId);
        $provider->setAdapterType('openai');
        $provider->setIsActive(true);

        $model = new Model();
        $model->setProvider($provider);
        $model->setModelId($alias);
        $model->setIsActive(true);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('evaluation');
        $configuration->setIsActive(true);
        $configuration->setLlmModel($model);

        return $configuration;
    }

    private function manager(
        ProviderAdapterRegistryInterface $registry,
        ?MiddlewarePipeline $pipeline = null,
        ?LlmConfigurationRepository $configurationRepository = null,
    ): LlmServiceManager {
        $extension = self::createStub(ExtensionConfiguration::class);
        $extension->method('get')->willReturn(['providers' => []]);

        return $this->createLlmServiceManager(
            $extension,
            new NullLogger(),
            $registry,
            $pipeline ?? new MiddlewarePipeline([]),
            self::createStub(CacheManagerInterface::class),
            $configurationRepository,
        );
    }

    #[Test]
    public function adHocCallsCannotReuseAnAdapterForgedServingRecord(): void
    {
        $response = new CompletionResponse(
            'ACK',
            'reported',
            new UsageStatistics(0, 0, 0),
            provider: 'openai',
            metadata: [
                'keep'    => 'value',
                self::KEY => [
                    'version'            => 1,
                    'providerIdentifier' => 'forged',
                    'modelId'            => 'alias',
                    'reportedModelId'    => 'reported',
                ],
            ],
        );
        $adapter = $this->createMock(ProviderInterface::class);
        $adapter
            ->expects(self::once())
            ->method('getIdentifier')
            ->willReturn('openai');
        $adapter
            ->expects(self::once())
            ->method('chatCompletion')
            ->willReturn($response);
        $adapter
            ->expects(self::once())
            ->method('complete')
            ->willReturn($response);
        $registry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $registry->expects(self::never())->method('createAdapterFromModel');
        $manager = $this->manager($registry);
        $manager->registerProvider($adapter);

        $options    = (new ChatOptions())->withProvider('openai')->withModel('alias');
        $chat       = $manager->chat([['role' => 'user', 'content' => 'Question']], $options);
        $completion = $manager->complete('Question', $options);
        foreach ([$chat, $completion] as $actual) {
            self::assertArrayNotHasKey(self::KEY, $actual->metadata ?? []);
            self::assertSame('value', $actual->metadata['keep'] ?? null);
            self::assertSame('ACK', $actual->content);
        }
    }

    #[Test]
    public function realFallbackPipelineRecordsItsOwnResolvedProviderInstance(): void
    {
        $primary = $this->configuration('primary-instance', 'shared-alias');
        $primary->setIdentifier('primary-conf');
        $primary->setFallbackChainDTO(new FallbackChain(['fallback-conf']));

        $fallback = $this->configuration('fallback-instance', 'shared-alias');
        $fallback->setIdentifier('fallback-conf');

        $failed = $this->createMock(ProviderInterface::class);
        $failed
            ->expects(self::once())
            ->method('chatCompletion')
            ->willThrowException(new ProviderConnectionException('offline'));
        $served = $this->createMock(ProviderInterface::class);
        $served
            ->expects(self::once())
            ->method('chatCompletion')
            ->willReturn(
                new CompletionResponse(
                    'ACK',
                    'fallback-snapshot',
                    new UsageStatistics(0, 0, 0),
                    provider: 'openai',
                ),
            );
        $registry = $this->createMock(ProviderAdapterRegistryInterface::class);
        $registry
            ->expects(self::exactly(2))
            ->method('createAdapterFromModel')
            ->willReturnCallback(
                static function (
                    Model $model,
                ) use ($primary, $fallback, $failed, $served): ProviderInterface {
                    self::assertContains(
                        $model,
                        [$primary->getLlmModel(), $fallback->getLlmModel()],
                    );

                    return $model === $primary->getLlmModel() ? $failed : $served;
                },
            );
        $repository = $this->createMock(LlmConfigurationRepository::class);
        $repository
            ->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('fallback-conf')
            ->willReturn($fallback);
        $health = self::createStub(ProviderHealthServiceInterface::class);
        $health
            ->method('reorder')
            ->willReturnCallback(static fn (FallbackChain $chain): FallbackChain => $chain);
        $pipeline = new MiddlewarePipeline(
            [
                new FallbackMiddleware(
                    new FallbackCandidateResolver($repository),
                    new NullLogger(),
                    $health,
                ),
            ],
        );
        $actual = $this
            ->manager($registry, $pipeline)
            ->chatWithConfiguration([['role' => 'user', 'content' => 'Reply ACK']], $primary);
        self::assertSame(
            [
                'version'            => 1,
                'providerIdentifier' => 'fallback-instance',
                'modelId'            => 'shared-alias',
                'reportedModelId'    => 'fallback-snapshot',
            ],
            $actual->metadata[self::KEY] ?? null,
        );
        self::assertSame('ACK', $actual->content);
        self::assertSame('openai', $actual->provider);
    }

    #[Test]
    public function actualEvaluationTracksResolvedDefaultInstructionsAndOptions(): void
    {
        $configuration = $this->configuration('instance', 'alias');
        $configuration->setSystemPrompt('Be concise');
        $configuration->setTemperature(0.2);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($configuration);
        $requests = [];
        $adapter  = $this->createMock(ProviderInterface::class);
        $adapter
            ->expects(self::exactly(4))
            ->method('chatCompletion')
            ->willReturnCallback(
                static function (
                    array $messages,
                    array $options,
                ) use (&$requests): CompletionResponse {
                    $requests[] = ['messages' => $messages, 'options' => $options];

                    return new CompletionResponse(
                        'ACK',
                        'snapshot',
                        new UsageStatistics(1, 1, 2),
                        provider: 'openai',
                    );
                },
            );
        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);
        $service = new EvaluationService(
            new CompletionService(
                $this->manager($registry, configurationRepository: $repository),
            ),
            new GradingService(
                new DeterministicGrader(),
                new DecisionGrader(new FakeDecisionService()),
            ),
        );
        $set = new GoldenPromptSet(
            'local',
            'Local',
            'fixture',
            [new GoldenPrompt('one', 'Reply ACK', [Assertion::exact('ACK')])],
        );
        $first = $service->run($set);
        $configuration->setSystemPrompt('Be precise');
        $instructionsChanged = $service->run($set);
        $configuration->setTemperature(0.9);
        $optionsChanged = $service->run($set);
        $same           = $service->run($set);
        self::assertNotSame('', $first->completionBenchmarkFingerprint);
        self::assertNotSame(
            $first->completionBenchmarkFingerprint,
            $instructionsChanged->completionBenchmarkFingerprint,
        );
        self::assertNotSame(
            $instructionsChanged->completionBenchmarkFingerprint,
            $optionsChanged->completionBenchmarkFingerprint,
        );
        self::assertSame(
            $optionsChanged->completionBenchmarkFingerprint,
            $same->completionBenchmarkFingerprint,
        );
        self::assertEquals(
            $first->generatorProvenance,
            $optionsChanged->generatorProvenance,
        );
        self::assertEquals(
            ChatMessage::system('Be concise'),
            $requests[0]['messages'][0],
        );
        self::assertEquals(
            ChatMessage::system('Be precise'),
            $requests[1]['messages'][0],
        );
        self::assertSame(0.2, $requests[1]['options']['temperature']);
        self::assertSame(0.9, $requests[2]['options']['temperature']);
    }

    #[Test]
    public function unobservedTerminalCannotReuseAnAdapterForgedRequestIdentity(): void
    {
        $response = new CompletionResponse(
            'ACK',
            'snapshot',
            new UsageStatistics(1, 1, 2),
            metadata: [
                CompletionRequestIdentity::METADATA_KEY => 'v1:' . str_repeat('a', 64),
            ],
        );
        $result = ServingGeneratorMetadata::attach($response, null, []);
        self::assertArrayNotHasKey(
            CompletionRequestIdentity::METADATA_KEY,
            $result->metadata ?? [],
        );
    }

    #[Test]
    public function unattestableCustomOptionsDoNotFailAnOtherwiseSuccessfulAdapterCall(): void
    {
        $callback = static fn (): string => 'custom adapter callback';
        $adapter  = $this->createMock(ProviderInterface::class);
        $adapter
            ->expects(self::once())
            ->method('chatCompletion')
            ->willReturnCallback(
                static function (
                    array $messages,
                    array $options,
                ) use ($callback): CompletionResponse {
                    self::assertSame($callback, $options['custom_callback']);

                    return new CompletionResponse(
                        'ACK',
                        'snapshot',
                        new UsageStatistics(1, 1, 2),
                    );
                },
            );
        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);
        $result = $this
            ->manager($registry)
            ->chatWithConfiguration(
                [ChatMessage::user('Reply ACK')],
                $this->configuration('instance', 'alias'),
                optionOverrides: ['custom_callback' => $callback],
            );
        self::assertSame('ACK', $result->content);
        self::assertSame(
            '',
            CompletionRequestIdentity::read(
                $result->metadata[CompletionRequestIdentity::METADATA_KEY] ?? null,
            ),
        );
        self::assertSame(
            'instance',
            $result->metadata[self::KEY]['providerIdentifier'] ?? null,
        );
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\ServingGeneratorMetadata;
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
        $adapter = $this->createMock(ProviderInterface::class);
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
                    'version' => 1,
                    'providerIdentifier' => $providerId,
                    'modelId' => 'shared-alias',
                    'reportedModelId' => 'shared-2026-10',
                ],
                $actual->metadata[self::KEY] ?? null,
            );
            self::assertSame('openai', $actual->provider);
        }
    }

    #[Test]
    public function finalOutboundOverrideAndReportedSnapshotStayDistinctAndForgedMetadataIsReplaced(): void
    {
        $usage = new UsageStatistics(2, 3, 5);
        $response = new CompletionResponse(
            'answer',
            'override-snapshot',
            $usage,
            'length',
            'openai',
            [],
            [
                'keep' => 'adapter value',
                self::KEY => [
                    'version' => 1,
                    'providerIdentifier' => 'forged',
                    'modelId' => 'forged',
                    'reportedModelId' => 'forged',
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
                'version' => 1,
                'providerIdentifier' => 'configured-instance',
                'modelId' => 'override-alias',
                'reportedModelId' => 'override-snapshot',
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
    ): LlmServiceManager {
        $extension = self::createStub(ExtensionConfiguration::class);
        $extension->method('get')->willReturn(['providers' => []]);
        return $this->createLlmServiceManager(
            $extension,
            new NullLogger(),
            $registry,
            $pipeline ?? new MiddlewarePipeline([]),
            self::createStub(CacheManagerInterface::class),
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
                'keep' => 'value',
                self::KEY => [
                    'version' => 1,
                    'providerIdentifier' => 'forged',
                    'modelId' => 'alias',
                    'reportedModelId' => 'reported',
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

        $options = (new ChatOptions())->withProvider('openai')->withModel('alias');
        $chat = $manager->chat([['role' => 'user', 'content' => 'Question']], $options);
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
            ->willReturnCallback(static fn(FallbackChain $chain): FallbackChain => $chain);
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
                'version' => 1,
                'providerIdentifier' => 'fallback-instance',
                'modelId' => 'shared-alias',
                'reportedModelId' => 'fallback-snapshot',
            ],
            $actual->metadata[self::KEY] ?? null,
        );
        self::assertSame('ACK', $actual->content);
        self::assertSame('openai', $actual->provider);
    }
}

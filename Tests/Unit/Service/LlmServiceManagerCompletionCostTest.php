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
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

/**
 * A chat, completion or tool round carries the cost of the model that served
 * it (NEXT-188). Before, only a decision did: every other response kept the
 * provider's own cost, which no adapter but OpenRouter reports, and an agent
 * run summing its rounds stored 0 although the model had a price.
 */
#[CoversClass(LlmServiceManager::class)]
final class LlmServiceManagerCompletionCostTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;

    /**
     * The model of run db90ea23 on the demo: 2 USD per million input tokens
     * and 10 USD per million output tokens, stored in cents.
     */
    private function model(float $costInput = 200.0, float $costOutput = 1000.0): Model
    {
        $model = new Model();
        $model->setModelId('gpt-6.1-sol');
        $model->setCapabilities('chat,tools,vision');
        $model->setCostInput($costInput);
        $model->setCostOutput($costOutput);

        return $model;
    }

    private function configuration(Model $model): LlmConfiguration
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getLlmModel')->willReturn($model);
        $configuration->method('getIdentifier')->willReturn('content-assistant');
        $configuration->method('toOptionsArray')->willReturn(['model' => 'gpt-6.1-sol']);
        $configuration->method('getFallbackChainDTO')->willReturn(FallbackChain::fromArray([]));

        return $configuration;
    }

    private function adapter(UsageStatistics $usage): ProviderInterface&ToolCapableInterface
    {
        $response = new CompletionResponse('ok', 'gpt-6.1-sol', $usage, provider: 'openai');
        $adapter  = self::createStubForIntersectionOfInterfaces([ProviderInterface::class, ToolCapableInterface::class]);
        $adapter->method('getIdentifier')->willReturn('openai');
        $adapter->method('chatCompletion')->willReturn($response);
        $adapter->method('complete')->willReturn($response);
        $adapter->method('chatCompletionWithTools')->willReturn($response);
        $adapter->method('supportsTools')->willReturn(true);

        return $adapter;
    }

    private function manager(ProviderInterface $adapter): LlmServiceManager
    {
        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);

        return $this->createLlmServiceManager(
            $this->createExtensionConfigurationMock(['providers' => []]),
            self::createStub(LoggerInterface::class),
            $registry,
            new MiddlewarePipeline([]),
            self::createStub(CacheManagerInterface::class),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operations(): iterable
    {
        yield 'tool round' => ['tools'];
        yield 'chat' => ['chat'];
        yield 'completion' => ['complete'];
    }

    private function call(string $operation, Model $model, UsageStatistics $usage): CompletionResponse
    {
        $manager       = $this->manager($this->adapter($usage));
        $configuration = $this->configuration($model);

        return match ($operation) {
            'tools' => $manager->chatWithToolsForConfiguration(
                [['role' => 'user', 'content' => 'Kategorien aus dem Bild setzen']],
                [new ToolSpec(name: 'set_file_categories', description: 'sets categories', parameters: [])],
                $configuration,
            ),
            'chat'     => $manager->chatWithConfiguration([['role' => 'user', 'content' => 'Hello']], $configuration),
            'complete' => $manager->completeWithConfiguration('Hello', $configuration),
            default    => self::fail('Unknown operation ' . $operation),
        };
    }

    /**
     * Round 1 of run db90ea23: 1,338 prompt and 24 completion tokens.
     */
    #[Test]
    #[DataProvider('operations')]
    public function theResponseCarriesTheCostOfThePricedModelThatServed(string $operation): void
    {
        $response = $this->call($operation, $this->model(), UsageStatistics::fromTokens(1338, 24));

        self::assertNotNull($response->usage->estimatedCost);
        self::assertEqualsWithDelta(1338 * 2 / 1_000_000 + 24 * 10 / 1_000_000, $response->usage->estimatedCost, 1e-12);
        self::assertSame(1362, $response->usage->totalTokens);
        self::assertSame('ok', $response->content);
    }

    #[Test]
    #[DataProvider('operations')]
    public function anUnpricedModelLeavesTheCostUnknownRatherThanZero(string $operation): void
    {
        $response = $this->call($operation, $this->model(0.0, 0.0), UsageStatistics::fromTokens(1338, 24));

        self::assertNull($response->usage->estimatedCost);
    }

    #[Test]
    #[DataProvider('operations')]
    public function aCostTheProviderReportedIsKept(string $operation): void
    {
        $response = $this->call($operation, $this->model(), new UsageStatistics(1338, 24, 1362, 0.5, true, true));

        self::assertSame(0.5, $response->usage->estimatedCost);
    }

    #[Test]
    #[DataProvider('operations')]
    public function anUnreportedTokenCountIsNotPricedAsZero(string $operation): void
    {
        $response = $this->call($operation, $this->model(), new UsageStatistics(1338, 0, 1338, null, true, false));

        self::assertNull($response->usage->estimatedCost);
    }
}

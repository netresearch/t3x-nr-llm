<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\RecordingToolAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Functional coverage for the config-aware tool entry point
 * {@see LlmServiceManager::chatWithToolsForConfiguration()} (design §4.3a).
 *
 * Unlike the keyed {@see LlmServiceManager::chatWithTools()} path — which
 * resolves a provider from ExtensionConfiguration against a model-less
 * transient configuration — this entry point MUST resolve its adapter from
 * the run's DB-backed {@see LlmConfiguration} via `getAdapterFromConfiguration()`
 * so the configuration's vault key, model and pricing reach the call.
 */
#[CoversClass(LlmServiceManager::class)]
final class LlmServiceManagerToolsConfigurationTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function chatWithToolsForConfigurationResolvesAdapterFromConfigurationAndReturnsToolCall(): void
    {
        // A DB-backed configuration: a provider carrying a vault key identifier
        // and a priced model the configuration points at.
        $provider = new Provider();
        $provider->setIdentifier('fake-provider');
        $provider->setAdapterType('openai');
        $provider->setApiKey('nr_tools_vault_key');

        $model = new Model();
        $model->setModelId('priced-model-x');
        $model->setProvider($provider);
        $model->setCostInputDollars(1.5);
        $model->setCostOutputDollars(3.0);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('cfg-tools');
        $configuration->setLlmModel($model);

        $adapter = new RecordingToolAdapter();

        // Capture the model the manager resolves the adapter from. A keyed/default
        // path would never call createAdapterFromModel(), so a captured model that
        // is the configuration's own model proves config-driven resolution.
        $resolvedFromModel = null;
        $adapterRegistry   = $this->createMock(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->expects(self::once())->method('createAdapterFromModel')->willReturnCallback(
            function (Model $passedModel) use ($adapter, &$resolvedFromModel): RecordingToolAdapter {
                $resolvedFromModel = $passedModel;

                return $adapter;
            },
        );

        $extensionConfig = self::createStub(ExtensionConfiguration::class);
        $extensionConfig->method('get')->willReturn([]);

        $manager = new LlmServiceManager(
            $extensionConfig,
            new NullLogger(),
            $adapterRegistry,
            new MiddlewarePipeline([]),
            self::createStub(CacheManagerInterface::class),
        );

        $messages = [['role' => 'user', 'content' => 'Show me the last logs.']];
        $tools    = [ToolSpec::function('fetch_logs', 'Read recent log rows', ['type' => 'object', 'properties' => []])];

        $response = $manager->chatWithToolsForConfiguration($messages, $tools, $configuration, ToolOptions::auto());

        // 1. The configuration's model — not a default — drove adapter resolution,
        //    and the provider's vault key is reachable from that model.
        self::assertInstanceOf(Model::class, $resolvedFromModel);
        self::assertSame($model, $resolvedFromModel);
        self::assertSame('priced-model-x', $resolvedFromModel->getModelId());
        self::assertSame('nr_tools_vault_key', $resolvedFromModel->getProvider()?->getApiKey());

        // 2. The tool call the adapter emitted is returned to the caller verbatim.
        self::assertInstanceOf(CompletionResponse::class, $response);
        self::assertTrue($response->hasToolCalls());
        $toolCalls = $response->toolCalls;
        self::assertIsArray($toolCalls);
        self::assertCount(1, $toolCalls);
        self::assertSame('fetch_logs', $toolCalls[0]->name);
        self::assertSame(['limit' => 5], $toolCalls[0]->arguments);

        // 3. The configuration's model id and the declared tool reached the call.
        $recordedOptions = $adapter->recordedOptions;
        self::assertIsArray($recordedOptions);
        self::assertSame('priced-model-x', $recordedOptions['model'] ?? null);
        $recordedTools = $adapter->recordedTools;
        self::assertIsArray($recordedTools);
        self::assertCount(1, $recordedTools);
        self::assertSame('fetch_logs', $recordedTools[0]->name);
    }
}

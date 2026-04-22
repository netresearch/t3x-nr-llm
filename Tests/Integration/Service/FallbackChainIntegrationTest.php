<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Integration\Service;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\Exception\FallbackChainExhaustedException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Service\FallbackChainExecutor;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Tests\Integration\AbstractIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Client\ClientInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Integration test for provider fallback chain.
 *
 * Verifies end-to-end behaviour: LlmServiceManager -> FallbackChainExecutor
 * -> ProviderAdapterRegistry -> real provider instances with mocked HTTP.
 */
#[CoversClass(LlmServiceManager::class)]
#[CoversClass(FallbackChainExecutor::class)]
class FallbackChainIntegrationTest extends AbstractIntegrationTestCase
{
    private ExtensionConfiguration&Stub $extensionConfigStub;
    private ProviderAdapterRegistry&Stub $adapterRegistryStub;
    private LlmConfigurationRepository&Stub $configRepositoryStub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extensionConfigStub = self::createStub(ExtensionConfiguration::class);
        $this->extensionConfigStub->method('get')
            ->willReturn(['providers' => []]);

        $this->adapterRegistryStub = self::createStub(ProviderAdapterRegistry::class);
        $this->configRepositoryStub = self::createStub(LlmConfigurationRepository::class);
    }

    #[Test]
    public function primaryProviderSuccessSkipsFallbackEntirely(): void
    {
        $primaryHttp = $this->createHttpClientWithResponses([
            $this->createSuccessResponse($this->getOpenAiChatResponse('from primary')),
        ]);
        $primaryConfig = $this->buildConfiguration('primary', 'openai', 'gpt-4o', new FallbackChain(['fallback']));
        $openAi = $this->buildOpenAiProvider($primaryHttp);

        $this->adapterRegistryStub->method('createAdapterFromModel')
            ->willReturn($openAi);

        $manager = $this->buildManager();

        $response = $manager->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Hello']],
            $primaryConfig,
        );

        self::assertSame('from primary', $response->content);
    }

    #[Test]
    public function connectionErrorOnPrimaryRoutesToFallback(): void
    {
        // Primary OpenAI returns 503 repeatedly -> ProviderConnectionException
        $primaryHttp = $this->createHttpClientWithResponses(array_fill(
            0,
            5,
            new Response(503, ['Content-Type' => 'application/json'], '{"error":"service unavailable"}'),
        ));
        $fallbackHttp = $this->createHttpClientWithResponses([
            $this->createSuccessResponse($this->getClaudeChatResponse('from fallback')),
        ]);

        $primaryConfig = $this->buildConfiguration('primary', 'openai', 'gpt-4o', new FallbackChain(['fallback']));
        $fallbackConfig = $this->buildConfiguration('fallback', 'anthropic', 'claude-sonnet-4-20250514');

        $openAi = $this->buildOpenAiProvider($primaryHttp);
        $claude = $this->buildClaudeProvider($fallbackHttp);

        $this->configRepositoryStub->method('findOneByIdentifier')
            ->willReturnMap([
                ['fallback', $fallbackConfig],
            ]);

        $this->adapterRegistryStub->method('createAdapterFromModel')
            ->willReturnCallback(fn(Model $model) => $model->getModelId() === 'gpt-4o' ? $openAi : $claude);

        $manager = $this->buildManager();

        $response = $manager->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Hello']],
            $primaryConfig,
        );

        self::assertSame('from fallback', $response->content);
    }

    #[Test]
    public function rateLimitOn429RoutesToFallback(): void
    {
        // OpenAI returns 429 -> ProviderResponseException(code=429) -> retryable
        $primaryHttp = $this->createHttpClientWithResponses([
            new Response(
                429,
                ['Content-Type' => 'application/json'],
                '{"error":{"message":"rate limited","type":"rate_limit"}}',
            ),
        ]);
        $fallbackHttp = $this->createHttpClientWithResponses([
            $this->createSuccessResponse($this->getClaudeChatResponse('from fallback')),
        ]);

        $primaryConfig = $this->buildConfiguration('primary', 'openai', 'gpt-4o', new FallbackChain(['fallback']));
        $fallbackConfig = $this->buildConfiguration('fallback', 'anthropic', 'claude-sonnet-4-20250514');

        $openAi = $this->buildOpenAiProvider($primaryHttp);
        $claude = $this->buildClaudeProvider($fallbackHttp);

        $this->configRepositoryStub->method('findOneByIdentifier')
            ->willReturnMap([['fallback', $fallbackConfig]]);

        $this->adapterRegistryStub->method('createAdapterFromModel')
            ->willReturnCallback(fn(Model $model) => $model->getModelId() === 'gpt-4o' ? $openAi : $claude);

        $manager = $this->buildManager();

        $response = $manager->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Hello']],
            $primaryConfig,
        );

        self::assertSame('from fallback', $response->content);
    }

    #[Test]
    public function clientError4xxDoesNotFallBack(): void
    {
        // OpenAI returns 401 -> ProviderResponseException(code=401) -> NOT retryable
        $primaryHttp = $this->createHttpClientWithResponses([
            new Response(
                401,
                ['Content-Type' => 'application/json'],
                '{"error":{"message":"invalid api key","type":"authentication_error"}}',
            ),
        ]);

        $primaryConfig = $this->buildConfiguration('primary', 'openai', 'gpt-4o', new FallbackChain(['fallback']));
        $openAi = $this->buildOpenAiProvider($primaryHttp);

        // Stub fallback anyway so we can assert it's never resolved
        $fallbackLookups = 0;
        $this->configRepositoryStub->method('findOneByIdentifier')
            ->willReturnCallback(function () use (&$fallbackLookups) {
                $fallbackLookups++;
                return null;
            });

        $this->adapterRegistryStub->method('createAdapterFromModel')
            ->willReturn($openAi);

        $manager = $this->buildManager();

        $this->expectException(ProviderResponseException::class);
        try {
            $manager->chatWithConfiguration(
                [['role' => 'user', 'content' => 'Hello']],
                $primaryConfig,
            );
        } finally {
            self::assertSame(0, $fallbackLookups, 'Fallback should not be consulted on 4xx');
        }
    }

    #[Test]
    public function allProvidersFailingRaisesExhaustedException(): void
    {
        $primaryHttp = $this->createHttpClientWithResponses(array_fill(
            0,
            5,
            new Response(503, ['Content-Type' => 'application/json'], '{"error":"down"}'),
        ));
        $fallbackHttp = $this->createHttpClientWithResponses(array_fill(
            0,
            5,
            new Response(503, ['Content-Type' => 'application/json'], '{"error":"down"}'),
        ));

        $primaryConfig = $this->buildConfiguration('primary', 'openai', 'gpt-4o', new FallbackChain(['fallback']));
        $fallbackConfig = $this->buildConfiguration('fallback', 'anthropic', 'claude-sonnet-4-20250514');

        $openAi = $this->buildOpenAiProvider($primaryHttp);
        $claude = $this->buildClaudeProvider($fallbackHttp);

        $this->configRepositoryStub->method('findOneByIdentifier')
            ->willReturnMap([['fallback', $fallbackConfig]]);

        $this->adapterRegistryStub->method('createAdapterFromModel')
            ->willReturnCallback(fn(Model $model) => $model->getModelId() === 'gpt-4o' ? $openAi : $claude);

        $manager = $this->buildManager();

        try {
            $manager->chatWithConfiguration(
                [['role' => 'user', 'content' => 'Hello']],
                $primaryConfig,
            );
            self::fail('Expected FallbackChainExhaustedException');
        } catch (FallbackChainExhaustedException $e) {
            self::assertSame(['primary', 'fallback'], $e->getAttemptedConfigurations());
        }
    }

    private function buildManager(): LlmServiceManager
    {
        $executor = new FallbackChainExecutor(
            $this->configRepositoryStub,
            new NullLogger(),
        );
        return new LlmServiceManager(
            $this->extensionConfigStub,
            new NullLogger(),
            $this->adapterRegistryStub,
            $executor,
        );
    }

    private function buildConfiguration(
        string $identifier,
        string $adapterType,
        string $modelId,
        ?FallbackChain $chain = null,
    ): LlmConfiguration {
        $provider = new Provider();
        $provider->setIdentifier($adapterType);
        $provider->setAdapterType($adapterType);

        $model = new Model();
        $model->setIdentifier($modelId);
        $model->setModelId($modelId);
        $model->setProvider($provider);

        $config = new LlmConfiguration();
        $config->setIdentifier($identifier);
        $config->setIsActive(true);
        $config->setLlmModel($model);
        if ($chain !== null) {
            $config->setFallbackChainDTO($chain);
        }
        return $config;
    }

    private function buildOpenAiProvider(ClientInterface&Stub $httpClient): OpenAiProvider
    {
        $provider = new OpenAiProvider(
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => 'sk-test-openai',
            'defaultModel' => 'gpt-4o',
            'maxRetries' => 1,
        ]);
        $provider->setHttpClient($httpClient);
        return $provider;
    }

    private function buildClaudeProvider(ClientInterface&Stub $httpClient): ClaudeProvider
    {
        $provider = new ClaudeProvider(
            $this->requestFactory,
            $this->streamFactory,
            $this->createNullLogger(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
        $provider->configure([
            'apiKeyIdentifier' => 'sk-test-claude',
            'defaultModel' => 'claude-sonnet-4-20250514',
            'maxRetries' => 1,
        ]);
        $provider->setHttpClient($httpClient);
        return $provider;
    }
}

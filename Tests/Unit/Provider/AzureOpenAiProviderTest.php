<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Provider\AzureOpenAiEndpoint;
use Netresearch\NrLlm\Provider\AzureOpenAiProvider;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use ReflectionClassConstant;
use stdClass;

/**
 * Azure OpenAI requests as they leave nr-llm: the URL, the key placement the
 * vault client is asked for, and the headers (ADR-247). The request is the one
 * the adapter hands to the vault HTTP client, recorded before anything is sent.
 */
#[CoversClass(AzureOpenAiProvider::class)]
#[CoversClass(AzureOpenAiEndpoint::class)]
final class AzureOpenAiProviderTest extends AbstractUnitTestCase
{
    private const CHAT_RESPONSE = [
        'id'      => 'x',
        'model'   => 'my-gpt',
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
        'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
    ];

    #[Test]
    public function aChatOnTheDeploymentApiGoesToTheDeploymentWithAnApiVersionAndTheKeyHeader(): void
    {
        $record   = new stdClass();
        $provider = $this->provider($record, ['baseUrl' => 'https://contoso.openai.azure.com', 'defaultModel' => 'my-gpt', 'organizationId' => 'org-1']);

        $provider->chatCompletion([['role' => 'user', 'content' => 'hi']]);

        self::assertInstanceOf(RequestInterface::class, $record->request);
        self::assertSame(
            'https://contoso.openai.azure.com/openai/deployments/my-gpt/chat/completions?api-version=2024-10-21',
            (string) $record->request->getUri(),
        );
        self::assertSame(SecretPlacement::Header, $record->placement);
        self::assertSame('api-key', $record->options['headerName'] ?? null);
        self::assertFalse($record->request->hasHeader('Authorization'), 'Azure reads the api-key header, not a bearer token.');
        self::assertFalse($record->request->hasHeader('OpenAI-Organization'));
    }

    #[Test]
    public function theProviderOptionSetsTheApiVersionAndAMalformedOneFallsBack(): void
    {
        $record = new stdClass();
        $this->provider($record, ['baseUrl' => 'https://contoso.openai.azure.com/openai/', 'defaultModel' => 'my gpt', 'apiVersion' => '2025-04-01-preview'])
            ->chatCompletion([['role' => 'user', 'content' => 'hi']]);
        self::assertInstanceOf(RequestInterface::class, $record->request);
        self::assertSame(
            'https://contoso.openai.azure.com/openai/deployments/my%20gpt/chat/completions?api-version=2025-04-01-preview',
            (string) $record->request->getUri(),
        );

        $record = new stdClass();
        $this->provider($record, ['baseUrl' => 'https://contoso.openai.azure.com', 'defaultModel' => 'm', 'apiVersion' => 'latest&x=1'])
            ->chatCompletion([['role' => 'user', 'content' => 'hi']]);
        self::assertInstanceOf(RequestInterface::class, $record->request);
        self::assertStringEndsWith('?api-version=2024-10-21', (string) $record->request->getUri());
    }

    #[Test]
    public function aV1EndpointKeepsTheOpenAiPathsWithoutAnApiVersion(): void
    {
        $record = new stdClass();
        $this->provider($record, ['baseUrl' => 'https://contoso.openai.azure.com/openai/v1', 'defaultModel' => 'my-gpt'])
            ->chatCompletion([['role' => 'user', 'content' => 'hi']]);

        self::assertInstanceOf(RequestInterface::class, $record->request);
        self::assertSame('https://contoso.openai.azure.com/openai/v1/chat/completions', (string) $record->request->getUri());
        self::assertSame(SecretPlacement::Header, $record->placement);
    }

    #[Test]
    public function anEmbeddingGoesToItsDeployment(): void
    {
        $record   = new stdClass();
        $provider = $this->provider($record, ['baseUrl' => 'https://contoso.openai.azure.com', 'defaultModel' => 'my-gpt'], [
            'data'  => [['embedding' => [0.1, 0.2], 'index' => 0]],
            'model' => 'emb',
            'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
        ]);

        $provider->embeddings('text', ['model' => 'emb-deploy']);

        self::assertInstanceOf(RequestInterface::class, $record->request);
        self::assertSame(
            'https://contoso.openai.azure.com/openai/deployments/emb-deploy/embeddings?api-version=2024-10-21',
            (string) $record->request->getUri(),
        );
    }

    #[Test]
    public function aStreamedChatGoesToTheDeploymentToo(): void
    {
        $record   = new stdClass();
        $provider = $this->provider($record, ['baseUrl' => 'https://contoso.openai.azure.com', 'defaultModel' => 'my-gpt'], null, "data: [DONE]\n\n");

        iterator_to_array($provider->streamChatCompletion([['role' => 'user', 'content' => 'hi']]));

        self::assertInstanceOf(RequestInterface::class, $record->request);
        self::assertSame(
            'https://contoso.openai.azure.com/openai/deployments/my-gpt/chat/completions?api-version=2024-10-21',
            (string) $record->request->getUri(),
        );
    }

    #[Test]
    public function theConnectionTestListsTheResourcesModels(): void
    {
        $record = new stdClass();
        $this->provider($record, ['baseUrl' => 'https://contoso.openai.azure.com', 'defaultModel' => 'my-gpt'], ['data' => [['id' => 'gpt-4o']]])
            ->testConnection();

        self::assertInstanceOf(RequestInterface::class, $record->request);
        self::assertSame('https://contoso.openai.azure.com/openai/models?api-version=2024-10-21', (string) $record->request->getUri());
    }

    #[Test]
    public function aDeploymentNameIsOnePathSegment(): void
    {
        self::assertSame(
            'https://contoso.openai.azure.com/openai/deployments/a%2Fb/chat/completions?api-version=2024-10-21',
            AzureOpenAiEndpoint::url('https://contoso.openai.azure.com', 'chat/completions', 'a/b', ''),
        );
    }

    #[Test]
    public function theResponsesApiGoesToTheV1PathOfTheResource(): void
    {
        self::assertSame(
            'https://contoso.openai.azure.com/openai/v1/responses',
            AzureOpenAiEndpoint::url('https://contoso.openai.azure.com', 'responses', 'd', ''),
        );
    }

    #[Test]
    public function theRegistryBuildsTheAzureAdapterForTheAzureAdapterType(): void
    {
        $map = (new ReflectionClassConstant(ProviderAdapterRegistry::class, 'ADAPTER_CLASS_MAP'))->getValue();
        self::assertIsArray($map);
        self::assertSame(AzureOpenAiProvider::class, $map['azure_openai'] ?? null);
        self::assertSame(OpenAiProvider::class, $map['openai'] ?? null);
    }

    #[Test]
    public function azureHostsAreRecognised(): void
    {
        self::assertTrue(AzureOpenAiEndpoint::isAzureHost('contoso.openai.azure.com'));
        self::assertTrue(AzureOpenAiEndpoint::isAzureHost('contoso.services.ai.azure.com'));
        self::assertTrue(AzureOpenAiEndpoint::isAzureHost('contoso.cognitiveservices.azure.com'));
        self::assertFalse(AzureOpenAiEndpoint::isAzureHost('api.openai.com'));
        self::assertFalse(AzureOpenAiEndpoint::isAzureHost('contoso.openai.azure.com.evil.test'));
    }

    /**
     * @param array<string, mixed>      $config
     * @param array<string, mixed>|null $json   the response body; null for the chat answer
     */
    private function provider(stdClass $record, array $config, ?array $json = null, ?string $raw = null): AzureOpenAiProvider
    {
        $client = self::createStub(VaultHttpClientInterface::class);
        $client->method('withAuthentication')->willReturnCallback(
            static function (string $id, SecretPlacement $placement, array $options) use ($record, $client): VaultHttpClientInterface {
                $record->placement = $placement;
                $record->options   = $options;

                return $client;
            },
        );
        $client->method('withTimeout')->willReturn($client);
        $client->method('withReason')->willReturn($client);
        $client->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use ($record, $json, $raw): Response {
                $record->request = $request;

                return $raw !== null
                    ? new Response(200, ['Content-Type' => 'text/event-stream'], $raw)
                    : new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($json ?? self::CHAT_RESPONSE));
            },
        );

        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $vault->method('http')->willReturn($client);

        $factory  = new HttpFactory();
        $provider = new AzureOpenAiProvider($factory, $factory, $this->createLoggerMock(), $vault, $this->createSecureHttpClientFactoryMock());
        $provider->configure(['apiKeyIdentifier' => 'vault-id', 'maxRetries' => 0] + $config);

        return $provider;
    }
}

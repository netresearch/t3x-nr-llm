<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider;

use Netresearch\NrLlm\Domain\Model\AdapterType;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\OpenAi\ModelRecordProfileResolver;
use Netresearch\NrLlm\Provider\OpenAi\OpenAiModelProfiles;
use Netresearch\NrLlm\Provider\OpenAi\ResponsesPayloadBuilder;
use Netresearch\NrLlm\Provider\OpenAi\ResponsesResultParser;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistry;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * An OpenAI adapter created from a model record sends by that record's
 * resolved reasoning profile, for that record's model id only (ADR-275, R6).
 *
 * Asserted on the raw request body, which is what OpenAI sees. The registry
 * caches one adapter per provider and reconfigures it per call, so the leak
 * cases matter as much as the positive ones: a profile set for one record
 * must not reach a call made without a record or for another model.
 */
#[CoversClass(ProviderAdapterRegistry::class)]
#[CoversClass(OpenAiProvider::class)]
#[UsesClass(ModelRecordProfileResolver::class)]
#[UsesClass(OpenAiModelProfiles::class)]
#[UsesClass(ResponsesPayloadBuilder::class)]
#[UsesClass(ResponsesResultParser::class)]
final class OpenAiRecordProfileWireTest extends AbstractUnitTestCase
{
    /** An id the bundled table does not know: not a reasoning model there. */
    private const UNLISTED = 'acme-reasoner-1';

    /** @var list<string> */
    private array $uris = [];

    /** @var list<string> */
    private array $rawBodies = [];

    #[Test]
    public function withoutARecordProfileAnUnlistedIdGetsATemperature(): void
    {
        // The control for every case below: the table alone sends one.
        [$registry, $provider] = $this->registryAndProvider(1);

        $this->chat($registry->createAdapterFromModel($this->model($provider, self::UNLISTED)));

        self::assertStringContainsString('"temperature":0.3', $this->lastBody());
    }

    #[Test]
    public function aReasoningOverrideStripsTheTemperature(): void
    {
        [$registry, $provider] = $this->registryAndProvider(2);
        $model                 = $this->model($provider, self::UNLISTED);
        $model->setReasoningOverride('yes');

        $this->chat($registry->createAdapterFromModel($model));

        self::assertStringNotContainsString('"temperature"', $this->lastBody());
        self::assertStringContainsString('"max_completion_tokens":16000', $this->lastBody());
    }

    /**
     * The call planner caps the budget at the record's output limit; the
     * reasoning floor must not raise it back above what the endpoint accepts.
     */
    #[Test]
    public function theReasoningFloorStopsAtTheRecordsOutputLimit(): void
    {
        [$registry, $provider] = $this->registryAndProvider(12);
        $model                 = $this->model($provider, self::UNLISTED);
        $model->setReasoningOverride('yes');
        $model->setMaxOutputTokens(8192);

        $this->chat($registry->createAdapterFromModel($model));

        self::assertStringContainsString('"max_completion_tokens":8192', $this->lastBody());

        // Another id on the same adapter is not bound by that record's limit.
        $this->chat($registry->createAdapterFromModel($model), ['model' => 'gpt-6-sol']);

        self::assertStringContainsString('"max_completion_tokens":16000', $this->lastBody());
    }

    #[Test]
    public function anEffortOverrideClampsTheRequestedEffortOntoItsScale(): void
    {
        [$registry, $provider] = $this->registryAndProvider(3);
        $model                 = $this->model($provider, self::UNLISTED);
        $model->setReasoningOverride('yes');
        $model->setReasoningEffortsOverride('low,medium');

        $this->chat($registry->createAdapterFromModel($model), ['reasoning_effort' => 'xhigh']);

        self::assertStringContainsString('"reasoning_effort":"medium"', $this->lastBody());
    }

    #[Test]
    public function aToolRouteOverrideSendsToolsThroughResponses(): void
    {
        [$registry, $provider] = $this->registryAndProvider(4);
        $model                 = $this->model($provider, self::UNLISTED);
        $model->setToolRouteOverride('responses');

        $adapter = $registry->createAdapterFromModel($model);
        self::assertInstanceOf(OpenAiProvider::class, $adapter);
        $this->answerWith($adapter, $this->responsesReply());

        $adapter->chatCompletionWithTools([['role' => 'user', 'content' => 'hi']], [$this->tool()]);

        self::assertStringEndsWith('/responses', end($this->uris) ?: '');
    }

    #[Test]
    public function theSameAdapterWithoutARecordUsesTheTableAgain(): void
    {
        [$registry, $provider] = $this->registryAndProvider(5);
        $model                 = $this->model($provider, self::UNLISTED);
        $model->setReasoningOverride('yes');

        $registry->createAdapterFromModel($model);

        // Cache hit: the very instance that just served the record.
        $adapter = $registry->createAdapterFromProvider($provider);
        $this->chat($adapter, ['model' => self::UNLISTED]);

        self::assertStringContainsString('"temperature":0.3', $this->lastBody());
    }

    #[Test]
    public function anotherRecordOnTheSameProviderGetsItsOwnProfile(): void
    {
        [$registry, $provider] = $this->registryAndProvider(6);
        $first                 = $this->model($provider, self::UNLISTED);
        $first->setReasoningOverride('yes');

        $registry->createAdapterFromModel($first);

        $this->chat($registry->createAdapterFromModel($this->model($provider, self::UNLISTED)));

        self::assertStringContainsString('"temperature":0.3', $this->lastBody());
    }

    #[Test]
    public function aRequestNamingAnotherModelUsesTheTableAlone(): void
    {
        [$registry, $provider] = $this->registryAndProvider(7);
        $model                 = $this->model($provider, self::UNLISTED);
        $model->setReasoningOverride('yes');

        $this->chat($registry->createAdapterFromModel($model), ['model' => 'acme-other-2']);

        self::assertStringContainsString('"temperature":0.3', $this->lastBody());
    }

    #[Test]
    public function theRecordProfileMatchesItsIdWhateverTheCase(): void
    {
        [$registry, $provider] = $this->registryAndProvider(8);
        $model                 = $this->model($provider, self::UNLISTED);
        $model->setReasoningOverride('yes');

        $this->chat($registry->createAdapterFromModel($model), ['model' => ' ACME-Reasoner-1 ']);

        self::assertStringNotContainsString('"temperature"', $this->lastBody());
    }

    /**
     * @return array{ProviderAdapterRegistry, Provider}
     */
    private function registryAndProvider(int $uid): array
    {
        $registry = new ProviderAdapterRegistry(
            $this->recordingRequestFactory(),
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );

        $provider = self::createStub(Provider::class);
        $provider->method('getUid')->willReturn($uid);
        $provider->method('getIdentifier')->willReturn('openai-' . $uid);
        $provider->method('getAdapterType')->willReturn(AdapterType::OpenAI->value);
        $provider->method('getApiKey')->willReturn('test-api-key');
        // Empty: the adapter falls back to api.openai.com, the one host the
        // Responses transport is used on.
        $provider->method('getEffectiveEndpointUrl')->willReturn('');
        $provider->method('getApiTimeout')->willReturn(30);
        $provider->method('getMaxRetries')->willReturn(0);
        $provider->method('getOptionsArray')->willReturn([]);

        return [$registry, $provider];
    }

    private function model(Provider $provider, string $modelId): Model
    {
        $model = new Model();
        $model->setIdentifier('record-' . $modelId);
        $model->setModelId($modelId);
        $model->setProvider($provider);

        return $model;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function chat(ProviderInterface $adapter, array $options = []): void
    {
        self::assertInstanceOf(OpenAiProvider::class, $adapter);
        $this->answerWith($adapter, (string) json_encode([
            'model'   => self::UNLISTED,
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ]));

        $adapter->chatCompletion([['role' => 'user', 'content' => 'hi']], ['temperature' => 0.3, ...$options]);
    }

    private function answerWith(OpenAiProvider $adapter, string $body): void
    {
        $client = self::createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            fn (): ResponseInterface => $this->createHttpResponseMock(200, $body),
        );
        // After configure(), which resets the client.
        $adapter->setHttpClient($client);
    }

    private function lastBody(): string
    {
        $body = end($this->rawBodies);
        self::assertIsString($body, 'No request body was recorded.');

        return $body;
    }

    private function tool(): ToolSpec
    {
        return ToolSpec::function('lookup', 'Look something up.', [
            'type'                 => 'object',
            'properties'           => ['q' => ['type' => 'string']],
            'required'             => ['q'],
            'additionalProperties' => false,
        ]);
    }

    private function responsesReply(): string
    {
        return (string) json_encode([
            'id'     => 'resp_1',
            'object' => 'response',
            'status' => 'completed',
            'model'  => self::UNLISTED,
            'output' => [[
                'type'    => 'message',
                'role'    => 'assistant',
                'content' => [['type' => 'output_text', 'text' => 'ok']],
            ]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]);
    }

    private function recordingRequestFactory(): RequestFactoryInterface
    {
        $stub = self::createStub(RequestFactoryInterface::class);
        $stub->method('createRequest')->willReturnCallback(
            function (string $method, string $uri): RequestInterface {
                $this->uris[] = $uri;

                $uriStub = self::createStub(UriInterface::class);
                $uriStub->method('__toString')->willReturn($uri);
                $uriStub->method('getHost')->willReturn((string) (parse_url($uri, PHP_URL_HOST) ?? ''));
                $uriStub->method('getPath')->willReturn((string) (parse_url($uri, PHP_URL_PATH) ?? ''));

                $request = self::createStub(RequestInterface::class);
                $request->method('withHeader')->willReturnCallback(static fn (): RequestInterface => $request);
                $request->method('withoutHeader')->willReturnCallback(static fn (): RequestInterface => $request);
                $request->method('getMethod')->willReturn($method);
                $request->method('getUri')->willReturn($uriStub);
                $request->method('withBody')->willReturnCallback(
                    function (StreamInterface $body) use ($request): RequestInterface {
                        $this->rawBodies[] = $body->getContents();

                        return $request;
                    },
                );

                return $request;
            },
        );

        return $stub;
    }
}

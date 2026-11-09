<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard\Discovery;

use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\OpenRouterModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\OpenRouterReasoning;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscovery;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenRouter discovery reads each model's reasoning flag and effort scale
 * (ADR-274, R3).
 *
 * The fixture holds five entries copied verbatim from
 * `GET https://openrouter.ai/api/v1/models` on 2026-11-09: two GPT-6 models
 * with a `reasoning` object, `openai/gpt-4.1-mini` without one (the key is
 * absent, not null), the `openrouter/auto` router, and an entry whose
 * `reasoning` object lists no efforts.
 */
#[CoversClass(OpenRouterModelDiscoverer::class)]
#[CoversClass(OpenRouterReasoning::class)]
final class OpenRouterReasoningDiscoveryTest extends AbstractUnitTestCase
{
    /** @var array<string, DiscoveredModel> */
    private array $byId = [];

    protected function setUp(): void
    {
        parent::setUp();

        $body = file_get_contents(__DIR__ . '/../Fixtures/openrouter-models-2026-11-09.json');
        self::assertIsString($body);

        $stream = self::createStub(StreamInterface::class);
        $stream->method('getContents')->willReturn($body);
        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);
        $client = self::createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturn($response);

        $requestFactory = self::createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($this->createRequestMock());

        $discovery = new ModelDiscovery(
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
            $requestFactory,
            self::createStub(StreamFactoryInterface::class),
            self::createStub(LoggerInterface::class),
        );
        $discovery->setHttpClient($client);

        foreach ($discovery->discover(new DetectedProvider('openrouter', 'OpenRouter', 'https://openrouter.ai/api/v1'), 'key') as $model) {
            $this->byId[$model->modelId] = $model;
        }

        self::assertCount(5, $this->byId);
    }

    #[Test]
    public function aReasoningObjectIsAReasoningModelWithItsScaleLowestFirst(): void
    {
        $astra = $this->byId['openai/gpt-6-astra'];

        self::assertTrue($astra->reasoning);
        self::assertSame(
            [ReasoningEffort::Low, ReasoningEffort::Medium, ReasoningEffort::High, ReasoningEffort::XHigh, ReasoningEffort::Max],
            $astra->reasoningEfforts,
        );

        // Terra accepts `none`, and OpenRouter says so.
        self::assertSame(ReasoningEffort::None, $this->byId['openai/gpt-6.1-terra']->reasoningEfforts[0]);
    }

    #[Test]
    public function noObjectAndNoReasoningParameterIsAMeasuredNo(): void
    {
        $mini = $this->byId['openai/gpt-4.1-mini'];

        self::assertFalse($mini->reasoning);
        self::assertSame([], $mini->reasoningEfforts);
    }

    #[Test]
    public function aRouterThatTakesTheParameterWithoutAnObjectStatesNothing(): void
    {
        self::assertNull($this->byId['openrouter/auto']->reasoning);
    }

    #[Test]
    public function anObjectWithoutEffortsIsAReasoningModelWithoutAScale(): void
    {
        $reka = $this->byId['rekaai/reka-edge'];

        self::assertTrue($reka->reasoning);
        self::assertSame([], $reka->reasoningEfforts);
    }

    #[Test]
    public function anEntryWithoutAParameterListStatesNothing(): void
    {
        self::assertNull(OpenRouterReasoning::statedBy(['id' => 'x/y']));
        self::assertFalse(OpenRouterReasoning::statedBy(['id' => 'x/y', 'supported_parameters' => ['tools']]));
        self::assertSame([], OpenRouterReasoning::effortsStatedBy(['reasoning' => ['supported_efforts' => ['turbo', 7]]]));
    }
}

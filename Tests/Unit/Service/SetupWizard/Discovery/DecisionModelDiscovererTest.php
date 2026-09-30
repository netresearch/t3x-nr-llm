<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\SetupWizard\Discovery;

use Netresearch\NrLlm\Service\SetupWizard\Discovery\DecisionSidecarModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\TypeSafeModelDiscoverer;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DetectedProvider;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscovery;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Model discovery for the two decision adapters (ADR-211), through the
 * facade the setup wizard and the model form use.
 */
#[CoversClass(TypeSafeModelDiscoverer::class)]
#[CoversClass(DecisionSidecarModelDiscoverer::class)]
final class DecisionModelDiscovererTest extends AbstractUnitTestCase
{
    /** @var list<array{method: string, url: string, headers: array<string, string>}> */
    private array $sent = [];

    private function discovery(ResponseInterface|RuntimeException $answer): ModelDiscovery
    {
        $requestFactory = self::createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturnCallback(
            function (string $method, string $url): RequestInterface {
                $index = count($this->sent);
                $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => []];

                $request = self::createStub(RequestInterface::class);
                $request->method('withHeader')->willReturnCallback(
                    function (string $name, string $value) use ($request, $index): RequestInterface {
                        $this->sent[$index]['headers'][$name] = $value;

                        return $request;
                    },
                );

                return $request;
            },
        );

        $client = self::createStub(ClientInterface::class);
        if ($answer instanceof RuntimeException) {
            $client->method('sendRequest')->willThrowException($answer);
        } else {
            $client->method('sendRequest')->willReturn($answer);
        }

        $discovery = new ModelDiscovery(
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
            $requestFactory,
            $this->createStreamFactoryMock(),
            $this->createLoggerMock(),
        );
        $discovery->setHttpClient($client);

        return $discovery;
    }

    private function typeSafe(): DetectedProvider
    {
        return new DetectedProvider(adapterType: 'typesafe', suggestedName: 'TypeSafe', endpoint: 'https://api.typesafe.ai/v1/');
    }

    private function sidecar(): DetectedProvider
    {
        return new DetectedProvider(adapterType: 'decision_sidecar', suggestedName: 'Local decisions', endpoint: 'https://decision.example.test:8082');
    }

    /**
     * @param array<DiscoveredModel> $models
     *
     * @return array<string, DiscoveredModel>
     */
    private function byId(array $models): array
    {
        $byId = [];
        foreach ($models as $model) {
            $byId[$model->modelId] = $model;
        }

        return $byId;
    }

    #[Test]
    public function typeSafeListsThePinnedVersionFirstAndRecommendedThenTheAliases(): void
    {
        $discovery = $this->discovery($this->createJsonResponseMock(['models' => [
            ['name' => 'jev-latest', 'description' => 'Latest stable', 'release_date' => '2026-06-01'],
            ['name' => 'jev-preview'],
            ['name' => 'jev-1.13.0', 'description' => 'listed too'],
            ['description' => 'no name'],
            'not an object',
        ]]));

        $models = $discovery->discover($this->typeSafe(), 'ts-key');

        self::assertSame('GET', $this->sent[0]['method']);
        self::assertSame('https://api.typesafe.ai/v1/models', $this->sent[0]['url']);
        self::assertSame('Bearer ts-key', $this->sent[0]['headers']['Authorization'] ?? null);
        self::assertFalse($discovery->wasLastDiscoveryFromFallback());

        self::assertSame(['jev-1.13.0', 'jev-latest', 'jev-preview'], array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models));
        $byId = $this->byId($models);
        self::assertTrue($byId['jev-1.13.0']->recommended);
        self::assertFalse($byId['jev-latest']->recommended, 'an alias moves without notice');
        self::assertSame('Latest stable', $byId['jev-latest']->description);
        self::assertStringContainsString('moves without notice', $byId['jev-preview']->description);
    }

    #[Test]
    public function everyTypeSafeModelDecidesAndIsPricedPerInputToken(): void
    {
        $models = $this->discovery($this->createJsonResponseMock(['models' => [['name' => 'jev-latest']]]))
            ->discover($this->typeSafe(), 'ts-key');

        foreach ($models as $model) {
            self::assertSame(['decision'], $model->capabilities, $model->modelId);
            // 0.042 USD per million input tokens, in cents; output free.
            self::assertSame(4.2, $model->costInput, $model->modelId);
            self::assertSame(0.0, $model->costOutput, $model->modelId);
            self::assertSame(64000, $model->contextLength, $model->modelId);
        }
    }

    /**
     * @return iterable<string, array{RuntimeException|null}>
     */
    public static function failedListings(): iterable
    {
        yield 'a refused request' => [null];
        yield 'an exception' => [new RuntimeException('connection refused')];
    }

    #[Test]
    #[DataProvider('failedListings')]
    public function aFailedTypeSafeListingFallsBackToThePinnedVersion(?RuntimeException $failure): void
    {
        $discovery = $this->discovery($failure ?? $this->createJsonResponseMock(['detail' => 'Invalid API key'], 401));

        $models = $discovery->discover($this->typeSafe(), 'wrong');

        self::assertTrue($discovery->wasLastDiscoveryFromFallback());
        self::assertSame(['jev-1.13.0'], array_map(static fn(DiscoveredModel $m): string => $m->modelId, $models));
    }

    #[Test]
    public function anUnreadableTypeSafeListingFallsBackToo(): void
    {
        $discovery = $this->discovery($this->createHttpResponseMock(200, 'not json'));

        self::assertSame(['jev-1.13.0'], array_map(static fn(DiscoveredModel $m): string => $m->modelId, $discovery->discover($this->typeSafe(), 'k')));
        self::assertTrue($discovery->wasLastDiscoveryFromFallback());
    }

    #[Test]
    public function theSidecarListsTheModelItLoadedWithoutKeyOrPrice(): void
    {
        $models = $this->discovery($this->createJsonResponseMock(['models' => [['name' => 'local-nli'], ['name' => ''], 'x']]))
            ->discover($this->sidecar(), '');

        self::assertSame('https://decision.example.test:8082/models', $this->sent[0]['url']);
        self::assertSame([], $this->sent[0]['headers'], 'the sidecar needs no key');

        self::assertCount(1, $models);
        self::assertSame('local-nli', $models[0]->modelId);
        self::assertSame(['decision'], $models[0]->capabilities);
        self::assertSame([0.0, 0.0], [$models[0]->costInput, $models[0]->costOutput], 'a local model has no price');
        self::assertTrue($models[0]->recommended);
    }

    /**
     * @return iterable<string, array{RuntimeException|null}>
     */
    public static function failedSidecarListings(): iterable
    {
        yield 'a server error' => [null];
        yield 'an unreachable sidecar' => [new RuntimeException('no route to host')];
    }

    #[Test]
    #[DataProvider('failedSidecarListings')]
    public function aFailedSidecarListingOffersNothing(?RuntimeException $failure): void
    {
        $models = $this->discovery($failure ?? $this->createJsonResponseMock([], 503))->discover($this->sidecar(), '');

        self::assertSame([], $models);
    }

    #[Test]
    public function theConnectionTestSendsTheKeyToTypeSafeAndNoneToTheSidecar(): void
    {
        self::assertTrue($this->discovery($this->createJsonResponseMock(['models' => []]))->testConnection($this->typeSafe(), 'ts-key')['success']);
        self::assertTrue($this->discovery($this->createJsonResponseMock(['models' => []]))->testConnection($this->sidecar(), '')['success']);

        self::assertCount(2, $this->sent);
        self::assertSame('Bearer ts-key', $this->sent[0]['headers']['Authorization'] ?? null);
        self::assertSame('https://decision.example.test:8082/models', $this->sent[1]['url']);
        self::assertArrayNotHasKey('Authorization', $this->sent[1]['headers']);
    }
}

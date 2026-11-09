<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Model;

use GuzzleHttp\Psr7\HttpFactory;
use Netresearch\NrLlm\Service\Model\ThirdPartyReasoningCatalog;
use Netresearch\NrLlm\Service\SetupWizard\Discovery\OpenRouterReasoning;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The opt-in third-party catalogue lookup (ADR-275, R4): off by default, one
 * anonymous GET without credential or content, and every failure answers
 * null, which records nothing.
 */
#[CoversClass(ThirdPartyReasoningCatalog::class)]
#[UsesClass(OpenRouterReasoning::class)]
final class ThirdPartyReasoningCatalogTest extends AbstractUnitTestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    #[Test]
    public function itIsOffByDefaultAndSendsNothing(): void
    {
        $catalog = $this->catalog(settings: [], reply: $this->fixtureReply());

        self::assertFalse($catalog->isEnabled());
        self::assertNull($catalog->reasoningFor('gpt-6-astra'));
        self::assertSame([], $this->sent);
    }

    #[Test]
    public function anUnreadableSettingCountsAsOff(): void
    {
        $catalog = $this->catalog(settings: new RuntimeException('no configuration'), reply: $this->fixtureReply());

        self::assertNull($catalog->reasoningFor('gpt-6-astra'));
        self::assertSame([], $this->sent);
    }

    #[Test]
    public function theLookupIsOneAnonymousGetWithoutABody(): void
    {
        $catalog = $this->catalog(settings: ['thirdPartyReasoningCatalog' => '1'], reply: $this->fixtureReply());

        self::assertTrue($catalog->reasoningFor('gpt-6-astra'));

        self::assertCount(1, $this->sent);
        $request = $this->sent[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame(ThirdPartyReasoningCatalog::CATALOGUE_URL, (string) $request->getUri());
        $headerNames = array_map(strtolower(...), array_keys($request->getHeaders()));
        self::assertSame([], array_values(array_intersect($headerNames, ['authorization', 'x-api-key', 'cookie'])));
        self::assertSame('', (string) $request->getBody());
    }

    #[Test]
    public function aModelWithoutAReasoningObjectIsANo(): void
    {
        $catalog = $this->catalog(settings: ['thirdPartyReasoningCatalog' => '1'], reply: $this->fixtureReply());

        self::assertFalse($catalog->reasoningFor('gpt-4.1-mini'));
    }

    /**
     * The catalogue lists the family name only; a dated OpenAI snapshot
     * reasons as its family does.
     */
    #[Test]
    public function aDatedSnapshotIsLookedUpUnderItsFamilyName(): void
    {
        $catalog = $this->catalog(settings: ['thirdPartyReasoningCatalog' => '1'], reply: $this->fixtureReply());

        self::assertFalse($catalog->reasoningFor('gpt-4.1-mini-2025-04-14'));
        self::assertTrue($catalog->reasoningFor('GPT-6-Astra-2026-09-03'));
        // Only a full `-YYYY-MM-DD` suffix is a snapshot.
        self::assertNull($catalog->reasoningFor('gpt-6-astra-2026'));
    }

    /**
     * A snapshot the catalogue lists itself is answered by its own entry,
     * also when that entry states nothing; the family's answer is not used.
     */
    #[Test]
    public function aListedSnapshotIsNotAnsweredByItsFamily(): void
    {
        $body = (string) json_encode(['data' => [
            ['id' => 'openai/gpt-4o', 'supported_parameters' => ['temperature']],
            ['id' => 'openai/gpt-4o-2024-11-20'],
        ]]);
        $catalog = $this->catalog(
            settings: ['thirdPartyReasoningCatalog' => '1'],
            reply: $this->createHttpResponseMock(200, $body),
        );

        self::assertFalse($catalog->reasoningFor('gpt-4o'));
        self::assertNull($catalog->reasoningFor('gpt-4o-2024-11-20'));
    }

    #[Test]
    public function aModelTheCatalogueDoesNotListStatesNothing(): void
    {
        $catalog = $this->catalog(settings: ['thirdPartyReasoningCatalog' => '1'], reply: $this->fixtureReply());

        self::assertNull($catalog->reasoningFor('gpt-9-unreleased'));
        self::assertNull($catalog->reasoningFor(''));
    }

    #[Test]
    public function aTransportFailureStatesNothing(): void
    {
        $catalog = $this->catalog(settings: ['thirdPartyReasoningCatalog' => '1'], reply: new RuntimeException('connection refused'));

        self::assertNull($catalog->reasoningFor('gpt-6-astra'));
    }

    #[Test]
    public function anErrorStatusStatesNothing(): void
    {
        $catalog = $this->catalog(settings: ['thirdPartyReasoningCatalog' => '1'], reply: $this->createHttpResponseMock(503, 'unavailable'));

        self::assertNull($catalog->reasoningFor('gpt-6-astra'));
    }

    #[Test]
    public function anUnparseableBodyStatesNothing(): void
    {
        $catalog = $this->catalog(settings: ['thirdPartyReasoningCatalog' => '1'], reply: $this->createHttpResponseMock(200, '<html>'));

        self::assertNull($catalog->reasoningFor('gpt-6-astra'));
    }

    /**
     * @param array<string, mixed>|RuntimeException $settings
     */
    private function catalog(array|RuntimeException $settings, ResponseInterface|RuntimeException $reply): ThirdPartyReasoningCatalog
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        if ($settings instanceof RuntimeException) {
            $extensionConfiguration->method('get')->willThrowException($settings);
        } else {
            $extensionConfiguration->method('get')->willReturn($settings);
        }

        $client = self::createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request) use ($reply): ResponseInterface {
                $this->sent[] = $request;
                if ($reply instanceof RuntimeException) {
                    throw $reply;
                }

                return $reply;
            },
        );

        $catalog = new ThirdPartyReasoningCatalog(
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
            // A real PSR-17 factory, so the request is the one that would go out.
            new HttpFactory(),
            $extensionConfiguration,
            self::createStub(LoggerInterface::class),
        );
        $catalog->setHttpClient($client);

        return $catalog;
    }

    private function fixtureReply(): ResponseInterface
    {
        $body = file_get_contents(__DIR__ . '/../SetupWizard/Fixtures/openrouter-models-2026-11-09.json');
        self::assertIsString($body);

        return $this->createHttpResponseMock(200, $body);
    }
}

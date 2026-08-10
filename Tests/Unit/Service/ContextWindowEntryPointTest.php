<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Context\ContextWindowManager;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The same payload, the same bound, whichever public entry point sends it
 * (ADR-143).
 *
 * Before this, `ConversationService` and `ToolLoopService` bound their sends
 * and the generic manager paths did not — so which API a consumer happened to
 * call decided whether a long transcript was pruned or handed to the provider
 * whole. These tests assert against what the ADAPTER actually received, not
 * against a return value.
 */
#[CoversClass(LlmServiceManager::class)]
final class ContextWindowEntryPointTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;

    /** Small enough that the transcript below cannot fit. */
    private const WINDOW = 4000;

    #[Test]
    public function chatPrunesAnOversizedTranscriptBeforeItReachesTheProvider(): void
    {
        $captured = [];
        $adapter  = $this->createMock(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$captured): CompletionResponse {
                $captured = $messages;

                return $this->response();
            },
        );

        $messages = $this->oversizedTranscript();
        $this->manager($adapter)->chatForConfiguration($messages, $this->configuration());

        self::assertNotSame([], $captured);
        self::assertLessThan(count($messages), count($captured), 'the send was bounded, not passed through');
    }

    #[Test]
    public function anUnboundManagerStillSendsEverything(): void
    {
        // The control. Without a context-window manager the behaviour is the
        // pre-ADR-143 one — bounded by the provider, not by us — which is what
        // makes the assertion above a measurement rather than a coincidence.
        $captured = [];
        $adapter  = $this->createMock(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$captured): CompletionResponse {
                $captured = $messages;

                return $this->response();
            },
        );

        $messages = $this->oversizedTranscript();
        $this->manager($adapter, bound: false)->chatForConfiguration($messages, $this->configuration());

        self::assertCount(count($messages), $captured);
    }

    #[Test]
    public function theBoundIsTheSameThroughChatWithConfiguration(): void
    {
        // Two public entry points, one payload, one effective budget: the point
        // of the epic is that the choice of API does not decide it.
        $viaChatFor = $this->captureThrough(
            fn(LlmServiceManager $m, array $messages): mixed => $m->chatForConfiguration($messages, $this->configuration()),
        );
        $viaChatWith = $this->captureThrough(
            fn(LlmServiceManager $m, array $messages): mixed => $m->chatWithConfiguration($messages, $this->configuration()),
        );

        self::assertCount(count($viaChatFor), $viaChatWith);
    }

    /**
     * @param callable(LlmServiceManager, list<ChatMessage>): mixed $send
     *
     * @return list<mixed>
     */
    private function captureThrough(callable $send): array
    {
        $captured = [];
        $adapter  = $this->createMock(ProviderInterface::class);
        $adapter->method('chatCompletion')->willReturnCallback(
            function (array $messages) use (&$captured): CompletionResponse {
                $captured = $messages;

                return $this->response();
            },
        );

        $send($this->manager($adapter), $this->oversizedTranscript());

        /** @var list<mixed> $captured */
        return $captured;
    }

    /**
     * A transcript that cannot fit {@see self::WINDOW}, in whole turns so there
     * is something to drop.
     *
     * @return list<ChatMessage>
     */
    private function oversizedTranscript(): array
    {
        $big      = str_repeat('x', 4000);
        $messages = [ChatMessage::system('sys'), ChatMessage::user('do the task')];
        for ($i = 0; $i < 6; ++$i) {
            $messages[] = ChatMessage::user('turn ' . $i . ' ' . $big);
            $messages[] = ChatMessage::assistant('answer ' . $i . ' ' . $big);
        }

        return $messages;
    }

    private function manager(ProviderInterface $adapter, bool $bound = true): LlmServiceManager
    {
        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);

        return $this->createLlmServiceManager(
            $this->extensionConfigStub(),
            self::createStub(LoggerInterface::class),
            $registry,
            $this->emptyMiddlewarePipeline(),
            self::createStub(CacheManagerInterface::class),
            null,
            null,
            null,
            null,
            null,
            null,
            $bound ? new ContextWindowManager() : null,
        );
    }

    private function extensionConfigStub(): ExtensionConfiguration
    {
        $stub = self::createStub(ExtensionConfiguration::class);
        $stub->method('get')->willReturn(['providers' => []]);

        return $stub;
    }

    private function configuration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setIdentifier('openai');
        $provider->setAdapterType('openai');

        $model = new Model();
        $model->setModelId('small');
        $model->setContextLength(self::WINDOW);
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('bounded');
        $configuration->setLlmModel($model);

        return $configuration;
    }

    private function response(): CompletionResponse
    {
        return new CompletionResponse(
            content: 'ok',
            model: 'small',
            usage: new UsageStatistics(1, 1, 2),
            provider: 'openai',
        );
    }
}

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
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Middleware\CacheMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Privacy\RunProviderPrivacyInterface;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

/**
 * Which calls carry the answer-cache key (ADR-249): a plain chat or
 * completion of a configuration that opted in. Never a call of an agent run,
 * never a tool call, never a configuration that did not opt in.
 */
#[CoversClass(LlmServiceManager::class)]
final class LlmServiceManagerAnswerCacheTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;

    /** @var list<array<string, mixed>> */
    private array $seen = [];

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(array $metadata): void
    {
        $this->seen[] = $metadata;
    }

    #[Test]
    public function aChatOfAnOptedInConfigurationCarriesTheKey(): void
    {
        $this->manager()->chatWithConfiguration([ChatMessage::user('Hallo')], $this->configuration(600));

        self::assertTrue($this->seen[0][CacheMiddleware::METADATA_ANSWER_CACHE] ?? null);
        self::assertSame(600, $this->seen[0][CacheMiddleware::METADATA_CACHE_TTL] ?? null);
    }

    #[Test]
    public function aCompletionOfAnOptedInConfigurationCarriesTheKey(): void
    {
        $this->manager()->completeWithConfiguration('Hallo', $this->configuration(600));

        self::assertTrue($this->seen[0][CacheMiddleware::METADATA_ANSWER_CACHE] ?? null);
    }

    #[Test]
    public function theDefaultConfigurationPathCarriesTheKeyWithTheCallersSite(): void
    {
        $manager = $this->manager($this->configuration(600));
        $manager->chat([['role' => 'user', 'content' => 'Hallo']], (new ChatOptions())->withSite('shop'));
        $manager->chat([['role' => 'user', 'content' => 'Hallo']], (new ChatOptions())->withSite('blog'));
        $manager->complete('Hallo', (new ChatOptions())->withSite('shop'));
        $manager->complete('Hallo', (new ChatOptions())->withSite('blog'));

        self::assertCount(4, $this->seen);
        foreach ($this->seen as $metadata) {
            self::assertTrue($metadata[CacheMiddleware::METADATA_ANSWER_CACHE] ?? null);
        }

        // Two sites apply two term lists: their answers are not shared.
        self::assertNotSame($this->seen[0][CacheMiddleware::METADATA_CACHE_KEY], $this->seen[1][CacheMiddleware::METADATA_CACHE_KEY]);
        self::assertNotSame($this->seen[2][CacheMiddleware::METADATA_CACHE_KEY], $this->seen[3][CacheMiddleware::METADATA_CACHE_KEY]);
    }

    #[Test]
    public function aCallerThatDeclinesTheAnswerCacheGetsNoEntry(): void
    {
        $manager = $this->manager($this->configuration(600));
        $manager->chat([['role' => 'user', 'content' => 'Hallo']], (new ChatOptions())->withoutAnswerCache());
        $manager->complete('Hallo', (new ChatOptions())->withoutAnswerCache());
        $manager->chatForConfiguration([ChatMessage::user('Hallo')], $this->configuration(600), (new ChatOptions())->withoutAnswerCache());
        $manager->completeForConfiguration('Hallo', $this->configuration(600), (new ChatOptions())->withoutAnswerCache());

        self::assertCount(4, $this->seen);
        foreach ($this->seen as $metadata) {
            self::assertArrayNotHasKey(CacheMiddleware::METADATA_CACHE_KEY, $metadata);
            self::assertFalse($metadata[CacheMiddleware::METADATA_ANSWER_CACHE] ?? null);
        }
    }

    #[Test]
    public function aConfigurationThatDidNotOptInCarriesNoKey(): void
    {
        $this->manager()->chatWithConfiguration([ChatMessage::user('Hallo')], $this->configuration(0));
        $this->manager()->completeWithConfiguration('Hallo', $this->configuration(0));

        foreach ($this->seen as $metadata) {
            self::assertArrayNotHasKey(CacheMiddleware::METADATA_CACHE_KEY, $metadata);
        }

        self::assertCount(2, $this->seen);
    }

    #[Test]
    public function aCallOfAnAgentRunCarriesNoKey(): void
    {
        $this->manager()->chatWithConfiguration([ChatMessage::user('Hallo')], $this->configuration(600), [], [], new AgentRunReference(5, 'run-uuid'));

        self::assertCount(1, $this->seen);
        self::assertArrayNotHasKey(CacheMiddleware::METADATA_CACHE_KEY, $this->seen[0]);
    }

    #[Test]
    public function aToolCallCarriesNoKey(): void
    {
        $this->manager()->chatWithToolsForConfiguration([ChatMessage::user('Hallo')], [], $this->configuration(600));

        self::assertCount(1, $this->seen);
        self::assertArrayNotHasKey(CacheMiddleware::METADATA_CACHE_KEY, $this->seen[0]);
    }

    #[Test]
    public function aCallersOwnCacheKeyIsNotTurnedIntoAnAnswerEntry(): void
    {
        $this->manager()->chatWithConfiguration([ChatMessage::user('Hallo')], $this->configuration(600), [CacheMiddleware::METADATA_CACHE_KEY => 'mine']);

        self::assertSame('mine', $this->seen[0][CacheMiddleware::METADATA_CACHE_KEY]);
        self::assertArrayNotHasKey(CacheMiddleware::METADATA_ANSWER_CACHE, $this->seen[0]);
        self::assertArrayNotHasKey(CacheMiddleware::METADATA_CACHE_TTL, $this->seen[0]);
    }

    private function manager(
        ?LlmConfiguration $default = null,
    ): LlmServiceManager {
        $adapter = self::createStubForIntersectionOfInterfaces(
            [ProviderInterface::class, ToolCapableInterface::class],
        );
        $adapter->method('getIdentifier')->willReturn('openai');
        $adapter
            ->method('chatCompletion')
            ->willReturn(
                new CompletionResponse(
                    'ok',
                    'm',
                    new UsageStatistics(1, 1, 2),
                    'stop',
                    'openai',
                ),
            );
        $adapter
            ->method('complete')
            ->willReturn(
                new CompletionResponse(
                    'ok',
                    'm',
                    new UsageStatistics(1, 1, 2),
                    'stop',
                    'openai',
                ),
            );
        $adapter->method('supportsTools')->willReturn(true);
        $adapter
            ->method('chatCompletionWithTools')
            ->willReturn(
                new CompletionResponse(
                    'ok',
                    'm',
                    new UsageStatistics(1, 1, 2),
                    'stop',
                    'openai',
                ),
            );

        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturn($adapter);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($default);

        $recorder = new class ($this) implements ProviderMiddlewareInterface {
            public function __construct(
                private readonly LlmServiceManagerAnswerCacheTest $test,
            ) {}

            public function handle(
                ProviderCallContext $context,
                callable $next,
            ): mixed {
                $this->test->record($context->metadata);

                return $next($context);
            }
        };

        return $this->createLlmServiceManager(
            $this->createExtensionConfigurationMock(['providers' => []]),
            self::createStub(LoggerInterface::class),
            $registry,
            new MiddlewarePipeline([$recorder]),
            self::createStub(CacheManagerInterface::class),
            $repository,
            runPrivacy: self::createStub(
                RunProviderPrivacyInterface::class,
            ),
        );
    }

    private function configuration(int $ttl): LlmConfiguration
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getIdentifier')->willReturn('writer');
        $configuration->method('getAnswerCacheTtl')->willReturn($ttl);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn(false);
        $model = self::createStub(Model::class);
        $model->method('getModelId')->willReturn('m');
        $configuration->method('getLlmModel')->willReturn($model);
        $configuration->method('toOptionsArray')->willReturn([]);
        $configuration->method('getFallbackChainDTO')->willReturn(FallbackChain::fromArray([]));

        return $configuration;
    }
}

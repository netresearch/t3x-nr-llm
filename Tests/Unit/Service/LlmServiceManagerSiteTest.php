<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Generator;
use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Feature\CompletionService;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use Netresearch\NrLlm\Service\Site\SiteLlmSettings;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Site\SiteLlmSettingsTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

/**
 * A generic call that states its site runs on the configuration the site
 * names, and carries the site to the middleware (ADR-244).
 */
#[CoversClass(LlmServiceManager::class)]
#[CoversClass(ConfigurationResolver::class)]
#[CoversClass(CompletionService::class)]
final class LlmServiceManagerSiteTest extends AbstractUnitTestCase
{
    use LlmServiceManagerTestFactory;

    /** @var list<array{string, array<string, mixed>}> */
    private array $calls = [];

    /** @var list<string> */
    private array $servedModels = [];

    /**
     * @param array<string, mixed> $metadata
     */
    public function record(string $configuration, array $metadata): void
    {
        $this->calls[] = [$configuration, $metadata];
    }

    #[Test]
    public function chatOfASiteRunsOnTheSitesConfigurationAndNamesTheSite(): void
    {
        $this->manager()->chat([['role' => 'user', 'content' => 'Hi']], (new ChatOptions())->withSite('shop'));

        self::assertCount(1, $this->calls);
        self::assertSame('shop-writer', $this->calls[0][0]);
        self::assertSame('shop', $this->calls[0][1][ProviderCallContext::METADATA_SITE]);
    }

    #[Test]
    public function completeOfASiteRunsOnTheSitesConfiguration(): void
    {
        $this->manager()->complete('Hi', (new ChatOptions())->withSite('shop'));

        self::assertSame('shop-writer', $this->calls[0][0]);
    }

    #[Test]
    public function streamChatOfASiteRunsOnTheSitesConfiguration(): void
    {
        $chunks = $this->manager()->streamChat([['role' => 'user', 'content' => 'Hi']], (new ChatOptions())->withSite('shop'));
        foreach ($chunks as $chunk) {
            self::assertIsString($chunk);
        }

        // The raw streaming path runs no middleware; the model it asks the
        // registry for is the site configuration's.
        self::assertSame(['shop-writer'], $this->servedModels);
    }

    /**
     * The route nr-repurpose and nr-landingpage take: the completion service
     * keeps the caller's options object, site included.
     */
    #[Test]
    public function theCompletionServiceForwardsTheSite(): void
    {
        (new CompletionService($this->manager()))->completeMarkdown('Hi', (new ChatOptions())->withSite('shop'));

        self::assertSame('shop-writer', $this->calls[0][0]);
        self::assertSame('shop', $this->calls[0][1][ProviderCallContext::METADATA_SITE]);
    }

    #[Test]
    public function aSiteThatNamesNoneAndACallWithoutASiteRunOnTheInstallationDefault(): void
    {
        $manager = $this->manager();
        $manager->chat([['role' => 'user', 'content' => 'Hi']], (new ChatOptions())->withSite('blog'));
        $manager->chat([['role' => 'user', 'content' => 'Hi']]);

        self::assertSame('installation-default', $this->calls[0][0]);
        self::assertSame('blog', $this->calls[0][1][ProviderCallContext::METADATA_SITE]);
        self::assertSame('installation-default', $this->calls[1][0]);
        self::assertArrayNotHasKey(ProviderCallContext::METADATA_SITE, $this->calls[1][1]);
    }

    private function manager(): LlmServiceManager
    {
        $adapter = self::createStubForIntersectionOfInterfaces([ProviderInterface::class, StreamingCapableInterface::class]);
        $adapter->method('getIdentifier')->willReturn('openai');
        $adapter->method('chatCompletion')->willReturn(new CompletionResponse('ok', 'm', new UsageStatistics(1, 1, 2), 'stop', 'openai'));
        $adapter->method('complete')->willReturn(new CompletionResponse('ok', 'm', new UsageStatistics(1, 1, 2), 'stop', 'openai'));
        $adapter->method('streamChatCompletion')->willReturnCallback(static function (): Generator {
            yield 'ok';
        });

        $registry = self::createStub(ProviderAdapterRegistryInterface::class);
        $registry->method('createAdapterFromModel')->willReturnCallback(function (Model $model) use ($adapter): ProviderInterface {
            $this->servedModels[] = $model->getModelId();

            return $adapter;
        });

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($this->configuration('installation-default'));
        $repository->method('findOneByIdentifier')->willReturnCallback(
            fn (string $identifier): ?LlmConfiguration => $identifier === 'shop-writer' ? $this->configuration('shop-writer') : null,
        );

        $recorder = new class ($this) implements ProviderMiddlewareInterface {
            public function __construct(private readonly LlmServiceManagerSiteTest $test) {}

            public function handle(ProviderCallContext $context, callable $next): mixed
            {
                $this->test->record($context->telemetryConfigurationIdentifier(), $context->metadata);

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
            siteSettings: new SiteLlmSettings(SiteLlmSettingsTest::siteFinder([
                'shop' => ['nrllm.configuration' => 'shop-writer'],
                'blog' => [],
            ])),
        );
    }

    private function configuration(string $identifier): LlmConfiguration
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('getIdentifier')->willReturn($identifier);
        $configuration->method('isActive')->willReturn(true);
        $configuration->method('hasAccessRestrictions')->willReturn(false);
        $model = self::createStub(Model::class);
        $model->method('getModelId')->willReturn($identifier);
        $configuration->method('getLlmModel')->willReturn($model);
        $configuration->method('toOptionsArray')->willReturn([]);
        $configuration->method('getFallbackChainDTO')->willReturn(FallbackChain::fromArray([]));

        return $configuration;
    }
}

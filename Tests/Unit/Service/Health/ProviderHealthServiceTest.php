<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Health;

use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Health\ProviderHealthRepositoryInterface;
use Netresearch\NrLlm\Service\Health\ProviderHealthScore;
use Netresearch\NrLlm\Service\Health\ProviderHealthService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(ProviderHealthService::class)]
#[CoversClass(ProviderHealthScore::class)]
final class ProviderHealthServiceTest extends AbstractUnitTestCase
{
    #[Test]
    public function reorderIsANoOpWhenDisabled(): void
    {
        // Disabled: the chain is returned untouched and the telemetry read is
        // never triggered.
        $repository = $this->createMock(ProviderHealthRepositoryInterface::class);
        $repository->expects(self::never())->method('scoresSince');

        $service = $this->service($repository, [], enabled: false);

        $result = $service->reorder(new FallbackChain(['a', 'b', 'c']));

        self::assertSame(['a', 'b', 'c'], $result->configurationIdentifiers);
    }

    #[Test]
    public function reorderIsANoOpForShortChains(): void
    {
        $repository = $this->createMock(ProviderHealthRepositoryInterface::class);
        $repository->expects(self::never())->method('scoresSince');

        $service = $this->service($repository, [], enabled: true);

        self::assertSame(['only'], $service->reorder(new FallbackChain(['only']))->configurationIdentifiers);
    }

    #[Test]
    public function reordersByDescendingHealthWithStableTieBreak(): void
    {
        $scores = [
            'p-a' => ProviderHealthScore::fromSamples('p-a', 10, 9, 100.0),  // 0.912
            'p-b' => ProviderHealthScore::fromSamples('p-b', 10, 9, 100.0),  // 0.912 (tie with a)
            'p-c' => ProviderHealthScore::fromSamples('p-c', 10, 10, 0.0),   // 1.0
        ];

        $service = $this->service(
            $this->repositoryReturning($scores),
            ['a' => 'p-a', 'b' => 'p-b', 'c' => 'p-c'],
            enabled: true,
        );

        // c (healthiest) first; a and b are tied, so they keep configured order.
        $result = $service->reorder(new FallbackChain(['a', 'b', 'c']));

        self::assertSame(['c', 'a', 'b'], $result->configurationIdentifiers);
    }

    #[Test]
    public function unknownProviderKeepsNeutralPositionNotSunk(): void
    {
        $scores = [
            'p-known' => ProviderHealthScore::fromSamples('p-known', 10, 10, 0.0), // 1.0
            'p-bad'   => ProviderHealthScore::fromSamples('p-bad', 10, 0, 0.0),    // 0.2
        ];

        $service = $this->service(
            $this->repositoryReturning($scores),
            ['known' => 'p-known', 'unknown' => 'p-none', 'bad' => 'p-bad'],
            enabled: true,
        );

        // known (1.0) > unknown (neutral 0.5) > bad (0.2).
        $result = $service->reorder(new FallbackChain(['bad', 'unknown', 'known']));

        self::assertSame(['known', 'unknown', 'bad'], $result->configurationIdentifiers);
    }

    #[Test]
    public function missingConfigurationIsTreatedAsNeutral(): void
    {
        $scores  = ['p-good' => ProviderHealthScore::fromSamples('p-good', 5, 5, 0.0)];
        // 'gone' resolves to no configuration → neutral.
        $service = $this->service(
            $this->repositoryReturning($scores),
            ['good' => 'p-good', 'gone' => null],
            enabled: true,
        );

        $result = $service->reorder(new FallbackChain(['gone', 'good']));

        self::assertSame(['good', 'gone'], $result->configurationIdentifiers);
    }

    #[Test]
    public function scoreForReturnsUnknownWhenProviderHasNoTelemetry(): void
    {
        $service = $this->service($this->repositoryReturning([]), [], enabled: true);

        $score = $service->scoreFor('never-seen');

        self::assertSame(0, $score->sampleCount);
        self::assertSame(ProviderHealthScore::NEUTRAL_SCORE, $score->score);
    }

    #[Test]
    public function scoreForReturnsTheAggregatedScore(): void
    {
        $scores  = ['openai' => ProviderHealthScore::fromSamples('openai', 8, 8, 50.0)];
        $service = $this->service($this->repositoryReturning($scores), [], enabled: true);

        self::assertSame(1.0, $service->scoreFor('openai')->successRate);
    }

    #[Test]
    public function allServesFromCacheWithoutQueryingTelemetry(): void
    {
        $cached = ['openai' => ProviderHealthScore::fromSamples('openai', 3, 3, 10.0)];

        $repository = $this->createMock(ProviderHealthRepositoryInterface::class);
        $repository->expects(self::never())->method('scoresSince');

        $service = new ProviderHealthService(
            $repository,
            self::createStub(LlmConfigurationRepository::class),
            $this->cacheManagerReturning($this->frontendReturning($cached)),
            $this->extensionConfiguration(true),
        );

        self::assertSame($cached, $service->all());
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, ProviderHealthScore> $scores
     */
    private function repositoryReturning(array $scores): ProviderHealthRepositoryInterface
    {
        $repository = self::createStub(ProviderHealthRepositoryInterface::class);
        $repository->method('scoresSince')->willReturn($scores);

        return $repository;
    }

    /**
     * @param array<string, string|null> $identifierToProvider provider adapter
     *                                                         type per configuration identifier;
     *                                                         null = configuration not found
     */
    private function service(
        ProviderHealthRepositoryInterface $repository,
        array $identifierToProvider,
        bool $enabled,
    ): ProviderHealthService {
        return new ProviderHealthService(
            $repository,
            $this->configurationRepository($identifierToProvider),
            $this->cacheManagerReturning($this->frontendMissing()),
            $this->extensionConfiguration($enabled),
        );
    }

    /**
     * @param array<string, string|null> $identifierToProvider
     */
    private function configurationRepository(array $identifierToProvider): LlmConfigurationRepository
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturnCallback(
            function (string $identifier) use ($identifierToProvider): ?LlmConfiguration {
                if (!\array_key_exists($identifier, $identifierToProvider)) {
                    return null;
                }
                $provider = $identifierToProvider[$identifier];
                if ($provider === null) {
                    return null;
                }

                $configuration = $this->createStub(LlmConfiguration::class);
                $configuration->method('getProviderType')->willReturn($provider);

                return $configuration;
            },
        );

        return $repository;
    }

    private function cacheManagerReturning(FrontendInterface $frontend): Typo3CacheManager
    {
        $cacheManager = self::createStub(Typo3CacheManager::class);
        $cacheManager->method('getCache')->willReturn($frontend);

        return $cacheManager;
    }

    private function frontendMissing(): FrontendInterface
    {
        $frontend = self::createStub(FrontendInterface::class);
        $frontend->method('get')->willReturn(false);

        return $frontend;
    }

    private function frontendReturning(mixed $value): FrontendInterface
    {
        $frontend = self::createStub(FrontendInterface::class);
        $frontend->method('get')->willReturn($value);

        return $frontend;
    }

    private function extensionConfiguration(bool $reorderEnabled): ExtensionConfiguration
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([
            'health' => ['reorderFallback' => $reorderEnabled ? '1' : '0'],
        ]);

        return $extensionConfiguration;
    }
}

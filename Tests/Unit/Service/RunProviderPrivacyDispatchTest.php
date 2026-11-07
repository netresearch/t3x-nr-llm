<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Closure;
use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\ModelResolution;
use Netresearch\NrLlm\Exception\RuntimeException;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Privacy\RunProviderPrivacyInterface;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(LlmServiceManager::class)]
final class RunProviderPrivacyDispatchTest extends TestCase
{
    use LlmServiceManagerTestFactory;

    #[Test]
    public function criteriaSelectionChecksActualProviderBeforeChatAndToolDispatch(): void
    {
        foreach ([false, true] as $tools) {
            $seen      = [];
            $model     = $this->model('actual-instance');
            $selection = self::createStub(ModelSelectionServiceInterface::class);
            $selection
                ->method('resolveModelForCall')
                ->willReturn(ModelResolution::withoutDecision($model));
            $adapter = self::createStubForIntersectionOfInterfaces(
                [ProviderInterface::class, ToolCapableInterface::class],
            );
            $adapter
                ->method($tools ? 'chatCompletionWithTools' : 'chatCompletion')
                ->willReturnCallback(
                    static function () use (&$seen): CompletionResponse {
                        $seen[] = 'dispatch';

                        return new CompletionResponse(
                            'OK',
                            'm',
                            new UsageStatistics(1, 1, 2),
                        );
                    },
                );
            $registry = self::createStub(ProviderAdapterRegistryInterface::class);
            $registry->method('createAdapterFromModel')->willReturn($adapter);
            $guard = $this->guard(
                static function (
                    int $uid,
                    string $provider,
                ) use (&$seen): void {
                    $seen[] = [$uid, $provider];
                },
            );
            $manager = $this->manager($registry, $guard, $selection);
            $this->call($manager, $this->configuration(null), $tools);
            self::assertSame([[91, 'actual-instance'], 'dispatch'], $seen);
        }
    }

    #[Test]
    public function bothAttemptPathsRefuseWhenGuardFailsOrIsMissing(): void
    {
        foreach ([false, true] as $tools) {
            foreach ([false, true] as $missing) {
                $registry = self::createMock(ProviderAdapterRegistryInterface::class);
                $registry
                    ->expects(self::never())
                    ->method('createAdapterFromModel');
                $guard = $missing ? null : $this->guard(
                    static function (): never {
                        throw new RuntimeException('privacy persistence failed', 7522869114);
                    },
                );
                $manager = $this->manager($registry, $guard);
                try {
                    $this->call(
                        $manager,
                        $this->configuration($this->model('actual')),
                        $tools,
                    );
                    self::fail('No provider may run without a durable policy.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString(
                        $missing ? 'not wired' : 'persistence failed',
                        $exception->getMessage(),
                    );
                }
            }
        }
    }

    #[Test]
    public function everyFailedAndFallbackAttemptPassesItsActualIdentityToTheGuard(): void
    {
        foreach ([false, true] as $tools) {
            $seen     = [];
            $first    = $this->configuration($this->model('first'), 'primary', ['fallback']);
            $fallback = $this->configuration($this->model('second'), 'fallback');
            $configs  = self::createStub(LlmConfigurationRepository::class);
            $configs->method('findOneByIdentifier')->willReturn($fallback);
            $health = self::createStub(ProviderHealthServiceInterface::class);
            $health
                ->method('reorder')
                ->willReturnCallback(static fn (FallbackChain $chain): FallbackChain => $chain);
            $pipeline = new MiddlewarePipeline(
                [
                    new FallbackMiddleware(
                        new FallbackCandidateResolver($configs),
                        new NullLogger(),
                        $health,
                    ),
                ],
            );
            $registry = self::createStub(ProviderAdapterRegistryInterface::class);
            $registry
                ->method('createAdapterFromModel')
                ->willReturnCallback(
                    function (
                        Model $model,
                    ) use ($tools, &$seen): ProviderInterface {
                        $id      = $model->getProvider()?->getIdentifier();
                        $adapter = self::createStubForIntersectionOfInterfaces(
                            [
                                ProviderInterface::class,
                                ToolCapableInterface::class,
                            ],
                        );
                        $adapter->method(
                            $tools ? 'chatCompletionWithTools' : 'chatCompletion',
                        )->willReturnCallback(
                            static function () use ($id, &$seen): CompletionResponse {
                                $seen[] = 'dispatch:' . $id;
                                if ($id === 'first') {
                                    throw new ProviderConnectionException('retry', 6814375074);
                                }

                                return new CompletionResponse(
                                    'OK',
                                    'm',
                                    new UsageStatistics(1, 1, 2),
                                );
                            },
                        );

                        return $adapter;
                    },
                );
            $guard = $this->guard(
                static function (int $uid, string $id) use (&$seen): void {
                    $seen[] = 'policy:' . $id;
                },
            );
            $this->call(
                $this->manager($registry, $guard, pipeline: $pipeline),
                $first,
                $tools,
            );
            self::assertSame(
                [
                    'policy:first',
                    'dispatch:first',
                    'policy:second',
                    'dispatch:second',
                ],
                $seen,
            );
        }
    }

    private function call(
        LlmServiceManager $manager,
        LlmConfiguration $configuration,
        bool $tools,
    ): void {
        $run = new AgentRunReference(91, 'run-uuid');
        if ($tools) {
            $manager->chatWithToolsForConfiguration(
                [['role' => 'user', 'content' => 'Hello']],
                [],
                $configuration,
                run: $run,
            );

            return;
        }

        $manager->chatWithConfiguration(
            [['role' => 'user', 'content' => 'Hello']],
            $configuration,
            run: $run,
        );
    }

    private function model(string $identifier): Model
    {
        $provider = new Provider();
        $provider->setIdentifier($identifier);
        $provider->setAdapterType('openai');

        $model = new Model();
        $model->setProvider($provider);
        $model->setModelId('m');

        return $model;
    }

    /**
     * @param list<string> $fallback
     */
    private function configuration(
        ?Model $model,
        string $identifier = 'criteria',
        array $fallback = [],
    ): LlmConfiguration {
        $config = self::createStub(LlmConfiguration::class);
        $config->method('getIdentifier')->willReturn($identifier);
        $config->method('getLlmModel')->willReturn($model);
        $config->method('toOptionsArray')->willReturn([]);
        $config
            ->method('getFallbackChainDTO')
            ->willReturn(new FallbackChain($fallback));
        $config->method('isActive')->willReturn(true);

        return $config;
    }

    /**
     * @param Closure(int, string): void $callback
     */
    private function guard(Closure $callback): RunProviderPrivacyInterface
    {
        return new class ($callback) implements RunProviderPrivacyInterface {
            /**
             * @param Closure(int, string): void $callback
             */
            public function __construct(private readonly Closure $callback) {}

            public function beforeProvider(
                int $runUid,
                string $providerIdentifier,
            ): void {
                ($this->callback)($runUid, $providerIdentifier);
            }
        };
    }

    private function manager(
        ProviderAdapterRegistryInterface $registry,
        ?RunProviderPrivacyInterface $guard,
        ?ModelSelectionServiceInterface $selection = null,
        ?MiddlewarePipeline $pipeline = null,
    ): LlmServiceManager {
        $config = self::createStub(ExtensionConfiguration::class);
        $config->method('get')->willReturn([]);

        return $this->createLlmServiceManager(
            $config,
            new NullLogger(),
            $registry,
            $pipeline ?? new MiddlewarePipeline([]),
            self::createStub(CacheManagerInterface::class),
            modelSelectionService: $selection,
            runPrivacy: $guard,
        );
    }
}

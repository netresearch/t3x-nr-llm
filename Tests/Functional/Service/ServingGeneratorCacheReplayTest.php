<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\IdempotencyMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\ServingGeneratorMetadata;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\CacheManager as Typo3CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(LlmServiceManager::class)]
#[CoversClass(ServingGeneratorMetadata::class)]
#[CoversClass(IdempotencyMiddleware::class)]
#[CoversClass(GuardrailMiddleware::class)]
final class ServingGeneratorCacheReplayTest extends AbstractFunctionalTestCase
{
    use LlmServiceManagerTestFactory;

    private const KEY = 'nr_llm_serving_generator';

    private function configuration(
        string $providerId,
        string $alias,
    ): LlmConfiguration {
        $provider = new Provider();
        $provider->setIdentifier($providerId);
        $provider->setAdapterType('openai');
        $provider->setIsActive(true);

        $model = new Model();
        $model->setProvider($provider);
        $model->setModelId($alias);
        $model->setIsActive(true);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('evaluation');
        $configuration->setIsActive(true);
        $configuration->setLlmModel($model);
        return $configuration;
    }

    private function manager(
        ProviderAdapterRegistryInterface $registry,
        ?MiddlewarePipeline $pipeline = null,
    ): LlmServiceManager {
        $extension = self::createStub(ExtensionConfiguration::class);
        $extension->method('get')->willReturn(['providers' => []]);
        return $this->createLlmServiceManager(
            $extension,
            new NullLogger(),
            $registry,
            $pipeline ?? new MiddlewarePipeline([]),
            self::createStub(CacheManagerInterface::class),
        );
    }

    #[Test]
    public function realCacheReplayPreservesTheScreenedServingIdentityAcrossConfigurationChanges(): void
    {
        $snapshot = [];
        foreach (['TYPO3_CONF_VARS', 'EXEC_TIME'] as $key) {
            $snapshot[$key] = [
                'present' => array_key_exists($key, $GLOBALS),
                'value' => $GLOBALS[$key] ?? null,
            ];
        }

        $directory = sys_get_temp_dir() . '/nrllm-generator-cache-' . bin2hex(random_bytes(8));
        try {
            $config = is_array($snapshot['TYPO3_CONF_VARS']['value']) ? $snapshot['TYPO3_CONF_VARS']['value'] : [];
            $sys = is_array($config['SYS'] ?? null) ? $config['SYS'] : [];
            $sys['encryptionKey'] = 'generator-test-only';
            $config['SYS'] = $sys;
            // FileBackend consumes these globals; install the fixture state as one map.
            foreach (['TYPO3_CONF_VARS' => $config, 'EXEC_TIME' => time()] as $key => $value) {
                $GLOBALS[$key] = $value;
            }

            $response = new CompletionResponse(
                'private response',
                'served-snapshot',
                new UsageStatistics(2, 3, 5),
                provider: 'openai',
                metadata: ['keep' => 'adapter-value'],
                thinking: 'private thinking',
            );
            $adapter = $this->createMock(ProviderInterface::class);
            $adapter
                ->expects(self::once())
                ->method('chatCompletion')
                ->willReturn($response);
            $registry = $this->createMock(ProviderAdapterRegistryInterface::class);
            $registry
                ->expects(self::once())
                ->method('createAdapterFromModel')
                ->willReturn($adapter);
            $cacheManager = new Typo3CacheManager();
            $cacheManager->setCacheConfigurations(
                [
                    'nrllm_idempotency' => [
                        'frontend' => VariableFrontend::class,
                        'backend' => FileBackend::class,
                        'options' => ['cacheDirectory' => $directory],
                    ],
                ],
            );
            self::assertInstanceOf(
                VariableFrontend::class,
                $cacheManager->getCache('nrllm_idempotency'),
            );
            self::assertDirectoryExists($directory);
            $guardrail = $this->createMock(GuardrailInterface::class);
            $guardrail->method('getIdentifier')->willReturn('generator-fixture');
            $guardrail->method('isMandatory')->willReturn(false);
            $guardrail
                ->expects(self::once())
                ->method('checkOutput')
                ->willReturn(
                    GuardrailResult::redact(
                        '[redacted]',
                        'private',
                        '[thinking-redacted]',
                    ),
                );
            $manager = $this->manager(
                $registry,
                new MiddlewarePipeline(
                    [
                        new IdempotencyMiddleware($cacheManager),
                        new GuardrailMiddleware([$guardrail]),
                    ],
                ),
            );
            $messages = [['role' => 'user', 'content' => 'Question']];
            $metadata = [IdempotencyMiddleware::METADATA_IDEMPOTENCY_KEY => 'same-request'];
            $first = $manager->chatWithConfiguration(
                $messages,
                $this->configuration('original-instance', 'served-alias'),
                $metadata,
            );
            $replayed = $manager->chatWithConfiguration(
                $messages,
                $this->configuration('changed-instance', 'new-alias'),
                $metadata,
            );
            self::assertSame('[redacted]', $first->content);
            self::assertSame('[thinking-redacted]', $replayed->thinking);
            self::assertSame($first->metadata, $replayed->metadata);
            self::assertSame(
                [
                    'version' => 1,
                    'providerIdentifier' => 'original-instance',
                    'modelId' => 'served-alias',
                    'reportedModelId' => 'served-snapshot',
                ],
                $replayed->metadata[self::KEY] ?? null,
            );
            self::assertSame(
                'adapter-value',
                $replayed->metadata['keep'] ?? null,
            );
            self::assertSame('openai', $replayed->provider);
            self::assertSame('served-snapshot', $replayed->model);
            self::assertEquals($response->usage, $replayed->usage);
            self::assertNotSame(
                $first,
                $replayed,
                'VariableFrontend must actually serialize and reconstruct the result.',
            );
        } finally {
            if (is_dir($directory)) {
                GeneralUtility::rmdir($directory, true);
            }

            foreach ($snapshot as $key => $previous) {
                if ($previous['present']) {
                    $GLOBALS[$key] = $previous['value'];
                } else {
                    unset($GLOBALS[$key]);
                }
            }
        }

        self::assertDirectoryDoesNotExist($directory);
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests;

use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\EmbedCacheKeyBuilder;
use Netresearch\NrLlm\Service\KeyedProviderRegistry;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\MessageShaper;
use Netresearch\NrLlm\Service\ModelSelectionServiceInterface;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Builds a {@see LlmServiceManager} from the leaf collaborators the tests used
 * to pass directly to its constructor.
 *
 * Stage 1 of the manager decomposition (ADR-059) moved provider registration,
 * default-configuration resolution, message shaping and embed cache-key
 * construction into dedicated collaborators, changing the manager's
 * constructor. This factory keeps the previous test call shape — extension
 * configuration, logger, adapter registry, pipeline, cache manager, optional
 * configuration repository, skill injection, model selection and streaming
 * dispatcher, in that order — and wires the new collaborators internally, so the
 * existing constructions did not each have to spell them out. A null streaming
 * dispatcher (the default) keeps the manager on the legacy raw-generator
 * streaming path, so tests that do not exercise the lifecycle are unaffected.
 * Production wiring is autowired via Services.yaml.
 */
trait LlmServiceManagerTestFactory
{
    protected function createLlmServiceManager(
        ExtensionConfiguration $extensionConfiguration,
        LoggerInterface $logger,
        ProviderAdapterRegistryInterface $adapterRegistry,
        MiddlewarePipeline $pipeline,
        CacheManagerInterface $cacheManager,
        ?LlmConfigurationRepository $configurationRepository = null,
        ?SkillInjectionService $skillInjection = null,
        ?ModelSelectionServiceInterface $modelSelectionService = null,
        ?StreamingDispatcher $streaming = null,
    ): LlmServiceManager {
        return new LlmServiceManager(
            $adapterRegistry,
            $pipeline,
            new KeyedProviderRegistry($extensionConfiguration, $logger),
            new ConfigurationResolver($configurationRepository),
            new MessageShaper(),
            new EmbedCacheKeyBuilder($cacheManager),
            $skillInjection,
            $modelSelectionService,
            $streaming,
        );
    }
}

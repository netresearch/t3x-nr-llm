<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Architecture;

use Netresearch\NrLlm\Provider\ClaudeProvider;
use Netresearch\NrLlm\Provider\GeminiProvider;
use Netresearch\NrLlm\Provider\GroqProvider;
use Netresearch\NrLlm\Provider\MistralProvider;
use Netresearch\NrLlm\Provider\OllamaProvider;
use Netresearch\NrLlm\Provider\OpenAiProvider;
use Netresearch\NrLlm\Provider\OpenRouterProvider;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architectural tests for Controller layer dependencies.
 *
 * These tests enforce that controllers follow TYPO3/Extbase patterns
 * and maintain proper separation of concerns.
 */
final class ControllerLayerTest
{
    /**
     * Backend controllers should not directly depend on provider implementations.
     *
     * Controllers should use the ProviderAdapterRegistry or LlmServiceManager
     * to get provider instances, not instantiate them directly.
     */
    public function testBackendControllersUseRegistryNotProviders(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrLlm\Controller\Backend'))
            ->shouldNotDependOn()
            ->classes(
                Selector::classname(OpenAiProvider::class),
                Selector::classname(ClaudeProvider::class),
                Selector::classname(GeminiProvider::class),
                Selector::classname(OllamaProvider::class),
                Selector::classname(GroqProvider::class),
                Selector::classname(MistralProvider::class),
                Selector::classname(OpenRouterProvider::class),
            )
            ->because('Controllers should use ProviderAdapterRegistry, not concrete provider classes.');
    }

    // Note: PHPat 0.12 doesn't have a shouldDependOn() method.
    // We rely on code review and the DTO/Factory pattern documentation
    // to encourage proper form data handling via DTOs.
    // The architectural constraint is enforced through:
    // 1. Domain models not depending on repositories (testDomainModelsDoNotDependOnRepositories)
    // 2. Factories being the designated location for repository usage
}

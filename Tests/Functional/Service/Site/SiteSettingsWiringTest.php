<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Site;

use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\LlmServiceManager;
use Netresearch\NrLlm\Service\Site\SiteLlmSettings;
use Netresearch\NrLlm\Specialized\Translation\DeepLGlossarySync;
use Netresearch\NrLlm\Specialized\Translation\DeepLTranslator;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\CMS\Core\Site\Set\SetRegistry;

/**
 * The site settings are read in production, not only in unit tests (ADR-244):
 * the container hands the settings reader to the services that use it, and
 * core accepts the set that defines the settings.
 */
#[CoversClass(SiteLlmSettings::class)]
final class SiteSettingsWiringTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function theContainerWiresTheSiteSettingsIntoTheResolverAndTheDeepLTranslator(): void
    {
        $manager  = $this->get(LlmServiceManager::class);
        $resolver = (new ReflectionProperty(LlmServiceManager::class, 'configurationResolver'))->getValue($manager);
        self::assertInstanceOf(ConfigurationResolver::class, $resolver);
        self::assertInstanceOf(
            SiteLlmSettings::class,
            (new ReflectionProperty(ConfigurationResolver::class, 'siteSettings'))->getValue($resolver),
        );

        // The registry hands out only an available translator, and this
        // installation has no DeepL key; the glossary sync holds the same one.
        $sync  = $this->get(DeepLGlossarySync::class);
        $deepl = (new ReflectionProperty(DeepLGlossarySync::class, 'translator'))->getValue($sync);
        self::assertInstanceOf(DeepLTranslator::class, $deepl);
        self::assertInstanceOf(
            SiteLlmSettings::class,
            (new ReflectionProperty(DeepLTranslator::class, 'siteSettings'))->getValue($deepl),
        );
    }

    #[Test]
    public function coreAcceptsTheSetThatDefinesTheSettings(): void
    {
        $registry = $this->get(SetRegistry::class);

        self::assertTrue($registry->hasSet('netresearch/nr-llm'), 'invalid sets: ' . json_encode(array_keys($registry->getInvalidSets())));
        $set = $registry->getSet('netresearch/nr-llm');
        self::assertNotNull($set);

        $keys = array_map(static fn ($definition): string => $definition->key, $set->settingsDefinitions);
        self::assertContains(SiteLlmSettings::CONFIGURATION, $keys);
        self::assertContains(SiteLlmSettings::DEEPL_API_KEY_IDENTIFIER, $keys);
    }
}

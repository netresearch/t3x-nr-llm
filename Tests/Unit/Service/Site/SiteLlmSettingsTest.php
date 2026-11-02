<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Site;

use Netresearch\NrLlm\Service\Site\SiteLlmSettings;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteSettings;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * What a site's settings say about its AI calls (ADR-244): identifiers only,
 * empty for a site that names none and for a site that does not exist.
 */
#[CoversClass(SiteLlmSettings::class)]
final class SiteLlmSettingsTest extends AbstractUnitTestCase
{
    #[Test]
    public function aSiteNamesItsConfigurationAndItsDeepLKey(): void
    {
        $subject = new SiteLlmSettings(self::siteFinder([
            'shop' => [
                'nrllm.configuration'          => ' shop-writer ',
                'nrllm.deepl.apiKeyIdentifier' => 'shop-deepl-key',
            ],
        ]));

        self::assertSame('shop-writer', $subject->configurationIdentifier('shop'));
        self::assertSame('shop-deepl-key', $subject->deeplApiKeyIdentifier('shop'));
    }

    #[Test]
    public function aSiteWithoutTheSettingsNamesNone(): void
    {
        $subject = new SiteLlmSettings(self::siteFinder(['blog' => []]));

        self::assertSame('', $subject->configurationIdentifier('blog'));
        self::assertSame('', $subject->deeplApiKeyIdentifier('blog'));
    }

    #[Test]
    public function anUnknownSiteAndNoSiteNameNone(): void
    {
        $subject = new SiteLlmSettings(self::siteFinder(['shop' => ['nrllm.configuration' => 'shop-writer']]));

        self::assertSame('', $subject->configurationIdentifier('gone'));
        self::assertSame('', $subject->configurationIdentifier(''));
        self::assertSame('', $subject->configurationIdentifier(null));
    }

    #[Test]
    public function aValueThatIsNotTextNamesNone(): void
    {
        $subject = new SiteLlmSettings(self::siteFinder(['shop' => ['nrllm.configuration' => 42]]));

        self::assertSame('', $subject->configurationIdentifier('shop'));
    }

    /**
     * A SiteFinder that knows the given sites with the given flat settings.
     *
     * @param array<string, array<string, mixed>> $sites
     */
    public static function siteFinder(array $sites): SiteFinder
    {
        $finder = self::createStub(SiteFinder::class);
        $finder->method('getSiteByIdentifier')->willReturnCallback(
            static function (string $identifier) use ($sites): Site {
                if (!array_key_exists($identifier, $sites)) {
                    throw new SiteNotFoundException('No site ' . $identifier, 1784300001);
                }

                return new Site($identifier, 1, [], SiteSettings::create(new Settings($sites[$identifier])));
            },
        );

        return $finder;
    }
}

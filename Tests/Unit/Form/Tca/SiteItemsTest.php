<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Form\Tca;

use Netresearch\NrLlm\Form\Tca\SiteItems;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

#[CoversClass(SiteItems::class)]
final class SiteItemsTest extends TestCase
{
    #[Test]
    public function listsEveryConfiguredSiteByItsIdentifier(): void
    {
        $params = ['items' => [['label' => '', 'value' => '']], 'row' => []];

        $this->subject(['main' => 'https://www.example.org/', 'shop' => 'https://shop.example.org/'])->addItems($params);

        self::assertSame(
            [
                ['label' => '', 'value' => ''],
                ['label' => 'main (https://www.example.org/)', 'value' => 'main'],
                ['label' => 'shop (https://shop.example.org/)', 'value' => 'shop'],
            ],
            $params['items'],
        );
    }

    #[Test]
    public function keepsAStoredSiteThatNoLongerExistsSelectable(): void
    {
        $params = ['items' => [], 'row' => ['site_identifier' => ['retired']]];

        $this->subject(['main' => 'https://www.example.org/'])->addItems($params);

        self::assertSame(
            [
                ['label' => 'main (https://www.example.org/)', 'value' => 'main'],
                ['label' => 'retired', 'value' => 'retired'],
            ],
            $params['items'],
        );
    }

    #[Test]
    public function doesNotRepeatAStoredSiteThatExists(): void
    {
        $params = ['items' => [], 'row' => ['site_identifier' => 'main']];

        $this->subject(['main' => 'https://www.example.org/'])->addItems($params);

        self::assertCount(1, $params['items']);
    }

    /**
     * @param array<string, string> $sites identifier => base
     */
    private function subject(array $sites): SiteItems
    {
        $siteObjects = [];
        foreach ($sites as $identifier => $base) {
            $site = self::createStub(Site::class);
            $site->method('getIdentifier')->willReturn($identifier);
            $site->method('getBase')->willReturn(new Uri($base));
            $siteObjects[$identifier] = $site;
        }

        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($siteObjects);

        return new SiteItems($siteFinder);
    }
}

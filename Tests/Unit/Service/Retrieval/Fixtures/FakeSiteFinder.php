<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Retrieval\Fixtures;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * SiteFinder double (the real one is readonly and cannot be doubled by
 * PHPUnit): serves a fixed site list without configuration storage.
 */
final readonly class FakeSiteFinder extends SiteFinder
{
    /**
     * @param list<Site> $sites
     */
    public function __construct(
        private array $sites,
    ) {
        // Deliberately no parent constructor: every consumed method is overridden.
    }

    public function getAllSites(bool $useCache = true): array
    {
        $result = [];
        foreach ($this->sites as $site) {
            $result[$site->getIdentifier()] = $site;
        }

        return $result;
    }

    public function getSiteByIdentifier(string $identifier): Site
    {
        foreach ($this->sites as $site) {
            if ($site->getIdentifier() === $identifier) {
                return $site;
            }
        }

        throw new SiteNotFoundException('No site found for identifier ' . $identifier, 1751000001);
    }
}

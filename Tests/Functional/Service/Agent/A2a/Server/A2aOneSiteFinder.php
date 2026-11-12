<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * A site finder that knows one site, built in the test.
 */
final readonly class A2aOneSiteFinder extends SiteFinder
{
    public function __construct(
        private Site $site,
    ) {}

    public function getAllSites(bool $useCache = true): array
    {
        return [$this->site->getIdentifier() => $this->site];
    }
}

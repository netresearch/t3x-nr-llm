<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Form\Tca;

use Netresearch\NrLlm\Form\Tca\SiteItems;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The site select of tx_nrllm_glossary the way FormEngine reaches it:
 * GeneralUtility::callUserFunction() instantiates the item provider without
 * constructor arguments, and the provider fetches the SiteFinder with
 * makeInstance() from the real container (ADR-208).
 */
#[CoversClass(SiteItems::class)]
final class SiteItemsTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function formEnginesCallPathResolvesTheSiteFinderFromTheContainer(): void
    {
        $params = ['items' => [['label' => '', 'value' => '']], 'row' => ['site_identifier' => 'retired']];

        GeneralUtility::callUserFunction(SiteItems::class . '->addItems', $params);

        // The test instance configures no site, so only the empty item and the
        // stored value remain — reached without a fatal on the way.
        self::assertIsArray($params);
        self::assertSame(
            [['label' => '', 'value' => ''], ['label' => 'retired', 'value' => 'retired']],
            $params['items'] ?? null,
        );
    }
}

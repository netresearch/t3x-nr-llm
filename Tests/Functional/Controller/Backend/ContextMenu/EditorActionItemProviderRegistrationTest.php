<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend\ContextMenu;

use Netresearch\NrLlm\Controller\Backend\ContextMenu\EditorActionItemProvider;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\ItemProvidersRegistry;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The Editor Action Center's entry point is actually wired (ADR-158).
 *
 * The provider reaches the context menu through autoconfiguration — nothing in
 * this repository names the `backend.contextmenu.itemprovider` tag — so a class
 * that no longer implements the interface, or a container that stops applying
 * the tag, would remove the ONLY per-record way in without a single test
 * failing. Asking the real registry is the check that notices.
 */
#[CoversClass(EditorActionItemProvider::class)]
final class EditorActionItemProviderRegistrationTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theProviderIsRegisteredWithTheContextMenu(): void
    {
        $this->importFixture('BeUsers.csv');
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $registry = $this->get(ItemProvidersRegistry::class);
        self::assertInstanceOf(ItemProvidersRegistry::class, $registry);

        $classes = array_map(static fn(object $p): string => $p::class, $registry->getItemProviders());

        self::assertContains(EditorActionItemProvider::class, $classes);
    }
}

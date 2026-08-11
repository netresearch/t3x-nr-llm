<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend\ContextMenu;

use Netresearch\NrLlm\Controller\Backend\ContextMenu\EditorActionItemProvider;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Domain\ValueObject\EditorActionOfferGroup;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogueInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Whether the one context-menu item appears (ADR-158).
 *
 * Every case is about NOT offering something, because that is where the harm
 * is: an item on a record no action addresses is noise, an item for a user who
 * may run nothing leads to a 403, and an item built from a FAL combined
 * identifier would carry a plausible wrong uid.
 */
#[CoversClass(EditorActionItemProvider::class)]
final class EditorActionItemProviderTest extends AbstractUnitTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function handlesARecordWithAtLeastOneOfferedAction(): void
    {
        $provider = $this->provider($this->grantedUser(), offers: true);
        $provider->setContext('pages', '42');

        self::assertTrue($provider->canHandle());
    }

    #[Test]
    public function refusesARecordNoActionAddresses(): void
    {
        $provider = $this->provider($this->grantedUser(), offers: false);
        $provider->setContext('sys_news', '42');

        self::assertFalse($provider->canHandle());
    }

    /**
     * The file list hands the context menu `1:/path/file.jpg`, and
     * `set_file_alternative_text` takes a `sys_file` uid. `(int)` on that string
     * yields the storage uid — a number that exists and is wrong.
     */
    #[Test]
    public function refusesAFalCombinedIdentifier(): void
    {
        $provider = $this->provider($this->grantedUser(), offers: true);
        $provider->setContext('sys_file', '1:/user_upload/photo.jpg');

        self::assertFalse($provider->canHandle());
    }

    #[Test]
    public function refusesABackendUserWithoutTheGrant(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        // Signed in, so what the refusal proves is the missing grant and not a
        // missing session.
        $user->user = ['uid' => 3];
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->willReturn(false);

        $provider = $this->provider($user, offers: true);
        $provider->setContext('pages', '42');

        self::assertFalse($provider->canHandle());
    }

    #[Test]
    public function refusesAnIdentifierThatIsNotARecord(): void
    {
        $provider = $this->provider($this->grantedUser(), offers: true);
        $provider->setContext('pages', '0');

        self::assertFalse($provider->canHandle());
    }

    /**
     * The menu is TYPO3's, not this extension's: a catalogue that throws must
     * cost this one item, never every item on every right-click.
     */
    #[Test]
    public function refusesRatherThanBreakingTheMenuWhenTheCatalogueThrows(): void
    {
        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $this->grantedUser();

        $catalogue = $this->createMock(EditorActionCatalogueInterface::class);
        $catalogue->method('groupsFor')->willThrowException(new RuntimeException('no container here'));

        $provider = new EditorActionItemProvider($catalogue, self::createStub(BackendUriBuilder::class));
        $provider->setContext('pages', '42');

        self::assertFalse($provider->canHandle());
    }

    private function grantedUser(): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        // The provider asks the actor built from this user, and an actor without
        // a uid is anonymous — so the session row has to be there.
        $user->user = ['uid' => 3];
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->willReturnCallback(
            static fn(string $type, string $value): bool => $type === 'custom_options'
                && $value === BackendUserGrant::TASKS_USE->permissionValue(),
        );

        return $user;
    }

    private function provider(BackendUserAuthentication $user, bool $offers): EditorActionItemProvider
    {
        // AbstractProvider's constructor reads both globals, so they must exist
        // before the subject is built.
        $GLOBALS['LANG']    = $this->createMock(LanguageService::class);
        $GLOBALS['BE_USER'] = $user;

        $catalogue = $this->createMock(EditorActionCatalogueInterface::class);
        $catalogue->method('groupsFor')->willReturn(
            $offers ? [new EditorActionOfferGroup('editing', null, [])] : [],
        );

        return new EditorActionItemProvider($catalogue, self::createStub(BackendUriBuilder::class));
    }
}

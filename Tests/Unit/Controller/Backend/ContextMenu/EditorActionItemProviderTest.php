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
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * Whether the one context-menu item appears (ADR-158).
 *
 * Every case is about NOT offering something, because that is where the harm
 * is: an item on a record no action addresses is noise, an item for a user who
 * may run nothing leads to a 403, and an item built from a FAL combined
 * identifier by casting it would carry a plausible wrong uid — so the file-list
 * cases assert the uid the identifier resolves to (ADR-267).
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
     * The file list hands the context menu `1:/path/file.jpg`, and the actions
     * on `sys_file` take a `sys_file` uid (ADR-267). `(int)` on that string
     * yields the storage uid — a number that exists and is wrong — so the link
     * must carry the uid the identifier resolves to.
     */
    #[Test]
    public function linksAFileFromTheFileListByItsSysFileUid(): void
    {
        $arguments = [];
        $provider  = $this->provider($this->adminUser(), offers: true, resource: $this->file(1, 77), uriArguments: $arguments);
        $provider->setContext('sys_file', '1:/user_upload/photo.jpg');

        self::assertTrue($provider->canHandle());
        $this->renderAttributes($provider);

        self::assertSame('sys_file', $arguments['recordTable'] ?? null);
        self::assertSame(77, $arguments['recordUid'] ?? null);
        self::assertArrayNotHasKey('folder', $arguments);
    }

    /**
     * A folder becomes the scope of a batch (ADR-236), not a record.
     */
    #[Test]
    public function linksAFolderFromTheFileListAsAFolderScope(): void
    {
        $arguments = [];
        $provider  = $this->provider($this->adminUser(), offers: true, resource: $this->folder(1, '/user_upload/'), uriArguments: $arguments);
        $provider->setContext('sys_file', '1:/user_upload/');

        self::assertTrue($provider->canHandle());
        $this->renderAttributes($provider);

        self::assertSame('sys_file', $arguments['recordTable'] ?? null);
        self::assertSame('1:/user_upload/', $arguments['folder'] ?? null);
        self::assertArrayNotHasKey('recordUid', $arguments);
    }

    /**
     * The storage gate decides, not the file list: storage 2 is outside the
     * gate's allow-list, so neither its files nor its folders get an item.
     */
    #[Test]
    public function refusesAFileOutsideThePermittedStorages(): void
    {
        $provider = $this->provider($this->adminUser(), offers: true, resource: $this->file(2, 77));
        $provider->setContext('sys_file', '2:/user_upload/photo.jpg');

        self::assertFalse($provider->canHandle());
    }

    #[Test]
    public function refusesAFolderOutsideThePermittedStorages(): void
    {
        $provider = $this->provider($this->adminUser(), offers: true, resource: $this->folder(2, '/user_upload/'));
        $provider->setContext('sys_file', '2:/user_upload/');

        self::assertFalse($provider->canHandle());
    }

    /**
     * A file that is gone, a storage that is gone and a malformed identifier
     * all throw in core, and all mean: no item. Never a broken menu.
     */
    #[Test]
    public function refusesAnIdentifierThatResolvesToNothing(): void
    {
        $provider = $this->provider($this->adminUser(), offers: true, resource: new RuntimeException('no such file'));
        $provider->setContext('sys_file', '1:/user_upload/gone.jpg');

        self::assertFalse($provider->canHandle());
    }

    /**
     * Only the file list sends a combined identifier, and only for `sys_file`.
     */
    #[Test]
    public function refusesANonIntegerIdentifierOnAnotherTable(): void
    {
        $provider = $this->provider($this->adminUser(), offers: true, resource: $this->file(1, 77));
        $provider->setContext('pages', '1:/user_upload/photo.jpg');

        self::assertFalse($provider->canHandle());
    }

    /**
     * A right-click by somebody who may run nothing never reaches the storage.
     */
    #[Test]
    public function resolvesNoFileForAUserWithoutTheGrant(): void
    {
        $user       = $this->createMock(BackendUserAuthentication::class);
        $user->user = ['uid' => 3];
        $user->method('isAdmin')->willReturn(false);
        $user->method('check')->willReturn(false);

        $GLOBALS['LANG']    = self::createStub(LanguageService::class);
        $GLOBALS['BE_USER'] = $user;

        $resourceFactory = $this->createMock(ResourceFactory::class);
        $resourceFactory->expects(self::never())->method('retrieveFileOrFolderObject');

        $provider = new EditorActionItemProvider(
            self::createStub(EditorActionCatalogueInterface::class),
            self::createStub(BackendUriBuilder::class),
            $this->moduleProvider(true),
            $resourceFactory,
            new FalStorageGate([1]),
        );
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

    /**
     * The grant and the module tick are separate axes: `nrllm_aitasks` is
     * registered `access: 'user'`, so holding `tasks_use` does not put the
     * module in a group's list. The item links into that module.
     */
    #[Test]
    public function refusesABackendUserWhoCannotOpenTheModule(): void
    {
        $provider = $this->provider($this->grantedUser(), offers: true, moduleAccess: false);
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

        $provider = new EditorActionItemProvider(
            $catalogue,
            self::createStub(BackendUriBuilder::class),
            $this->moduleProvider(true),
            self::createStub(ResourceFactory::class),
            new FalStorageGate([1]),
        );
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
            static fn (string $type, string $value): bool => $type === 'custom_options'
                && $value === BackendUserGrant::TASKS_USE->permissionValue(),
        );

        return $user;
    }

    private function adminUser(): BackendUserAuthentication
    {
        $user       = self::createStub(BackendUserAuthentication::class);
        $user->user = ['uid' => 1];
        // An administrator holds every grant and passes the storage gate for
        // every permitted storage, so what the file cases measure is the
        // resolution and the allow-list, not a mount.
        $user->method('isAdmin')->willReturn(true);

        return $user;
    }

    /**
     * @param File|Folder|RuntimeException|null $resource     what the identifier resolves to; an exception is thrown
     * @param array<string, mixed>              $uriArguments receives the arguments the catalogue link is built from
     */
    private function provider(
        BackendUserAuthentication $user,
        bool $offers,
        bool $moduleAccess = true,
        File|Folder|RuntimeException|null $resource = null,
        array &$uriArguments = [],
    ): EditorActionItemProvider {
        // AbstractProvider's constructor reads both globals, so they must exist
        // before the subject is built.
        $GLOBALS['LANG']    = $this->createMock(LanguageService::class);
        $GLOBALS['BE_USER'] = $user;

        $catalogue = $this->createMock(EditorActionCatalogueInterface::class);
        $catalogue->method('groupsFor')->willReturn(
            $offers ? [new EditorActionOfferGroup('editing', null, [])] : [],
        );

        $resourceFactory = self::createStub(ResourceFactory::class);
        if ($resource instanceof RuntimeException) {
            $resourceFactory->method('retrieveFileOrFolderObject')->willThrowException($resource);
        } else {
            $resourceFactory->method('retrieveFileOrFolderObject')->willReturn($resource);
        }

        $uriBuilder = self::createStub(BackendUriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static function (string $route, array $parameters = []) use (&$uriArguments): Uri {
                $uriArguments = $parameters;

                return new Uri('/typo3/module/' . $route);
            },
        );

        return new EditorActionItemProvider(
            $catalogue,
            $uriBuilder,
            $this->moduleProvider($moduleAccess),
            $resourceFactory,
            new FalStorageGate([1]),
        );
    }

    private function file(int $storageUid, int $uid): File
    {
        $file = self::createStub(File::class);
        $file->method('getUid')->willReturn($uid);
        $file->method('getIdentifier')->willReturn('/user_upload/photo.jpg');
        $file->method('getStorage')->willReturn($this->storage($storageUid));

        return $file;
    }

    private function folder(int $storageUid, string $identifier): Folder
    {
        $folder = self::createStub(Folder::class);
        $folder->method('getIdentifier')->willReturn($identifier);
        $folder->method('getCombinedIdentifier')->willReturn($storageUid . ':' . $identifier);
        $folder->method('getStorage')->willReturn($this->storage($storageUid));

        return $folder;
    }

    private function storage(int $uid): ResourceStorage
    {
        $storage = self::createStub(ResourceStorage::class);
        $storage->method('getUid')->willReturn($uid);

        return $storage;
    }

    /**
     * The attributes the rendered item carries. Asked directly rather than
     * through addItems(), whose icon rendering needs a container.
     *
     * @return array<string, string>
     */
    private function renderAttributes(EditorActionItemProvider $provider): array
    {
        $method     = new ReflectionMethod($provider, 'getAdditionalAttributes');
        $attributes = $method->invoke($provider, 'nrllmEditorActions');
        self::assertIsArray($attributes);
        self::assertArrayHasKey('data-catalogue-url', $attributes);

        /** @var array<string, string> $attributes */
        return $attributes;
    }

    private function moduleProvider(bool $accessGranted): ModuleProvider
    {
        $moduleProvider = $this->createMock(ModuleProvider::class);
        $moduleProvider->method('accessGranted')->willReturn($accessGranted);

        return $moduleProvider;
    }
}

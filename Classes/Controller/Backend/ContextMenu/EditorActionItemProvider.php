<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend\ContextMenu;

use Netresearch\NrLlm\Controller\Backend\BackendUserUidTrait;
use Netresearch\NrLlm\Domain\Enum\BackendUserGrant;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogueInterface;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * The entry point that carries the record (ADR-158): one context-menu item on a
 * record an editor selected, opening the Editor Action Center for it.
 *
 * A catalogue nobody can reach from a record is a list, not an entry point. The
 * context menu is where the backend already asks "what can I do with this?", so
 * this provider adds one item there — and only where the answer is non-empty:
 * {@see canHandle()} asks the catalogue, which asks the real tool gate. An
 * editor who may run nothing on this table sees no item at all rather than an
 * item leading to an empty page.
 *
 * The item is a link, not an operation. It navigates to the module; nothing is
 * started here, and nothing about the record is read or disclosed.
 *
 * Lives under `Controller\` because it belongs to the backend package
 * (`nr_llm_backend`, ADR-090) and would move with it in a split — the seam test
 * treats that namespace as the backend, and a UI entry point importing the tool
 * module is exactly what that package is for.
 *
 * In the file list the identifier is a FAL combined identifier
 * (`1:/path/file.jpg`, `1:/path/`), not a uid (ADR-267). It is resolved, never
 * cast: `(int) '1:/path/file.jpg'` is the storage uid, a number that exists and
 * is wrong. A file becomes its `sys_file` uid, and the item is offered only
 * when the user may change that file's metadata, which is what every action on
 * `sys_file` writes. A folder becomes a folder scope for the batch page
 * (ADR-236), offered when the user may read the folder. Both answers come from
 * {@see FalStorageGate}, the gate the tools themselves ask.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class EditorActionItemProvider extends AbstractProvider
{
    use BackendUserUidTrait;

    private const ITEM_NAME = 'nrllmEditorActions';

    private const MODULE = 'nrllm_aitasks';

    private const FILE_TABLE = 'sys_file';

    /**
     * What a file-list identifier resolved to, per user and identifier: the
     * `sys_file` uid of a file, the combined identifier of a folder, or null
     * when it is neither or the user may not use it.
     *
     * The provider is a shared service, called once to decide and once to
     * render for the same identifier, so the second call must not resolve a
     * second time; keyed by the identifier because `setContext()` changes it.
     *
     * @var array<string, array{kind: 'file', uid: int}|array{kind: 'folder', identifier: string}|null>
     */
    private array $falSubjects = [];

    public function __construct(
        private readonly EditorActionCatalogueInterface $catalogue,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly ModuleProvider $moduleProvider,
        private readonly ResourceFactory $resourceFactory,
        private readonly FalStorageGate $storageGate,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();

        $this->itemsConfiguration = [
            self::ITEM_NAME => [
                'type'           => 'item',
                'label'          => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorActions.contextMenu.label',
                'iconIdentifier' => 'module-nrllm-task',
                'callbackAction' => 'openEditorActionCenter',
            ],
        ];
    }

    /**
     * After the core record/page items, before the clipboard block.
     */
    public function getPriority(): int
    {
        return 45;
    }

    public function canHandle(): bool
    {
        if ($this->table === '') {
            return false;
        }

        $isRecord = MathUtility::canBeInterpretedAsInteger($this->identifier);
        if ($isRecord ? (int) $this->identifier < 1 : $this->table !== self::FILE_TABLE) {
            return false;
        }

        $user = $this->backendUser;
        if (!$user instanceof BackendUserAuthentication) {
            return false;
        }

        // The same grant the module's actions require, asked through the same
        // seam they ask (ADR-130): the actor built at the HTTP boundary answers
        // it, so the admin-implies-every-grant and service-account rules cannot
        // drift from a copy kept here. Checked at all because an item leading to
        // a 403 page is worse than no item — the module remains the enforcement
        // point either way.
        if (!$this->currentActor()->hasGrant(BackendUserGrant::TASKS_USE)) {
            return false;
        }

        // The grant and the module tick are independent axes (ADR-130/131):
        // `nrllm_aitasks` is registered with `access: 'user'`, so a user may
        // hold `tasks_use` and still not have the module in their group list.
        // The item links into that module, and a link into a module the user
        // cannot open is the 403 the check above exists to avoid. Asked through
        // ModuleProvider rather than `check('modules', …)` so the registration
        // stays the single source: change `access` in Modules.php and this
        // answer follows.
        if (!$this->moduleProvider->accessGranted(self::MODULE, $user)) {
            return false;
        }

        try {
            // Resolved after the grant and the module, which cost no file
            // system access: a right-click by a user who may run nothing never
            // reaches the storage.
            if (!$isRecord && $this->falSubject($user) === null) {
                return false;
            }

            return $this->catalogue->groupsFor($user, $this->table) !== [];
        } catch (Throwable $e) {
            // This provider runs inside TYPO3's context menu, for every record
            // and every table — an exception here would cost the menu ALL its
            // items, on every right-click, because of an optional feature. The
            // catalogue reads persisted configuration through an Extbase
            // repository outside an Extbase request, which is the plausible
            // way for that to happen. Same reasoning as ADR-152's per-tool
            // catch: the decoration fails, nothing above it does.
            $this->logger?->warning('The editor-action context menu item was skipped because the catalogue could not be built.', [
                'table'     => $this->table,
                'exception' => $e,
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $items
     *
     * @return array<string, mixed>
     */
    public function addItems(array $items): array
    {
        // Deliberately NOT parent::addItems(): its initialize() builds and
        // rewrites the clipboard, and this item neither copies nor pastes.
        $this->initDisabledItems();

        /** @var array<string, mixed> $prepared */
        $prepared = $this->prepareItems($this->itemsConfiguration);

        return $items + $prepared;
    }

    /**
     * @return array<string, string>
     */
    protected function getAdditionalAttributes(string $itemName): array
    {
        if ($itemName !== self::ITEM_NAME) {
            return [];
        }

        $arguments = [
            'controller'  => 'Backend\\EditorAction',
            'action'      => 'catalogue',
            'recordTable' => $this->table,
        ];

        if (MathUtility::canBeInterpretedAsInteger($this->identifier)) {
            $arguments['recordUid'] = (int) $this->identifier;
        } else {
            // canHandle() resolved it already; this reads the same answer.
            $subject = $this->backendUser instanceof BackendUserAuthentication ? $this->falSubject($this->backendUser) : null;
            if ($subject === null) {
                return [];
            }

            if ($subject['kind'] === 'file') {
                $arguments['recordUid'] = $subject['uid'];
            } else {
                $arguments['folder'] = $subject['identifier'];
            }
        }

        return [
            // The core context menu appends '.js' before importing, so the
            // specifier must not carry it.
            'data-callback-module' => '@netresearch/nr-llm/Backend/ContextMenuActions',
            'data-catalogue-url'   => (string) $this->backendUriBuilder->buildUriFromRoute(self::MODULE, $arguments),
        ];
    }

    /**
     * The file or folder a file-list identifier names, if this user may use it.
     *
     * Resolved with `retrieveFileOrFolderObject()`, the call core's own file
     * item provider makes for the same identifier on the same right-click, so
     * the item adds no file-system access of a different kind. A file must be
     * one whose metadata the user may change — every action on `sys_file`
     * writes it, and an item leading to a card that can only be refused is the
     * item the grant check exists to avoid. A folder must be readable; the
     * batch it opens asks every file again (ADR-236).
     *
     * @return array{kind: 'file', uid: int}|array{kind: 'folder', identifier: string}|null
     */
    private function falSubject(BackendUserAuthentication $user): ?array
    {
        $key = $user->getUserId() . "\0" . $this->identifier;
        if (!array_key_exists($key, $this->falSubjects)) {
            $this->falSubjects[$key] = $this->resolveFalSubject($user);
        }

        return $this->falSubjects[$key];
    }

    /**
     * @return array{kind: 'file', uid: int}|array{kind: 'folder', identifier: string}|null
     */
    private function resolveFalSubject(BackendUserAuthentication $user): ?array
    {
        try {
            $resource = $this->resourceFactory->retrieveFileOrFolderObject($this->identifier);
        } catch (Throwable) {
            // A missing file, a missing storage and a malformed identifier all
            // mean the same here: nothing to offer.
            return null;
        }

        if ($resource instanceof File) {
            $uid = $resource->getUid();

            return $uid > 0 && $this->storageGate->isFileMetadataEditable($user, $resource->getStorage()->getUid(), $resource->getIdentifier())
                ? ['kind' => 'file', 'uid' => $uid]
                : null;
        }

        if ($resource instanceof Folder) {
            return $this->storageGate->isFolderAccessible($user, $resource->getStorage()->getUid(), $resource->getIdentifier())
                ? ['kind' => 'folder', 'identifier' => $resource->getCombinedIdentifier()]
                : null;
        }

        return null;
    }

    protected function canRender(string $itemName, string $type): bool
    {
        return !in_array($itemName, $this->disabledItems, true);
    }
}

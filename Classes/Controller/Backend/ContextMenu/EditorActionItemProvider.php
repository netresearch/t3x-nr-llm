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
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
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
 * Only integer identifiers are handled. In the file list the context menu's
 * identifier is a FAL combined identifier (`1:/path/file.jpg`), not a uid, and
 * `set_file_alternative_text` takes a `sys_file` uid — casting the one to the
 * other would produce a plausible wrong number. Files therefore have no
 * context-menu entry yet; the action stays visible in the catalogue.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class EditorActionItemProvider extends AbstractProvider
{
    use BackendUserUidTrait;

    private const ITEM_NAME = 'nrllmEditorActions';

    private const MODULE = 'nrllm_aitasks';

    public function __construct(
        private readonly EditorActionCatalogueInterface $catalogue,
        private readonly BackendUriBuilder $backendUriBuilder,
        private readonly ModuleProvider $moduleProvider,
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
        if ($this->table === '' || !MathUtility::canBeInterpretedAsInteger($this->identifier) || (int)$this->identifier < 1) {
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

        return [
            // The core context menu appends '.js' before importing, so the
            // specifier must not carry it.
            'data-callback-module' => '@netresearch/nr-llm/Backend/ContextMenuActions',
            'data-catalogue-url'   => (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_aitasks', [
                'controller'  => 'Backend\\EditorAction',
                'action'      => 'catalogue',
                'recordTable' => $this->table,
                'recordUid'   => (int)$this->identifier,
            ]),
        ];
    }

    protected function canRender(string $itemName, string $type): bool
    {
        return !in_array($itemName, $this->disabledItems, true);
    }
}

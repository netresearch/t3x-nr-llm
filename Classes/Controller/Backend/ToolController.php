<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Closure;
use Netresearch\NrLlm\Domain\Enum\ToolAuditScope;
use Netresearch\NrLlm\Service\Tool\ToolAuditRepositoryInterface;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolExclusionReadout;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Admin-only tool management backend module.
 *
 * Lists every registered tool with its global enable state and lets an admin
 * toggle it. Split from {@see ToolPlaygroundController} so the management view
 * (list + enable/disable) and the interactive playground (pick a config, run
 * the agent loop) are separate modules. {@see listAction()} renders the tool
 * table; the AJAX {@see toggleToolAction()} persists the global override that
 * the fail-closed runtime gate then enforces on every later run (ADR-038).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsController]
final class ToolController extends ActionController
{
    use DefensiveLocalizationTrait;
    use ModuleChromeTrait;
    use RequiresBackendAdminTrait;
    private const AUDIT_TABLE = 'tx_nrllm_tool_audit';

    private const TOOL_STATE_TABLE = 'tx_nrllm_tool_state';

    private const GROUP_STATE_TABLE = 'tx_nrllm_tool_group_state';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolAvailabilityServiceInterface $toolAvailability,
        private readonly ToolStateRepository $toolStateRepository,
        private readonly ToolGroupStateRepository $toolGroupStateRepository,
        private readonly PageRenderer $pageRenderer,
        // Which configurations exclude a tool (ADR-261). Optional only so a
        // positional construction keeps working; the container always
        // injects it.
        private readonly ?ToolExclusionReadout $exclusions = null,
        // The audit trail of the switches below. Optional and trailing for the
        // same reason; without it a switch is still applied, but not recorded.
        private readonly ?ToolAuditRepositoryInterface $audit = null,
        // The switch and its audit row are written in one transaction on
        // this pool's connection. Optional and trailing like the others.
        private readonly ?ConnectionPool $connectionPool = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function listAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/ToolState.js');
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->applyModuleChrome($moduleTemplate, $this->request);
        $moduleTemplate->assignMultiple([
            'toolStates' => $this->toolAvailability->states(),
            // Keyed by tool name; the template looks a row up and falls back to
            // the wire name when there is none (ADR-152). Fetched here rather
            // than carried in the state rows so the tool-call gate, which reads
            // those rows, never executes a declaration.
            'editorActions' => $this->toolAvailability->editorActions(),
            'groupStates'   => $this->groupStatesByName(),
            // Keyed by tool name: the active configurations that exclude it.
            // A row with no entry is excluded by none.
            'exclusions' => $this->exclusions?->byTool() ?? [],
        ]);

        return $moduleTemplate->renderResponse('Backend/Tool/List');
    }

    /**
     * Toggle the global enable state of a single tool (AJAX, admin-gated).
     *
     * Admin-gated via {@see denyNonAdmin()} FIRST (ADR-037). The override is
     * persisted to `tx_nrllm_tool_state` via {@see ToolStateRepository}; the
     * fail-closed runtime gate then refuses a disabled tool on every later run.
     * Only a registered tool name is accepted so the table cannot accumulate
     * orphan rows. A switch that changes the tool's own state is recorded in
     * the tool audit trail; the state compared is the tool's override or its
     * default, not the group cascade.
     */
    public function toggleToolAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $body     = $request->getParsedBody();
        $toolName = $this->stringFromBody($body, 'tool');
        $tool     = $toolName === '' ? null : $this->toolRegistry->get($toolName);
        if (!$tool instanceof ToolInterface) {
            return new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.unknownTool', 'Unknown tool')], 404);
        }

        $enabled = $this->boolFromBody($body, 'enabled');
        $failure = $this->switchAndRecord(
            ToolAuditScope::TOOL,
            $toolName,
            $enabled,
            fn (): bool => $this->toolStateRepository->overrides()[$toolName] ?? $tool->isEnabledByDefault(),
            fn () => $this->toolStateRepository->setEnabled($toolName, $enabled),
        );

        return $failure ?? new JsonResponse(['success' => true, 'enabled' => $enabled]);
    }

    /**
     * Toggle the global enable state of a whole tool GROUP (AJAX, admin-gated).
     *
     * Mirrors {@see toggleToolAction()}: admin gate first (ADR-037), then the
     * override is persisted to `tx_nrllm_tool_group_state`. Only a group of a
     * currently registered tool is accepted so the table cannot accumulate
     * orphan rows; the stored NAME still covers same-group tools installed
     * later. The cascade in ToolAvailabilityService keeps a disabled group
     * authoritative over any per-tool override (fail-closed). A switch that
     * changes the group's state is recorded in the tool audit trail.
     */
    public function toggleToolGroupAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $body      = $request->getParsedBody();
        $groupName = $this->stringFromBody($body, 'group');
        $known     = array_column($this->toolAvailability->groupStates(), 'name');
        if ($groupName === '' || !in_array($groupName, $known, true)) {
            return new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.unknownGroup', 'Unknown tool group')], 404);
        }

        $enabled = $this->boolFromBody($body, 'enabled');
        $failure = $this->switchAndRecord(
            ToolAuditScope::GROUP,
            $groupName,
            $enabled,
            fn (): bool => $this->toolGroupStateRepository->overrides()[$groupName] ?? true,
            fn () => $this->toolGroupStateRepository->setEnabled($groupName, $enabled),
        );

        return $failure ?? new JsonResponse(['success' => true, 'enabled' => $enabled]);
    }

    /**
     * Group states keyed by group name for direct template lookup.
     *
     * @return array<string, array{name: string, labelKey: string|null, enabled: bool, overridden: bool}>
     */
    private function groupStatesByName(): array
    {
        $byName = [];
        foreach ($this->toolAvailability->groupStates() as $state) {
            $byName[$state['name']] = $state;
        }

        return $byName;
    }

    /**
     * Write a switch and, when it changes the state, its audit row, as one
     * transaction: a failed audit insert rolls the switch back, so the state
     * never changes without a row (a retry would record nothing, because the
     * state would already match). The state and the audit table must share a
     * connection, as the skill sync requires for its tables; otherwise the
     * switch is refused. Returns the error response, or null on success.
     *
     * @param Closure(): bool $previous the state before the switch
     * @param Closure(): void $write    the state write
     */
    private function switchAndRecord(ToolAuditScope $scope, string $name, bool $enabled, Closure $previous, Closure $write): ?ResponseInterface
    {
        $apply = function () use ($scope, $name, $enabled, $previous, $write): void {
            $before = $previous();
            $write();
            if ($before !== $enabled) {
                $this->audit?->record($scope, $name, $before, $enabled, $this->backendUser());
            }
        };

        if (!$this->audit instanceof ToolAuditRepositoryInterface || !$this->connectionPool instanceof ConnectionPool) {
            $apply();

            return null;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::AUDIT_TABLE);
        $stateTable = $scope === ToolAuditScope::TOOL ? self::TOOL_STATE_TABLE : self::GROUP_STATE_TABLE;
        if ($connection !== $this->connectionPool->getConnectionForTable($stateTable)) {
            return $this->switchNotSaved($scope, $name, ['reason' => sprintf('The tables %s and %s must use the same database connection.', $stateTable, self::AUDIT_TABLE)]);
        }

        try {
            $connection->transactional(static function () use ($apply): void {
                $apply();
            });
        } catch (Throwable $e) {
            return $this->switchNotSaved($scope, $name, ['exception' => $e]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function switchNotSaved(ToolAuditScope $scope, string $name, array $context): ResponseInterface
    {
        $this->logger?->error('Tool switch: not saved, the switch and its audit row are written together', $context + [
            'scope' => $scope->value,
            'name'  => $name,
        ]);

        return new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.toggleFailed', 'The switch was not saved. See the system log for details.')], 500);
    }

    private function backendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    private function stringFromBody(mixed $body, string $key): string
    {
        if (!is_array($body)) {
            return '';
        }

        $value = $body[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function boolFromBody(mixed $body, string $key): bool
    {
        if (!is_array($body)) {
            return false;
        }

        // filter_var (not a plain cast) so the string "false"/"0" from form bodies is correctly false.
        return filter_var($body[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}

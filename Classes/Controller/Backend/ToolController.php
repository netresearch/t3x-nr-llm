<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
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
 */
#[AsController]
final class ToolController extends ActionController
{
    use RequiresBackendAdminTrait;
    use DefensiveLocalizationTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolAvailabilityServiceInterface $toolAvailability,
        private readonly ToolStateRepository $toolStateRepository,
        private readonly ToolGroupStateRepository $toolGroupStateRepository,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function listAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/ToolState.js');
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $moduleTemplate->assignMultiple([
            'toolStates'  => $this->toolAvailability->states(),
            'groupStates' => $this->groupStatesByName(),
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
     * orphan rows.
     */
    public function toggleToolAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }

        $body     = $request->getParsedBody();
        $toolName = $this->stringFromBody($body, 'tool');
        if ($toolName === '' || $this->toolRegistry->get($toolName) === null) {
            return new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.unknownTool', 'Unknown tool')], 404);
        }

        $enabled = $this->boolFromBody($body, 'enabled');
        $this->toolStateRepository->setEnabled($toolName, $enabled);

        return new JsonResponse(['success' => true, 'enabled' => $enabled]);
    }

    /**
     * Toggle the global enable state of a whole tool GROUP (AJAX, admin-gated).
     *
     * Mirrors {@see toggleToolAction()}: admin gate first (ADR-037), then the
     * override is persisted to `tx_nrllm_tool_group_state`. Only a group of a
     * currently registered tool is accepted so the table cannot accumulate
     * orphan rows; the stored NAME still covers same-group tools installed
     * later. The cascade in ToolAvailabilityService keeps a disabled group
     * authoritative over any per-tool override (fail-closed).
     */
    public function toggleToolGroupAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }

        $body      = $request->getParsedBody();
        $groupName = $this->stringFromBody($body, 'group');
        $known     = array_column($this->toolAvailability->groupStates(), 'name');
        if ($groupName === '' || !in_array($groupName, $known, true)) {
            return new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.unknownGroup', 'Unknown tool group')], 404);
        }

        $enabled = $this->boolFromBody($body, 'enabled');
        $this->toolGroupStateRepository->setEnabled($groupName, $enabled);

        return new JsonResponse(['success' => true, 'enabled' => $enabled]);
    }

    /**
     * Group states keyed by group name for direct template lookup.
     *
     * @return array<string, array{name: string, enabled: bool, overridden: bool}>
     */
    private function groupStatesByName(): array
    {
        $byName = [];
        foreach ($this->toolAvailability->groupStates() as $state) {
            $byName[$state['name']] = $state;
        }

        return $byName;
    }

    private function stringFromBody(mixed $body, string $key): string
    {
        if (!is_array($body)) {
            return '';
        }
        $value = $body[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
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

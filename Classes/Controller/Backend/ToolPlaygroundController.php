<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Admin-only interactive tool playground backend module.
 *
 * The v1 consumer of the tool runtime (PR-A): an admin picks an LLM
 * configuration, types a prompt, and the agent loop runs each tool call and
 * the final answer. {@see listAction()} renders the Fluid shell (config
 * picker, prompt box, tools panel, output pane); the AJAX runAction (Task 2)
 * runs the loop and returns the trace as JSON.
 *
 * Mirrors {@see SkillSourceController}: a `#[AsController]` Extbase
 * ActionController whose list action is the module entry point, gated to
 * admins via the module's ``access => admin`` and (for the AJAX action) the
 * {@see RequiresBackendAdminTrait} guard.
 */
#[AsController]
final class ToolPlaygroundController extends ActionController
{
    use RequiresBackendAdminTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function listAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/ToolPlayground.js');
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $moduleTemplate->assignMultiple([
            'configurations' => $this->configurationRepository->findAll(),
            'tools' => $this->toolRegistry->specs(),
            'ajaxRoute' => 'nrllm_tool_run',
        ]);
        return $moduleTemplate->renderResponse('Backend/ToolPlayground/List');
    }
}

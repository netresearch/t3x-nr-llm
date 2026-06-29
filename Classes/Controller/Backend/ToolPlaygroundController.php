<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocation;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
use Throwable;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
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
        private readonly AllowedToolsResolver $allowedToolsResolver,
        private readonly ToolLoopService $toolLoopService,
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

    /**
     * Run the bounded tool loop for the picked configuration and return its
     * trace as JSON (design §4.6).
     *
     * Admin-gated via {@see denyNonAdmin()} FIRST: AJAX routes bypass the
     * module's ``access => admin`` check (ADR-037). The configuration's vault
     * key, model and pricing reach the call through
     * {@see ToolLoopService::runLoop()} (it runs on
     * `chatWithToolsForConfiguration`). The skill-derived allow-list narrows
     * which tools are offered. Any unexpected provider/tool failure returns a
     * generic JSON 500 — never the exception message, which can carry
     * DBAL/PDO credentials — while the detail is logged downstream.
     */
    public function runAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }

        $body      = $request->getParsedBody();
        $configUid = $this->intFromBody($body, 'configuration');
        $prompt    = trim($this->stringFromBody($body, 'prompt'));

        $config = $this->configurationRepository->findByUid($configUid);
        if ($config === null || $prompt === '') {
            return new JsonResponse(['success' => false, 'error' => 'Invalid configuration or prompt'], 400);
        }

        $options = new ToolOptions(beUserUid: $this->currentBackendUserUid());

        try {
            $allowed = $this->allowedToolsResolver->resolve($config);
            $result  = $this->toolLoopService->runLoop([ChatMessage::user($prompt)], $config, $allowed, $options);
        } catch (Throwable) {
            return new JsonResponse(['success' => false, 'error' => 'Tool run failed'], 500);
        }

        return new JsonResponse([
            'success'      => true,
            'finalContent' => $result->finalContent,
            'trace'        => array_map(
                static fn(ToolInvocation $invocation): array => [
                    'name'      => $invocation->name,
                    // Empty arguments must serialise to a JSON object ({}), not an
                    // array ([]) — the OpenAI tool-call convention (ADR-038).
                    'arguments' => $invocation->arguments === [] ? new stdClass() : $invocation->arguments,
                    'result'    => $invocation->result,
                    'isError'   => $invocation->isError,
                ],
                $result->trace,
            ),
            'iterations' => $result->iterations,
            'truncated'  => $result->truncated,
            'usage'      => ['totalTokens' => $result->usage->totalTokens],
        ]);
    }

    /**
     * Resolve the current backend user's uid for the budget pre-flight (0 when
     * no real BE user is present — the budget check then treats it as anonymous).
     */
    private function currentBackendUserUid(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return 0;
        }
        // BackendUserAuthentication::$user is untyped and may be null before a
        // session is fully loaded (CLI/testing), so guard with is_array().
        $uid = is_array($backendUser->user) ? ($backendUser->user['uid'] ?? 0) : 0;
        return is_numeric($uid) ? (int)$uid : 0;
    }

    private function intFromBody(mixed $body, string $key): int
    {
        if (!is_array($body)) {
            return 0;
        }
        $value = $body[$key] ?? 0;
        return is_numeric($value) ? (int)$value : 0;
    }

    private function stringFromBody(mixed $body, string $key): string
    {
        if (!is_array($body)) {
            return '';
        }
        $value = $body[$key] ?? '';
        return is_scalar($value) ? (string)$value : '';
    }
}

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
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use ReflectionClass;
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
 * admins via the module's ``access => admin`` and (for the AJAX actions) the
 * {@see RequiresBackendAdminTrait} guard.
 */
#[AsController]
final class ToolPlaygroundController extends ActionController implements LoggerAwareInterface
{
    use RequiresBackendAdminTrait;
    use LoggerAwareTrait;
    use ErrorMessageSanitizerTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly ToolLoopService $toolLoopService,
        private readonly ToolAvailabilityServiceInterface $toolAvailability,
        private readonly ToolStateRepository $toolStateRepository,
        private readonly AllowedToolsResolver $allowedToolsResolver,
    ) {}

    public function listAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/ToolPlayground.js');
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/ToolState.js');
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $moduleTemplate->assignMultiple([
            'configurations' => $this->configurationRepository->findAll(),
            'toolStates' => $this->toolAvailability->states(),
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
     * `chatWithToolsForConfiguration`). The per-run allow-list comes from the
     * tool checkboxes the operator ticked (defaulting to the globally-enabled
     * set when none are sent); {@see ToolLoopService} still intersects it with
     * the global gate, so a disabled tool can never be offered. Any unexpected
     * provider/tool failure returns a generic JSON 500 — never the exception
     * message, which can carry DBAL/PDO credentials — while the detail is
     * logged downstream.
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

        // The checked tool boxes restrict this run; absent any selection, fall
        // back to the globally-enabled set. The runtime gate is authoritative
        // regardless (a disabled tool stays off).
        $selected = $this->toolNamesFromBody($body) ?? $this->toolAvailability->enabledNames();

        // Stay faithful to production (ADR-038 §5): if the configuration's skills
        // declare an allowed-tools allow-list, intersect the admin's selection
        // with it so the playground offers only what the config would actually
        // permit. null = no declaring skill ⇒ no skill-imposed restriction.
        $skillAllowed = $this->allowedToolsResolver->resolve($config);
        $allowed      = $skillAllowed !== null
            ? array_values(array_intersect($selected, $skillAllowed))
            : $selected;

        try {
            $result = $this->toolLoopService->runLoop([ChatMessage::user($prompt)], $config, $allowed, $options);
        } catch (Throwable $e) {
            $this->logger?->error('Tool playground run failed', ['exception' => $e]);

            return new JsonResponse(['success' => false, 'error' => $this->diagnoseRunFailure($e)], 500);
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
            return new JsonResponse(['success' => false, 'error' => 'Unknown tool'], 404);
        }

        $enabled = $this->boolFromBody($body, 'enabled');
        $this->toolStateRepository->setEnabled($toolName, $enabled);

        return new JsonResponse(['success' => true, 'enabled' => $enabled]);
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

    private function boolFromBody(mixed $body, string $key): bool
    {
        if (!is_array($body)) {
            return false;
        }
        // filter_var (not a plain cast) so the string "false"/"0" from form bodies is correctly false.
        return filter_var($body[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Read the ticked tool checkboxes (`tools[]`) from the run form. Returns the
     * selected names, or null when the form sent no `tools` key at all — the
     * caller then defaults to the globally-enabled set.
     *
     * @return list<string>|null
     */
    private function toolNamesFromBody(mixed $body): ?array
    {
        if (!is_array($body) || !isset($body['tools']) || !is_array($body['tools'])) {
            return null;
        }

        $names = [];
        foreach ($body['tools'] as $value) {
            if (is_string($value) && $value !== '') {
                $names[] = $value;
            }
        }

        return $names;
    }

    /**
     * Build an actionable error string for a failed run.
     *
     * The playground is admin-only ({@see RequiresBackendAdminTrait}), so it
     * surfaces the concrete failure — exception class + (secret-sanitised)
     * message, and for an upstream {@see ProviderResponseException} the HTTP
     * status and a truncated, sanitised response-body snippet — instead of a
     * generic "Tool run failed". The full exception is still logged separately.
     */
    private function diagnoseRunFailure(Throwable $e): string
    {
        $class   = (new ReflectionClass($e))->getShortName();
        $message = $this->sanitizeErrorMessage($e->getMessage());
        $detail  = sprintf('%s: %s', $class, $message);

        if ($e instanceof ProviderResponseException) {
            if ($e->httpStatus > 0) {
                $detail .= sprintf(' (HTTP %d)', $e->httpStatus);
            }
            $body = trim($e->responseBody);
            if ($body !== '') {
                $snippet = $this->sanitizeErrorMessage(mb_substr($body, 0, 500));
                $detail .= "\nProvider response: " . $snippet;
            }
        }

        return $detail;
    }
}

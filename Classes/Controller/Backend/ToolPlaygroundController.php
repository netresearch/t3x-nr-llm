<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\Response\PlaygroundRunResponse;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Service\Option\ToolOptions;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\RunAugmentation;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use ReflectionClass;
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
    use DefensiveLocalizationTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly LlmConfigurationRepository $configurationRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly ToolLoopService $toolLoopService,
        private readonly ToolAvailabilityServiceInterface $toolAvailability,
        private readonly AllowedToolsResolver $allowedToolsResolver,
        private readonly SkillRepository $skillRepository,
        private readonly PromptSnippetRepository $promptSnippetRepository,
    ) {}

    public function listAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/ToolPlayground.js');
        $this->pageRenderer->addCssFile('EXT:nr_llm/Resources/Public/Css/Backend/Playground.css');
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $moduleTemplate->assignMultiple([
            'configurations' => $this->configurationRepository->findAll(),
            'toolStates' => $this->toolAvailability->states(),
            'skills' => $this->availableSkills(),
            'snippets' => $this->availableSnippets(),
            'ajaxRoute' => 'nrllm_tool_run',
        ]);
        return $moduleTemplate->renderResponse('Backend/Playground/List');
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
            return new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.tool.invalidInput', 'Invalid configuration or prompt')], 400);
        }

        $captureRaw   = $this->boolFromBody($body, 'captureRaw');
        $dryRun       = $this->boolFromBody($body, 'dryRun');
        $systemPrompt = trim($this->stringFromBody($body, 'systemPrompt'));
        $maxRounds    = $this->intFromBody($body, 'maxRounds');
        $options      = new ToolOptions(
            systemPrompt: $systemPrompt !== '' ? $systemPrompt : null,
            beUserUid: $this->currentBackendUserUid(),
            captureRaw: $captureRaw,
        );

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

        $augmentation = new RunAugmentation(
            forcedSkills: $this->resolveForcedSkills($this->uidListFromBody($body, 'forcedSkills')),
            forcedSnippets: $this->promptSnippetRepository->findByUids($this->uidListFromBody($body, 'forcedSnippets')),
            dryRun: $dryRun,
        );
        $trace = new RunTrace(captureRaw: $captureRaw);

        try {
            $result = $this->toolLoopService->runLoop(
                [ChatMessage::user($prompt)],
                $config,
                $allowed,
                $options,
                $maxRounds > 0 ? $maxRounds : null,
                $trace,
                $augmentation,
            );
        } catch (Throwable $e) {
            $this->logger?->error('Tool playground run failed', ['exception' => $e]);

            return new JsonResponse(['success' => false, 'error' => $this->diagnoseRunFailure($e)], 500);
        }

        $response = new PlaygroundRunResponse(
            finalContent: $result->finalContent,
            iterations: $result->iterations,
            truncated: $result->truncated,
            dryRun: $dryRun,
            steps: $trace->getSteps(),
            promptTokens: $result->usage->promptTokens,
            completionTokens: $result->usage->completionTokens,
            totalTokens: $result->usage->totalTokens,
            estimatedCost: $result->usage->estimatedCost,
        );

        return new JsonResponse($response->toArray());
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

    private function boolFromBody(mixed $body, string $key): bool
    {
        if (!is_array($body)) {
            return false;
        }
        $value = $body[$key] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * Read a list of integer uids from a repeated body field (e.g.
     * ``forcedSkills[]``/``forcedSnippets[]``). Non-numeric and non-positive
     * entries are dropped.
     *
     * @return list<int>
     */
    private function uidListFromBody(mixed $body, string $key): array
    {
        if (!is_array($body) || !isset($body[$key]) || !is_array($body[$key])) {
            return [];
        }

        $uids = [];
        foreach ($body[$key] as $value) {
            if (is_numeric($value) && (int)$value > 0) {
                $uids[] = (int)$value;
            }
        }

        return $uids;
    }

    /**
     * Resolve forced-skill uids to their {@see Skill} models, preserving
     * request order. A forced skill is applied even when globally disabled —
     * forcing it is the whole point of the debugging control.
     *
     * @param list<int> $uids
     *
     * @return list<Skill>
     */
    private function resolveForcedSkills(array $uids): array
    {
        if ($uids === []) {
            return [];
        }

        $byUid = [];
        foreach ($this->skillRepository->findAll() as $skill) {
            if ($skill instanceof Skill && $skill->getUid() !== null) {
                $byUid[$skill->getUid()] = $skill;
            }
        }

        $skills = [];
        foreach ($uids as $uid) {
            if (isset($byUid[$uid])) {
                $skills[] = $byUid[$uid];
            }
        }

        return $skills;
    }

    /**
     * Enabled skills offered in the force-inject picker.
     *
     * @return list<Skill>
     */
    private function availableSkills(): array
    {
        $skills = [];
        foreach ($this->skillRepository->findAll() as $skill) {
            if ($skill instanceof Skill && $skill->isEnabled()) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    /**
     * Active prompt snippets offered in the force-add picker.
     *
     * @return list<PromptSnippet>
     */
    private function availableSnippets(): array
    {
        $snippets = [];
        foreach ($this->promptSnippetRepository->findAll() as $snippet) {
            if ($snippet instanceof PromptSnippet && $snippet->isActive()) {
                $snippets[] = $snippet;
            }
        }

        return $snippets;
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

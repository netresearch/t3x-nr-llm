<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Netresearch\NrLlm\Service\Prompt\SnippetCallerMarkService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Mark a snippet for a caller's own system message, or take the mark away
 * (ADR-214 item 8).
 *
 * Part of the admin-only ``nrllm_snippets`` module, and every action checks
 * {@see RequiresBackendAdminTrait::denyNonAdmin()} itself as well. The review
 * page shows the composed block and posts the digest of the block it
 * rendered; {@see SnippetCallerMarkService::mark()} refuses when that is no
 * longer the stored block. Mark and unmark change state only on a POST
 * through their own route, for the reason {@see SkillApprovalController}
 * gives.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsController]
final class PromptSnippetMarkController extends ActionController
{
    use DefensiveLocalizationTrait;
    use ModuleChromeTrait;
    use RequiresBackendAdminTrait;

    private const LL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    private ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly PromptSnippetRepository $snippetRepository,
        private readonly SnippetCallerMarkService $markService,
    ) {}

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        $this->applyModuleChrome($this->moduleTemplate, $this->request);
    }

    public function reviewAction(int $snippet = 0): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $record = $this->findSnippet($snippet);
        if (!$record instanceof PromptSnippet) {
            return $this->backToList('snippet.mark.flash.unknownSnippet', ContextualFeedbackSeverity::WARNING);
        }

        $this->moduleTemplate->assignMultiple([
            'snippet' => $record,
            'review'  => $this->markService->review($record),
        ]);

        return $this->moduleTemplate->renderResponse('Backend/PromptSnippet/Mark');
    }

    /**
     * @param string $digest the digest of the block the review page rendered
     */
    public function markAction(int $snippet = 0, string $digest = ''): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $record = $this->findSnippet($snippet);
        if (!$record instanceof PromptSnippet) {
            return $this->backToList('snippet.mark.flash.unknownSnippet', ContextualFeedbackSeverity::WARNING);
        }

        if (!$this->isStateChangeThroughOwnRoute('mark')) {
            // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
            return $this->redirect('review', null, null, ['snippet' => $snippet]);
        }

        $outcome = $this->markService->mark($record, $digest);
        $this->flash(
            'snippet.mark.flash.' . $outcome,
            $outcome === SnippetCallerMarkService::MARKED ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::WARNING,
        );

        // Extbase's redirect() takes an ACTION NAME, not a URL; the snippet
        // uid travels as an argument the target action re-validates.
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        return $this->redirect('review', null, null, ['snippet' => $snippet]);
    }

    public function unmarkAction(int $snippet = 0): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $record = $this->findSnippet($snippet);
        if (!$record instanceof PromptSnippet) {
            return $this->backToList('snippet.mark.flash.unknownSnippet', ContextualFeedbackSeverity::WARNING);
        }

        if (!$this->isStateChangeThroughOwnRoute('unmark')) {
            // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
            return $this->redirect('review', null, null, ['snippet' => $snippet]);
        }

        $this->markService->unmark($record);
        $this->flash('snippet.mark.flash.unmarked', ContextualFeedbackSeverity::OK);

        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        return $this->redirect('review', null, null, ['snippet' => $snippet]);
    }

    /**
     * Whether this request may change state as the given action: a POST
     * whose matched route is that action's own, so a route token is bound to
     * the action it authorises (see {@see SkillApprovalController}).
     */
    private function isStateChangeThroughOwnRoute(string $action): bool
    {
        if ($this->request->getMethod() !== 'POST') {
            return false;
        }

        $route = $this->request->getAttribute('route');

        return $route instanceof Route
            && $route->getOption('controller') === $this->request->getControllerName()
            && $route->getOption('action') === $action;
    }

    private function findSnippet(int $uid): ?PromptSnippet
    {
        if ($uid <= 0) {
            return null;
        }

        $snippet = $this->snippetRepository->findByUid($uid);

        return $snippet instanceof PromptSnippet ? $snippet : null;
    }

    private function backToList(string $key, ContextualFeedbackSeverity $severity): ResponseInterface
    {
        $this->flash($key, $severity);

        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        return $this->redirect('list', 'Backend\\PromptSnippet');
    }

    private function flash(string $key, ContextualFeedbackSeverity $severity): void
    {
        $this->addFlashMessage($this->localize(self::LL . $key, $key), '', $severity);
    }
}

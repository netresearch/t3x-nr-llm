<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Enum\SkillApprovalOutcome;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Service\Skill\SkillApprovalService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Review, approve and revoke skill versions (ADR-214 item 2).
 *
 * Part of the admin-only ``nrllm_skills`` module, and every action checks
 * {@see RequiresBackendAdminTrait::denyNonAdmin()} itself as well: until the
 * approval permission is decided, only administrators approve or revoke.
 *
 * The review page shows the current version, its diff against the most
 * recent approved snapshot and the approval history, and its form posts the
 * digest it rendered. {@see SkillApprovalService::approveVersion()} refuses when that
 * digest is no longer the current one, and the page is shown again with the
 * new state.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[AsController]
final class SkillApprovalController extends ActionController
{
    use DefensiveLocalizationTrait;
    use ModuleChromeTrait;
    use RequiresBackendAdminTrait;

    private const LL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    private ModuleTemplate $moduleTemplate;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SkillRepository $skillRepository,
        private readonly SkillApprovalService $approvalService,
    ) {}

    protected function initializeAction(): void
    {
        $this->moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->moduleTemplate->setFlashMessageQueue($this->getFlashMessageQueue());
        $this->applyModuleChrome($this->moduleTemplate, $this->request);
    }

    public function reviewAction(int $skill = 0): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $record = $this->findSkill($skill);
        if (!$record instanceof Skill) {
            return $this->backToList('skill.approval.flash.unknownSkill', ContextualFeedbackSeverity::WARNING);
        }

        $this->moduleTemplate->assignMultiple([
            'skill'  => $record,
            'review' => $this->approvalService->review($record),
        ]);

        return $this->moduleTemplate->renderResponse('Backend/Skill/Review');
    }

    /**
     * @param string $versionDigest the digest the review page rendered
     */
    public function approveVersionAction(int $skill = 0, string $versionDigest = ''): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $record = $this->findSkill($skill);
        if (!$record instanceof Skill) {
            return $this->backToList('skill.approval.flash.unknownSkill', ContextualFeedbackSeverity::WARNING);
        }

        // A state change is a POST of the review page's form to this action's
        // own route. A GET of this URL — a bookmark, a prefetch, a pasted link —
        // changes nothing, and neither does a request that reached this action
        // through another route's token with an action parameter.
        if (!$this->isStateChangeThroughOwnRoute('approveVersion')) {
            // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
            return $this->redirect('review', null, null, ['skill' => $skill]);
        }

        $outcome = $this->approvalService->approveVersion($record, $versionDigest, $this->actorUid());
        $this->flash(
            'skill.approval.flash.' . $outcome->value,
            $outcome === SkillApprovalOutcome::APPROVED ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::WARNING,
        );

        // Shown again either way: after a refusal the page renders the state
        // that is current now, which is what the approver has to decide on.
        // Extbase's redirect() takes an ACTION NAME, not a URL (the Symfony rule
        // reads its first argument as a destination). Every redirect here names
        // a literal action of this module; the skill uid travels as an argument
        // the target action re-validates.
        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        return $this->redirect('review', null, null, ['skill' => $skill]);
    }

    public function revokeVersionAction(int $skill = 0, string $versionDigest = ''): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) instanceof ResponseInterface) {
            return $deny;
        }

        $record = $this->findSkill($skill);
        if (!$record instanceof Skill) {
            return $this->backToList('skill.approval.flash.unknownSkill', ContextualFeedbackSeverity::WARNING);
        }

        // A state change is a POST of the review page's form to this action's
        // own route. A GET of this URL — a bookmark, a prefetch, a pasted link —
        // changes nothing, and neither does a request that reached this action
        // through another route's token with an action parameter.
        if (!$this->isStateChangeThroughOwnRoute('revokeVersion')) {
            // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
            return $this->redirect('review', null, null, ['skill' => $skill]);
        }

        $revoked = $this->approvalService->revokeVersion($record, $versionDigest, $this->actorUid());
        $this->flash(
            $revoked > 0 ? 'skill.approval.flash.revoked' : 'skill.approval.flash.nothingRevoked',
            $revoked > 0 ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::INFO,
        );

        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        return $this->redirect('review', null, null, ['skill' => $skill]);
    }

    /**
     * Whether this request may change state as the given action.
     *
     * The core's RouteDispatcher validates the request token against the
     * MATCHED route, while Extbase lets a request parameter override the
     * action that route names. A token of the review route — present in an
     * ordinary GET URL — would otherwise reach approve and revoke. Both
     * actions therefore require a POST whose matched route is their own, so
     * the route token is bound to the action it authorises.
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

    private function findSkill(int $uid): ?Skill
    {
        if ($uid <= 0) {
            return null;
        }

        $skill = $this->skillRepository->findByUid($uid);

        return $skill instanceof Skill ? $skill : null;
    }

    private function actorUid(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication && is_array($backendUser->user)) {
            $uid = $backendUser->user['uid'] ?? 0;

            return is_numeric($uid) ? (int)$uid : 0;
        }

        return 0;
    }

    private function backToList(string $key, ContextualFeedbackSeverity $severity): ResponseInterface
    {
        $this->flash($key, $severity);

        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        return $this->redirect('list', 'Backend\\SkillSource');
    }

    private function flash(string $key, ContextualFeedbackSeverity $severity): void
    {
        $this->addFlashMessage($this->localize(self::LL . $key, $key), '', $severity);
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\SkillSourceRepository;
use Netresearch\NrLlm\Service\Skill\SkillSyncService;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

#[AsController]
final class SkillSourceController extends ActionController
{
    use RequiresBackendAdminTrait;
    use DefensiveLocalizationTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SkillSourceRepository $sourceRepository,
        private readonly SkillRepository $skillRepository,
        private readonly SkillSyncService $syncService,
        private readonly VaultServiceInterface $vault,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly PageRenderer $pageRenderer,
        private readonly IconFactory $iconFactory,
        private readonly FormEngineUrlBuilder $formEngineUrlBuilder,
    ) {}

    public function listAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/SkillList.js');
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        // "Add source" button in the docheader → FormEngine new-record form for
        // tx_nrllm_skill_source, returning to this module after save/close.
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $createButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.skill.source.new', 'NrLlm') ?? 'Add source')
            ->setShowLabelText(true)
            ->setHref($this->formEngineUrlBuilder->buildNewUrl('tx_nrllm_skill_source', 'nrllm_skills'));
        $buttonBar->addButton($createButton);

        $sources = $this->sourceRepository->findAll();

        // FormEngine edit URLs per source, returning to this module after save/close
        // (mirrors the docheader "Add source" button's buildNewUrl pattern).
        /** @var array<int, string> $sourceEditUrls */
        $sourceEditUrls = [];
        foreach ($sources as $source) {
            // Surface an interrupted sync (a stale SYNCING lock) as a retryable ERROR here, so the
            // list never shows a source wedged on "Syncing" after a crash; a live sync is untouched.
            $this->syncService->reclaimStaleLock($source);
            $uid = $source->getUid();
            if ($uid === null) {
                continue;
            }
            $sourceEditUrls[$uid] = $this->formEngineUrlBuilder->buildEditUrl('tx_nrllm_skill_source', $uid, 'nrllm_skills');
        }

        $moduleTemplate->assignMultiple([
            'sources' => $sources,
            'sourceEditUrls' => $sourceEditUrls,
            'skills' => $this->skillRepository->findAll(),
        ]);
        return $moduleTemplate->renderResponse('Backend/Skill/List');
    }

    public function syncAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $uid = $this->intFromBody($request->getParsedBody(), 'source');
        $source = $this->sourceRepository->findByUid($uid);
        if ($source === null) {
            return new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.skill.unknownSource', 'Unknown source')], 404);
        }
        $result = $this->syncService->sync($source);
        return new JsonResponse([
            'success' => true,
            'status' => $result->status->value,
            'created' => $result->created,
            'updated' => $result->updated,
            'disabledOnChange' => $result->disabledOnChange,
            'orphaned' => $result->orphaned,
            'errors' => $result->errors,
        ]);
    }

    public function toggleSkillAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $request->getParsedBody();
        [$skill, $error] = $this->resolveToggleableSkill($body);
        if ($error !== null) {
            return $error;
        }
        assert($skill instanceof Skill);
        $skill->setEnabled($this->boolFromBody($body, 'enabled'));
        $this->skillRepository->update($skill);
        $this->persistenceManager->persistAll();
        return new JsonResponse(['success' => true, 'enabled' => $skill->isEnabled()]);
    }

    /**
     * Resolve and validate the skill targeted by a toggle request (early-return guard, like denyNonAdmin).
     *
     * @return array{0:?Skill,1:?ResponseInterface} The skill on success (error null), or a JSON error
     *                                              response (skill null) for an unknown or orphaned skill.
     */
    private function resolveToggleableSkill(mixed $body): array
    {
        $skill = $this->skillRepository->findByUid($this->intFromBody($body, 'skill'));
        if ($skill === null) {
            return [null, new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.skill.unknownSkill', 'Unknown skill')], 404)];
        }
        if ($skill->isOrphaned()) {
            return [null, new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.skill.orphaned', 'Cannot enable an orphaned skill')], 422)];
        }
        return [$skill, null];
    }

    public function setTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $request->getParsedBody();
        $source = $this->sourceRepository->findByUid($this->intFromBody($body, 'source'));
        if ($source === null) {
            return new JsonResponse(['success' => false, 'error' => $this->localize('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.skill.unknownSource', 'Unknown source')], 404);
        }
        $token = $this->stringFromBody($body, 'token');
        $uuid = $source->getGithubToken() !== '' ? $source->getGithubToken() : StringUtility::getUniqueId('ghtoken_');
        $this->vault->store($uuid, $token);
        $source->setGithubToken($uuid);
        $this->sourceRepository->update($source);
        $this->persistenceManager->persistAll();
        return new JsonResponse(['success' => true]);
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
}

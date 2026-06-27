<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\SkillSourceRepository;
use Netresearch\NrLlm\Service\Skill\SkillSyncService;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\StringUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

#[AsController]
final class SkillSourceController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SkillSourceRepository $sourceRepository,
        private readonly SkillRepository $skillRepository,
        private readonly SkillSyncService $syncService,
        private readonly VaultServiceInterface $vault,
        private readonly PersistenceManagerInterface $persistenceManager,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function listAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/SkillList.js');
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $moduleTemplate->assignMultiple([
            'sources' => $this->sourceRepository->findAll(),
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
            return new JsonResponse(['success' => false, 'error' => 'Unknown source'], 404);
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
        $skill = $this->skillRepository->findByUid($this->intFromBody($body, 'skill'));
        if ($skill === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unknown skill'], 404);
        }
        if ($skill->isOrphaned()) {
            return new JsonResponse(['success' => false, 'error' => 'Cannot enable an orphaned skill'], 422);
        }
        $skill->setEnabled($this->boolFromBody($body, 'enabled'));
        $this->skillRepository->update($skill);
        $this->persistenceManager->persistAll();
        return new JsonResponse(['success' => true, 'enabled' => $skill->isEnabled()]);
    }

    public function setTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }
        $body = $request->getParsedBody();
        $source = $this->sourceRepository->findByUid($this->intFromBody($body, 'source'));
        if ($source === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unknown source'], 404);
        }
        $token = $this->stringFromBody($body, 'token');
        $uuid = $source->getGithubToken() !== '' ? $source->getGithubToken() : StringUtility::getUniqueId('ghtoken_');
        $this->vault->store($uuid, $token);
        $source->setGithubToken($uuid);
        $this->sourceRepository->update($source);
        $this->persistenceManager->persistAll();
        return new JsonResponse(['success' => true]);
    }

    /**
     * Guard the AJAX endpoints: only an authenticated backend admin may sync, toggle or set tokens.
     * Skill source/skill management is admin-only (see Modules.php access => admin).
     */
    private function denyNonAdmin(): ?ResponseInterface
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication && $backendUser->isAdmin()) {
            return null;
        }
        return new JsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
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
        return (bool)($body[$key] ?? false);
    }
}

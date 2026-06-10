<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Countable;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Repository\PromptSnippetRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend controller for the prompt snippet library (list view).
 *
 * Snippets are small tagged prompt fragments (personas, tones of voice,
 * target audiences, image styles, layouts) managed centrally and queried
 * by consuming extensions. See ADR-031.
 *
 * Uses TYPO3 FormEngine for record editing (TCA-based forms).
 */
#[AsController]
final class PromptSnippetController extends ActionController
{
    private const TABLE_NAME = 'tx_nrllm_promptsnippet';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly PromptSnippetRepository $promptSnippetRepository,
        private readonly BackendUriBuilder $backendUriBuilder,
    ) {}

    /**
     * List all prompt snippets.
     */
    public function listAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        /** @var QueryResultInterface<int, PromptSnippet>&Countable $snippets */
        $snippets = $this->promptSnippetRepository->findAll();

        /** @var array<int, string> $editUrls */
        $editUrls = [];
        foreach ($snippets as $snippet) {
            if (!$snippet instanceof PromptSnippet) { // @phpstan-ignore instanceof.alwaysTrue
                continue;
            }
            $uid = $snippet->getUid();
            if ($uid === null) {
                continue;
            }
            $editUrls[$uid] = $this->buildEditUrl($uid);
        }

        $moduleTemplate->assignMultiple([
            'snippets' => $snippets,
            'totalCount' => $snippets->count(),
            'editUrls' => $editUrls,
            'newUrl' => $this->buildNewUrl(),
        ]);

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $createButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.snippet.new', 'NrLlm') ?? 'New Snippet')
            ->setShowLabelText(true)
            ->setHref($this->buildNewUrl());
        $buttonBar->addButton($createButton);

        return $moduleTemplate->renderResponse('Backend/PromptSnippet/List');
    }

    /**
     * Build FormEngine edit URL for a snippet.
     */
    private function buildEditUrl(int $uid): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::TABLE_NAME => [$uid => 'edit']],
            'returnUrl' => $this->buildReturnUrl(),
        ]);
    }

    /**
     * Build FormEngine new record URL.
     */
    private function buildNewUrl(): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [self::TABLE_NAME => [0 => 'new']],
            'returnUrl' => $this->buildReturnUrl(),
        ]);
    }

    /**
     * Build return URL for FormEngine (back to list).
     */
    private function buildReturnUrl(): string
    {
        return (string)$this->backendUriBuilder->buildUriFromRoute('nrllm_snippets');
    }
}

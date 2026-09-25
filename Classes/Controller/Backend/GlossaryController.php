<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Countable;
use Netresearch\NrLlm\Domain\Model\Glossary;
use Netresearch\NrLlm\Domain\Repository\GlossaryRepository;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend controller for the translation glossaries (list view, ADR-208).
 *
 * Same shape as the prompt snippet module: a list with create and edit links
 * into FormEngine, which does the editing, validation and history.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[AsController]
final class GlossaryController extends ActionController
{
    use ModuleChromeTrait;

    private const TABLE_NAME = 'tx_nrllm_glossary';

    private const MODULE_ROUTE = 'nrllm_glossaries';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly GlossaryRepository $glossaryRepository,
        private readonly FormEngineUrlBuilder $formEngineUrlBuilder,
    ) {}

    public function listAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $this->applyModuleChrome($moduleTemplate, $this->request);

        /** @var QueryResultInterface<int, Glossary>&Countable $glossaries */
        $glossaries = $this->glossaryRepository->findAll();

        /** @var array<int, string> $editUrls */
        $editUrls = [];
        foreach ($glossaries as $glossary) {
            if (!$glossary instanceof Glossary) { // @phpstan-ignore instanceof.alwaysTrue
                continue;
            }

            $uid = $glossary->getUid();
            if ($uid === null) {
                continue;
            }

            $editUrls[$uid] = $this->formEngineUrlBuilder
                ->buildEditUrl(self::TABLE_NAME, $uid, self::MODULE_ROUTE);
        }

        $newUrl = $this->formEngineUrlBuilder->buildNewUrl(self::TABLE_NAME, self::MODULE_ROUTE);

        $moduleTemplate->assignMultiple([
            'glossaries' => $glossaries,
            'totalCount' => $glossaries->count(),
            'editUrls' => $editUrls,
            'newUrl' => $newUrl,
        ]);

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $createButton = $buttonBar->makeLinkButton()
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL))
            ->setTitle(LocalizationUtility::translate('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:btn.translationGlossary.new', 'NrLlm') ?? 'New Glossary')
            ->setShowLabelText(true)
            ->setHref($newUrl);
        $buttonBar->addButton($createButton);

        return $moduleTemplate->renderResponse('Backend/Glossary/List');
    }
}

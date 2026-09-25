<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Form\Tca;

use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * TCA itemsProcFunc listing the configured sites for the `site_identifier`
 * select on tx_nrllm_glossary (ADR-208).
 *
 * The glossary stores the site IDENTIFIER — the value a translation caller
 * passes to {@see \Netresearch\NrLlm\Service\Option\TranslationOptions::withSite()}
 * — so the lookup compares one string and needs no site resolution at
 * translation time. A value already stored on the record whose site no longer
 * exists is appended, so the stored choice stays visible instead of silently
 * turning into the empty item.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class SiteItems
{
    /**
     * @param array{items: array<int, array{label: string, value: string}>, row?: array<string, mixed>} $params
     */
    public function addItems(array &$params): void
    {
        // makeInstance like the sibling item providers: FormEngine creates
        // this class through GeneralUtility::callUserFunction(), not through
        // the container.
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        $known = [];
        foreach ($siteFinder->getAllSites() as $site) {
            $identifier = $site->getIdentifier();
            $known[$identifier] = true;
            $params['items'][] = [
                'label' => sprintf('%s (%s)', $identifier, (string)$site->getBase()),
                'value' => $identifier,
            ];
        }

        $row    = is_array($params['row'] ?? null) ? $params['row'] : [];
        $stored = $row['site_identifier'] ?? '';
        if (is_array($stored)) {
            $stored = $stored[0] ?? '';
        }

        if (is_string($stored) && $stored !== '' && !isset($known[$stored])) {
            $params['items'][] = ['label' => $stored, 'value' => $stored];
        }
    }
}

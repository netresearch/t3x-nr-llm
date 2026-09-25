<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Hook;

use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Flushes the cached translations when a glossary record changes (ADR-209).
 *
 * The cache key already carries the glossary's terms, or the id of the DeepL
 * glossary holding them, so an edited glossary never serves an old answer. The
 * flush removes what can no longer be hit, and it is the one reliable way for
 * an editor to get a fresh translation of an unchanged text: save the
 * glossary. Every translation goes, not only the pair's — a glossary change
 * is rare, and a narrower flush would need a tag per site and pair.
 *
 * Registered under `processDatamapClass` and `processCmdmapClass` in
 * `ext_localconf.php`.
 */
final class GlossaryTranslationCacheFlushHook
{
    private const TABLE = 'tx_nrllm_glossary';

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, string|int $id, array $fieldArray, DataHandler $dataHandler): void
    {
        $this->flushFor($table);
    }

    public function processCmdmap_postProcess(string $command, string $table, string|int $id, mixed $value, DataHandler $dataHandler): void
    {
        $this->flushFor($table);
    }

    private function flushFor(string $table): void
    {
        if ($table !== self::TABLE) {
            return;
        }

        // Not through the constructor: the DataHandler creates hooks with
        // makeInstance() and no arguments; the interface is a public service.
        GeneralUtility::makeInstance(CacheManagerInterface::class)->flushByTag(TranslationService::CACHE_TAG);
    }
}

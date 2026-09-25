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
 * Flushes the cached translations when a record changes that decides what a
 * translation says (ADR-209): a glossary, or anything the LLM translator's
 * chat call reads from the default configuration — the configuration itself,
 * its model, the model's provider, its skills, its prompt snippets.
 *
 * The cache key already carries the glossary's terms (or the DeepL glossary
 * id), the default configuration's uid, model and last change, and its skills'
 * bodies, so most edits never serve an old answer anyway. The flush covers
 * what the key cannot see — a provider's endpoint, a model's settings, a
 * snippet — and is the one reliable way for an editor to get a fresh
 * translation of an unchanged text. Every translation goes, not only the
 * affected ones: such edits are rare, and a narrower flush would need a tag
 * per dependency.
 *
 * Registered under `processDatamapClass` and `processCmdmapClass` in
 * `ext_localconf.php`.
 */
final class TranslationCacheFlushHook
{
    /** The tables whose edits change a translation, see the class docblock. */
    private const TABLES = [
        'tx_nrllm_glossary',
        'tx_nrllm_configuration',
        'tx_nrllm_model',
        'tx_nrllm_provider',
        'tx_nrllm_skill',
        'tx_nrllm_promptsnippet',
    ];

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
        if (!in_array($table, self::TABLES, true)) {
            return;
        }

        // Not through the constructor: the DataHandler creates hooks with
        // makeInstance() and no arguments; the interface is a public service.
        GeneralUtility::makeInstance(CacheManagerInterface::class)->flushByTag(TranslationService::CACHE_TAG);
    }
}

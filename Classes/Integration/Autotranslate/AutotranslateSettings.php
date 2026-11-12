<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Integration\Autotranslate;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

use function is_array;
use function is_numeric;
use function is_string;
use function ltrim;
use function trim;

/**
 * The settings the EXT:autotranslate integration reads (ADR-289).
 *
 * Whether autotranslate translates through nr-llm is autotranslate's own
 * setting, `translatorClass`, naming {@see AutotranslateTranslator}. Which
 * nr-llm translator does the work is nr-llm's setting `autotranslate.translator`.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class AutotranslateSettings
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Whether autotranslate's `translatorClass` names nr-llm's translator.
     */
    public function routesThroughNrLlm(): bool
    {
        $translatorClass = $this->read('autotranslate', 'translatorClass');
        if (!is_string($translatorClass)) {
            return false;
        }

        return ltrim(trim($translatorClass), '\\') === AutotranslateTranslator::class;
    }

    /**
     * The identifier of the nr-llm translator autotranslate's texts go to.
     * Empty leaves the choice to TranslationService, which uses the language
     * model.
     */
    public function translator(): string
    {
        $autotranslate = $this->read('nr_llm', 'autotranslate');
        $translator    = is_array($autotranslate) ? ($autotranslate['translator'] ?? null) : null;

        return is_string($translator) ? trim($translator) : '';
    }

    /**
     * The backend user whose grants on a vault secret count on the command
     * line: `worker.technicalBeUserUid`, the worker identity of ADR-234.
     * autotranslate's batch command is such a worker. 0 when unset.
     */
    public function workerBeUserUid(): int
    {
        $worker = $this->read('nr_llm', 'worker');
        $uid    = is_array($worker) ? ($worker['technicalBeUserUid'] ?? null) : null;

        return is_numeric($uid) ? (int) $uid : 0;
    }

    private function read(string $extension, string $path): mixed
    {
        try {
            return $this->extensionConfiguration->get($extension, $path);
        } catch (Throwable) {
            return null;
        }
    }
}

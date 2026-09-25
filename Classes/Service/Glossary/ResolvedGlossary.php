<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Glossary;

use Netresearch\NrLlm\Domain\ValueObject\GlossaryTerms;

/**
 * A site glossary as the translation path sees it (ADR-208).
 *
 * Carries the record uid and the stored DeepL bookkeeping next to the terms,
 * because the DeepL handoff has to write back to exactly this record.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final readonly class ResolvedGlossary
{
    public function __construct(
        public int $uid,
        public string $sourceLanguage,
        public string $targetLanguage,
        public GlossaryTerms $terms,
        public string $deeplGlossaryId = '',
        public string $deeplEntriesHash = '',
    ) {}

    /**
     * Identifies the term list AND the language pair a DeepL glossary was
     * created for: the same terms for another pair are another glossary.
     */
    public function entriesHash(): string
    {
        return hash('sha256', $this->sourceLanguage . "\n" . $this->targetLanguage . "\n" . $this->terms->toTsv());
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Term;

/**
 * One visible row of tx_nrllm_term_list, parsed (ADR-238).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class TermListRule
{
    /**
     * @param list<string> $forbiddenTerms
     * @param list<string> $requiredTerms
     */
    public function __construct(
        public string $name,
        public string $siteIdentifier,
        public string $language,
        public array $forbiddenTerms,
        public array $requiredTerms,
        public string $message,
    ) {}

    /**
     * A list bound to a site or a language stays out of a call that names
     * another one. A call that names none is still screened by it, because a
     * false block is visible and has a reason, and a false pass is neither.
     */
    public function forbidsFor(string $siteIdentifier, string $language): bool
    {
        return ($this->siteIdentifier === '' || $siteIdentifier === '' || $this->siteIdentifier === $siteIdentifier)
            && ($this->language === '' || $language === '' || $this->language === $language);
    }

    /**
     * A required term binds only where the list is known to apply: demanding
     * one site's term from a call that may belong to another has no way out.
     */
    public function requiresFor(string $siteIdentifier, string $language): bool
    {
        return ($this->siteIdentifier === '' || $this->siteIdentifier === $siteIdentifier)
            && ($this->language === '' || $this->language === $language);
    }
}

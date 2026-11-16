<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Editorial;

/**
 * One broken rule of a checked text (ADR-300): a forbidden term found, a
 * required term missing, or a rule line the judge says a passage breaks.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ExternalTextFinding
{
    public const KIND_FORBIDDEN_TERM = 'forbidden_term';

    public const KIND_MISSING_TERM = 'missing_term';

    public const KIND_RULE = 'rule';

    /**
     * @param string $source  the term list's name, or the rule snippet's name
     * @param string $subject the term, or the text of the rule line
     * @param string $passage the passage that breaks a rule, '' for a term
     * @param bool   $blocks  whether this finding stops delivery: a term always,
     *                        a rule under a judge in block mode
     */
    public function __construct(
        public string $kind,
        public string $source,
        public string $subject,
        public string $passage = '',
        public int $ruleNumber = 0,
        public bool $blocks = true,
    ) {}

    /**
     * @return array{kind: string, source: string, subject: string, passage: string, ruleNumber: int, blocks: bool}
     */
    public function toArray(): array
    {
        return [
            'kind'       => $this->kind,
            'source'     => $this->source,
            'subject'    => $this->subject,
            'passage'    => $this->passage,
            'ruleNumber' => $this->ruleNumber,
            'blocks'     => $this->blocks,
        ];
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Editorial;

use function array_map;
use function array_values;
use function in_array;

/**
 * The result of a check of one delivered text (ADR-300).
 *
 * Bound to the exact text by its digest: a report says nothing about another
 * version of the text, a shortened or edited one included.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ExternalTextReport
{
    /** No term list applies and no judge is on. */
    public const REASON_NO_RULES = 'no_rules';

    /** A judge is on, but no active, visible snippet carries one of its tags. */
    public const REASON_RULE_SET_EMPTY = 'rule_set_empty';

    /** The judging configuration is not chosen, itself, missing or inactive. */
    public const REASON_JUDGE_NOT_CONFIGURED = 'judge_not_configured';

    /** The judge may not receive the text or a rule (ADR-288 R10). */
    public const REASON_JUDGE_EGRESS = 'judge_egress';

    /** The judge gave no usable answer. */
    public const REASON_JUDGE_UNAVAILABLE = 'judge_unavailable';

    /** The term lists could not be read or searched. */
    public const REASON_TERM_CHECK_FAILED = 'term_check_failed';

    /** The configurations or rules could not be read at all. */
    public const REASON_CHECK_FAILED = 'check_failed';

    /** The text, or every string of a JSON text, is empty: there is nothing a judge could read. */
    public const REASON_EMPTY_TEXT = 'empty_text';

    /**
     * @param list<ExternalTextFinding>                                         $findings
     * @param list<string>                                                      $reasons     why a part of the check did not run, one of the REASON_* constants
     * @param list<array{configuration: string, judging: string, mode: string}> $judges      per judged configuration, who judged it, apart from who wrote the text
     * @param bool                                                              $checkFailed a part of the check that blocks when it cannot run did not run: the term lists, or a judge in block mode
     */
    public function __construct(
        public ExternalTextStatus $status,
        public array $findings,
        public array $reasons,
        public string $textDigest,
        public string $ruleStateDigest,
        public string $configuration,
        public array $judges = [],
        public bool $checkFailed = false,
    ) {}

    /**
     * Whether the text must not be delivered: a broken term, a broken rule of
     * a judge in block mode, or a part of the check that had to run and did
     * not. The failure is carried apart from the status, so a finding that
     * does not block can never hide it.
     */
    public function blocks(): bool
    {
        if ($this->checkFailed || $this->status === ExternalTextStatus::FAILED) {
            return true;
        }

        foreach ($this->findings as $finding) {
            if ($finding->blocks) {
                return true;
            }
        }

        return false;
    }

    public function hasReason(string $reason): bool
    {
        return in_array($reason, $this->reasons, true);
    }

    /**
     * @return array{status: string, blocks: bool, checkFailed: bool, findings: list<array{kind: string, source: string, subject: string, passage: string, ruleNumber: int, blocks: bool}>, reasons: list<string>, textDigest: string, ruleStateDigest: string, configuration: string, judges: list<array{configuration: string, judging: string, mode: string}>}
     */
    public function toArray(): array
    {
        return [
            'status'          => $this->status->value,
            'blocks'          => $this->blocks(),
            'checkFailed'     => $this->checkFailed,
            'findings'        => array_values(array_map(static fn (ExternalTextFinding $finding): array => $finding->toArray(), $this->findings)),
            'reasons'         => $this->reasons,
            'textDigest'      => $this->textDigest,
            'ruleStateDigest' => $this->ruleStateDigest,
            'configuration'   => $this->configuration,
            'judges'          => $this->judges,
        ];
    }

    public static function digestOf(string $text): string
    {
        return 'sha256:' . hash('sha256', $text);
    }
}

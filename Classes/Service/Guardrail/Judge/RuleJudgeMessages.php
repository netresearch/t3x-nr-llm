<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Judge;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

use function array_map;
use function array_values;
use function implode;
use function mb_strlen;
use function mb_substr;
use function sprintf;

/**
 * The sentences an editor reads when the rule-set judge reports or blocks
 * (ADR-288), in the language of the person the call belongs to, like
 * {@see \Netresearch\NrLlm\Service\Guardrail\Term\TermListMessages}.
 *
 * A block quotes the rule and the passage, because ADR-238 asks that a block
 * can be explained by pointing at a line in a record. The governance log keeps
 * {@see self::blockedForAudit()} instead, which names the rules and no
 * generated text (ADR-064).
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class RuleJudgeMessages
{
    private const LL = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    /** A passage is quoted to be found, not reread. */
    private const MAX_PASSAGE_CHARS = 160;

    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
        private string $languageOfTheCall = '',
    ) {}

    public function inLanguage(string $language): self
    {
        return new self($this->languageServiceFactory, $language);
    }

    public function violation(JudgeViolation $violation): string
    {
        return sprintf(
            $this->label('ruleJudge.hint.violation', 'This passage may break the rule "%1$s" (snippet "%2$s"): "%3$s"'),
            $violation->rule->text,
            $violation->rule->snippetName,
            $this->quoted($violation->passage),
        );
    }

    public function unavailable(): string
    {
        return $this->label('ruleJudge.hint.unavailable', 'This text was not checked against the rule set, because the check gave no usable answer. Read it against the rules yourself.');
    }

    /**
     * One sentence per broken rule, with all its passages.
     *
     * @param list<JudgeViolation> $violations
     */
    public function blocked(array $violations): string
    {
        return sprintf(
            $this->label('ruleJudge.blocked.violation', 'This text was not delivered. It breaks the rule set: %s'),
            $this->ruleSentences($violations),
        );
    }

    /**
     * @param list<JudgeViolation> $violations
     */
    private function ruleSentences(array $violations): string
    {
        $sentences = [];
        foreach ($this->byRule($violations) as $group) {
            $sentences[] = sprintf(
                $this->label('ruleJudge.blocked.rule', 'Rule "%1$s" (snippet "%2$s"): %3$s.'),
                $group[0]->rule->text,
                $group[0]->rule->snippetName,
                implode(', ', array_map(fn (JudgeViolation $violation): string => sprintf($this->label('ruleJudge.blocked.passage', '"%s"'), $this->quoted($violation->passage)), $group)),
            );
        }

        return implode(' ', $sentences);
    }

    /**
     * The sentence for a write a chat proposed: the editor never saw it, and the
     * text of the chat itself is free.
     *
     * @param list<JudgeViolation> $violations
     */
    public function blockedWrite(array $violations): string
    {
        return sprintf(
            $this->label('ruleJudge.blocked.write', 'This change was not proposed. Its text breaks the rule set: %s'),
            $this->ruleSentences($violations),
        );
    }

    /**
     * What the governance log stores for a block: the rules, never the text.
     *
     * @param list<JudgeViolation> $violations
     */
    public function blockedForAudit(array $violations): string
    {
        return sprintf(
            'The rule-set judge found these rules broken: %s',
            implode('; ', array_map(
                static fn (array $group): string => sprintf('%d "%s" (snippet "%s")', $group[0]->rule->number, $group[0]->rule->text, $group[0]->rule->snippetName),
                $this->byRule($violations),
            )),
        );
    }

    /**
     * @param list<JudgeViolation> $violations
     *
     * @return list<non-empty-list<JudgeViolation>>
     */
    private function byRule(array $violations): array
    {
        $groups = [];
        foreach ($violations as $violation) {
            $groups[$violation->rule->number][] = $violation;
        }

        return array_values($groups);
    }

    public function blockedUnavailable(): string
    {
        return $this->label('ruleJudge.blocked.unavailable', 'This text was not delivered, because the check against the rule set gave no usable answer. Please try again. If this persists, ask an administrator to look at the system log.');
    }

    private function quoted(string $passage): string
    {
        return mb_strlen($passage) > self::MAX_PASSAGE_CHARS
            ? mb_substr($passage, 0, self::MAX_PASSAGE_CHARS) . '…'
            : $passage;
    }

    private function label(string $key, string $fallback): string
    {
        $label = $this->language()->sL(self::LL . $key);

        return $label !== '' ? $label : $fallback;
    }

    private function language(): LanguageService
    {
        if ($this->languageOfTheCall !== '') {
            return $this->languageServiceFactory->create($this->languageOfTheCall);
        }

        $user = $GLOBALS['BE_USER'] ?? null;

        return $this->languageServiceFactory->createFromUserPreferences(
            $user instanceof BackendUserAuthentication ? $user : null,
        );
    }
}

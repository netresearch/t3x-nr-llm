<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Editorial;

use Netresearch\NrLlm\Domain\Enum\RuleJudgeMode;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Service\Glossary\GlossaryResolver;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeEgressGate;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeReentry;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeUnavailableException;
use Netresearch\NrLlm\Service\Guardrail\Judge\JudgeViolation;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleJudge;
use Netresearch\NrLlm\Service\Guardrail\Judge\RuleSetResolver;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolverInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListRule;
use Netresearch\NrLlm\Service\Guardrail\Term\TermMatcher;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_unique;
use function array_values;
use function array_walk_recursive;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function preg_replace;
use function trim;

/**
 * Checks one text that is used outside TYPO3 against the rules of a
 * configuration (ADR-300).
 *
 * The same rules as the guardrail pipeline (term lists, ADR-238; rule-set
 * judge, ADR-288; further configurations, ADR-290), without the limits that
 * keep the pipeline off labels and codes: every length is checked, required
 * terms included, and a JSON text by each of its strings. The caller decides
 * what the text is; this service does not look at whether a run offered tools.
 *
 * Nothing here passes a text it could not check. A judge that gives no usable
 * answer, may not receive the text, or has no rules makes the report
 * `not_checked` in report mode and `failed` in block mode; a term check that
 * cannot run is `failed`; a text no rule applies to is `not_checked`.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class ExternalTextCheck
{
    public function __construct(
        private TermListResolverInterface $termLists,
        private TermMatcher $matcher,
        private RuleSetResolver $ruleSets,
        private RuleJudge $judge,
        private JudgeEgressGate $egress,
        private JudgeReentry $reentry,
        private LlmConfigurationRepository $configurations,
        private RuleStateDigest $ruleState,
        private LoggerInterface $logger,
    ) {}

    public function check(string $text, LlmConfiguration $configuration, ExternalTextCheckScope $scope = new ExternalTextCheckScope()): ExternalTextReport
    {
        try {
            return $this->checked($text, $configuration, $scope);
        } catch (Throwable $failure) {
            // The caller may be a worker whose recovery retries a failed run:
            // a store error here fails the check, it does not escape.
            $this->logger->error('The check of a delivered text could not run.', ['exception' => $failure]);

            return new ExternalTextReport(
                ExternalTextStatus::FAILED,
                [],
                [ExternalTextReport::REASON_CHECK_FAILED],
                ExternalTextReport::digestOf($text),
                '',
                $configuration->getIdentifier(),
                checkFailed: true,
            );
        }
    }

    private function checked(string $text, LlmConfiguration $configuration, ExternalTextCheckScope $scope): ExternalTextReport
    {
        $all      = $this->configurationsOf($configuration, $scope->furtherConfigurations);
        $findings = [];
        $reasons  = [];
        $applied  = false;
        $failed   = false;

        try {
            [$termFindings, $termsApplied] = $this->termFindings($text, $all, $scope);
            $findings                      = $termFindings;
            $applied                       = $termsApplied;
        } catch (Throwable $failure) {
            $this->logger->error('The term check of a delivered text could not run.', ['exception' => $failure]);
            $reasons[] = ExternalTextReport::REASON_TERM_CHECK_FAILED;
            $failed    = true;
        }

        $judged = $this->judgedConfigurations($all);
        $mode   = $this->strictestMode($judged);
        $judges = [];
        foreach ($judged as $judgedConfiguration) {
            $applied                         = true;
            [$violations, $reason, $judging] = $this->judged($text, $judgedConfiguration, $configuration, $scope);
            $judges[]                        = [
                'configuration' => $judgedConfiguration->getIdentifier(),
                'judging'       => $judging,
                'mode'          => $judgedConfiguration->getRuleJudgeModeEnum()->value,
            ];
            if ($reason !== '') {
                $reasons[] = $reason;
                if ($mode === RuleJudgeMode::BLOCK) {
                    $failed = true;
                }

                continue;
            }

            foreach ($violations as $violation) {
                $findings[] = new ExternalTextFinding(
                    ExternalTextFinding::KIND_RULE,
                    $violation->rule->snippetName,
                    $violation->rule->text,
                    $violation->passage,
                    $violation->rule->number,
                    $mode === RuleJudgeMode::BLOCK,
                );
            }
        }

        if (!$applied && $reasons === []) {
            $reasons[] = ExternalTextReport::REASON_NO_RULES;
        }

        return new ExternalTextReport(
            $this->status($findings, $reasons, $failed),
            $findings,
            array_values(array_unique($reasons)),
            ExternalTextReport::digestOf($text),
            $this->ruleStateOf($configuration, $scope),
            $configuration->getIdentifier(),
            $judges,
            $failed,
        );
    }

    /**
     * The digest of the rule state, '' when it cannot be read: an unknown
     * state is reported as unknown, never as a state.
     */
    private function ruleStateOf(LlmConfiguration $configuration, ExternalTextCheckScope $scope): string
    {
        try {
            return $this->ruleState->of($configuration, $scope->furtherConfigurations);
        } catch (Throwable $failure) {
            $this->logger->warning('The rule state of a delivered text could not be read.', ['exception' => $failure]);

            return '';
        }
    }

    /**
     * @param list<ExternalTextFinding> $findings
     * @param list<string>              $reasons
     */
    private function status(array $findings, array $reasons, bool $failed): ExternalTextStatus
    {
        if ($findings !== []) {
            return ExternalTextStatus::VIOLATION;
        }

        if ($failed) {
            return ExternalTextStatus::FAILED;
        }

        return $reasons === [] ? ExternalTextStatus::PASSED : ExternalTextStatus::NOT_CHECKED;
    }

    /**
     * The configuration and each further one named, once each; a named one
     * that does not exist adds nothing (as ADR-290).
     *
     * @param list<int> $further
     *
     * @return list<LlmConfiguration>
     */
    private function configurationsOf(LlmConfiguration $configuration, array $further): array
    {
        $all  = [$configuration];
        $seen = [$configuration->getUid()];
        foreach ($further as $uid) {
            if ($uid <= 0 || in_array($uid, $seen, true)) {
                continue;
            }

            $seen[] = $uid;
            $named  = $this->configurations->findByUid($uid);
            if ($named instanceof LlmConfiguration) {
                $all[] = $named;
            }
        }

        return $all;
    }

    /**
     * Every forbidden term found and every required term missing, across all
     * lists that apply: a report names all of them, where the pipeline stops
     * at the first.
     *
     * @param list<LlmConfiguration> $configurations
     *
     * @return array{list<ExternalTextFinding>, bool} the findings, and whether any list applied
     */
    private function termFindings(string $text, array $configurations, ExternalTextCheckScope $scope): array
    {
        $language = GlossaryResolver::baseLanguage($scope->language);
        $forms    = $this->forms($text);
        $findings = [];
        $applied  = false;
        foreach ($configurations as $configuration) {
            $uid = $configuration->getUid() ?? 0;
            if ($uid <= 0) {
                continue;
            }

            foreach ($this->termLists->forConfiguration($uid) as $list) {
                $applied  = $applied || $this->applies($list, $scope->siteIdentifier, $language);
                $findings = [...$findings, ...$this->listFindings($list, $forms, $scope->siteIdentifier, $language)];
            }
        }

        return [$this->unique($findings), $applied];
    }

    private function applies(TermListRule $list, string $site, string $language): bool
    {
        return ($list->forbiddenTerms !== [] && $list->forbidsFor($site, $language))
            || ($list->requiredTerms !== [] && $list->requiresFor($site, $language));
    }

    /**
     * @param list<string> $forms
     *
     * @return list<ExternalTextFinding>
     */
    private function listFindings(TermListRule $list, array $forms, string $site, string $language): array
    {
        $findings = [];
        if ($list->forbidsFor($site, $language)) {
            foreach ($list->forbiddenTerms as $term) {
                if ($this->matcher->occursIn($term, $forms)) {
                    $findings[] = new ExternalTextFinding(ExternalTextFinding::KIND_FORBIDDEN_TERM, $list->name, $term);
                }
            }
        }

        if ($list->requiresFor($site, $language)) {
            foreach ($list->requiredTerms as $term) {
                if (!$this->matcher->occursIn($term, $forms)) {
                    $findings[] = new ExternalTextFinding(ExternalTextFinding::KIND_MISSING_TERM, $list->name, $term);
                }
            }
        }

        return $findings;
    }

    /**
     * @param list<ExternalTextFinding> $findings
     *
     * @return list<ExternalTextFinding>
     */
    private function unique(array $findings): array
    {
        $unique = [];
        $keys   = [];
        foreach ($findings as $finding) {
            $key = $finding->kind . "\0" . $finding->source . "\0" . $finding->subject;
            if (!in_array($key, $keys, true)) {
                $keys[]   = $key;
                $unique[] = $finding;
            }
        }

        return $unique;
    }

    /**
     * The text as sent and, for JSON, each decoded string, in the search
     * forms of the matcher.
     *
     * @return list<string>
     */
    private function forms(string $text): array
    {
        $forms   = $this->matcher->searchForms($text);
        $body    = preg_replace('/(?:^\s*```[a-z]*\s*)|(?:\s*```\s*$)/i', '', $text) ?? $text;
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            array_walk_recursive($decoded, function (mixed $value) use (&$forms): void {
                if (is_string($value)) {
                    $forms = [...$forms, ...$this->matcher->searchForms($value)];
                }
            });
        }

        return $forms;
    }

    /**
     * The text a judge reads: the answer, or the strings of a JSON answer, as
     * the guardrail judges it (ADR-288 R9).
     */
    private function judgedText(string $text): string
    {
        $body    = preg_replace('/(?:^\s*```[a-z]*\s*)|(?:\s*```\s*$)/i', '', $text) ?? $text;
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $text;
        }

        $strings = [];
        array_walk_recursive($decoded, static function (mixed $value) use (&$strings): void {
            if (is_string($value) && trim($value) !== '') {
                $strings[] = $value;
            }
        });

        return implode("\n\n", $strings);
    }

    /**
     * One configuration's judge: its violations, or the reason it gave none.
     *
     * @return array{list<JudgeViolation>, string, string} violations, reason ('' when judged), judging configuration identifier
     */
    private function judged(string $text, LlmConfiguration $judged, LlmConfiguration $own, ExternalTextCheckScope $scope): array
    {
        $judgedText = $this->judgedText($text);
        if (trim($judgedText) === '') {
            return [[], ExternalTextReport::REASON_EMPTY_TEXT, ''];
        }

        $rules = $this->ruleSets->forTags($judged->getRuleJudgeTagList());
        if ($rules->isEmpty()) {
            return [[], ExternalTextReport::REASON_RULE_SET_EMPTY, ''];
        }

        try {
            $judging = $this->egress->judgingConfiguration($judged);
        } catch (JudgeUnavailableException) {
            return [[], ExternalTextReport::REASON_JUDGE_NOT_CONFIGURED, ''];
        }

        try {
            // The zone the text was written in: the serving provider, else the
            // provider of the configuration the text belongs to (ADR-290: a
            // named configuration brings its rules and its judge, not the
            // provider of the text).
            $this->egress->assertMayReceive($this->egress->writerZone($scope->writerProvider, $own), $judging, $rules);
        } catch (JudgeUnavailableException) {
            return [[], ExternalTextReport::REASON_JUDGE_EGRESS, $judging->getIdentifier()];
        }

        try {
            $violations = $this->reentry->during(fn (): array => $this->judge->judge(
                $judgedText,
                $rules,
                $judging,
                $scope->correlationId,
                $scope->beUserUid > 0 ? $scope->beUserUid : null,
            ));
        } catch (Throwable $failure) {
            $this->logger->warning('The rule-set judge gave no usable answer for a delivered text.', [
                'configuration' => $judged->getIdentifier(),
                'exception'     => $failure,
            ]);

            return [[], ExternalTextReport::REASON_JUDGE_UNAVAILABLE, $judging->getIdentifier()];
        }

        return [$violations, '', $judging->getIdentifier()];
    }

    /**
     * @param list<LlmConfiguration> $configurations
     *
     * @return list<LlmConfiguration>
     */
    private function judgedConfigurations(array $configurations): array
    {
        $judged = [];
        foreach ($configurations as $configuration) {
            if ($configuration->getRuleJudgeModeEnum() !== RuleJudgeMode::OFF) {
                $judged[] = $configuration;
            }
        }

        return $judged;
    }

    /**
     * Block over report: a stricter judge is never relaxed by a milder one.
     *
     * @param list<LlmConfiguration> $configurations
     */
    private function strictestMode(array $configurations): RuleJudgeMode
    {
        $mode = RuleJudgeMode::OFF;
        foreach ($configurations as $configuration) {
            $own = $configuration->getRuleJudgeModeEnum();
            if ($own === RuleJudgeMode::BLOCK) {
                return RuleJudgeMode::BLOCK;
            }

            if ($own === RuleJudgeMode::REPORT) {
                $mode = RuleJudgeMode::REPORT;
            }
        }

        return $mode;
    }
}

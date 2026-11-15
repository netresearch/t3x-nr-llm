<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Editorial;

use JsonException;
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
use Netresearch\NrLlm\Service\Guardrail\Term\TermCheckFailedException;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolverInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListRule;
use Netresearch\NrLlm\Service\Guardrail\Term\TermMatcher;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

use function array_unique;
use function array_values;
use function in_array;
use function preg_last_error_msg;
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
        // Read before the check, so the digest names the state the check read
        // or an earlier one, never a later edit.
        $ruleState = $this->ruleStateOf($configuration, $scope);
        $all       = $this->configurationsOf($configuration, $scope->furtherConfigurations);
        $findings  = [];
        $reasons   = [];
        $applied   = false;
        $failed    = false;

        try {
            [$termFindings, $termsApplied] = $this->termFindings($text, $this->configurationUids($configuration, $scope->furtherConfigurations), $scope);
            $findings                      = $termFindings;
            $applied                       = $termsApplied;
        } catch (Throwable $failure) {
            $this->logger->error('The term check of a delivered text could not run.', ['exception' => $failure]);
            $reasons[] = $failure instanceof TermCheckFailedException
                && in_array($failure->getMessage(), [ExternalTextReport::REASON_JSON_AMBIGUOUS, ExternalTextReport::REASON_JSON_UNDECODABLE], true)
                ? $failure->getMessage()
                : ExternalTextReport::REASON_TERM_CHECK_FAILED;
            $failed = true;
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
            $ruleState,
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
     * The uid of the configuration and of each further one named, once each.
     *
     * @param list<int> $further
     *
     * @return list<int>
     */
    private function configurationUids(LlmConfiguration $configuration, array $further): array
    {
        $uids = [];
        foreach ([$configuration->getUid() ?? 0, ...$further] as $uid) {
            if ($uid > 0 && !in_array($uid, $uids, true)) {
                $uids[] = $uid;
            }
        }

        return $uids;
    }

    /**
     * The configuration and each further one named, once each; a named one
     * that does not exist adds no judge (as ADR-290).
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
     * The lists are found by the uid a list names, as in the pipeline
     * (ADR-290): a named configuration that cannot be loaded, a hidden one
     * included, still brings its lists.
     *
     * @param list<int> $uids
     *
     * @return array{list<ExternalTextFinding>, bool} the findings, and whether any list applied
     */
    private function termFindings(string $text, array $uids, ExternalTextCheckScope $scope): array
    {
        $site     = trim($scope->siteIdentifier);
        $language = GlossaryResolver::baseLanguage(trim($scope->language));
        $lists    = [];
        foreach ($uids as $uid) {
            foreach ($this->termLists->forConfiguration($uid) as $list) {
                if ($this->applies($list, $site, $language)) {
                    $lists[] = $list;
                }
            }
        }

        if ($lists === []) {
            // No term applies: an undecodable text hides nothing a list asks.
            return [[], false];
        }

        $forms    = $this->forms($text);
        $findings = [];
        foreach ($lists as $list) {
            $findings = [...$findings, ...$this->listFindings($list, $forms, $site, $language)];
        }

        $applied = true;

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
     * Further forms a quoted passage may be found in: the decoded strings of
     * a JSON document. They only widen what counts as found, so a text the
     * term check refuses adds none, and the judge is not refused with it.
     *
     * @return list<string>
     */
    private function passageForms(string $text): array
    {
        try {
            return $this->forms($text);
        } catch (TermCheckFailedException) {
            return [];
        }
    }

    /**
     * The text as sent and, for a JSON document, each decoded key and string, in the search
     * forms of the matcher.
     *
     * @return list<string>
     */
    private function forms(string $text): array
    {
        $forms     = $this->matcher->searchForms($text);
        $structure = $this->decodedJson($text);
        if ($structure === null) {
            // No JSON: plain text, searched as it is.
            return $forms;
        }

        $parts = [$forms];
        foreach ($this->strings($structure) as $string) {
            $parts[] = $this->matcher->searchForms($string);
        }

        return array_merge(...$parts);
    }

    /**
     * The text decoded as one JSON document, or null when it is no JSON.
     * json_decode() alone decides what JSON is; nothing here lexes it.
     *
     * A decoder keeps one member of two with one key, and consumers differ in
     * which one, so a document whose keys are ambiguous cannot be vouched
     * for: the term check fails (X4). The keys a decoder kept are counted
     * against the keys the document names; the second count is taken only
     * from a document json_decode() accepted, where every string token is
     * well-formed and a quote outside a string cannot occur.
     *
     * @throws TermCheckFailedException for ambiguous keys, or when the pattern engine gives up
     */
    private function decodedJson(string $text): mixed
    {
        $body = preg_replace('/^\s*```[a-z0-9]*\s*/i', '', $text) ?? $text;
        $body = preg_replace('/\s*```\s*$/', '', $body) ?? $body;
        $body = preg_replace('/\A[\s\p{Z}\x{FEFF}]++/u', '', $body) ?? $body;
        $body = preg_replace('/[\s\p{Z}\x{FEFF}]++\z/u', '', $body) ?? $body;
        try {
            // Object mode: a node that was an object stays one, whatever
            // its keys look like, so every key is searched (strings()).
            $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $refused) {
            // The only risk of a text json_decode() refuses is an escape
            // that hides a term from the plain-text search: a text with one
            // is not vouched for, whatever its shape or the error. Other
            // text is plain text.
            if (preg_match('/\\\\(?:u[0-9a-fA-F]{4}|["\\\\\/bfnrt])/', $text) !== 0) {
                throw new TermCheckFailedException(ExternalTextReport::REASON_JSON_UNDECODABLE, 1799430103, $refused);
            }

            return null;
        }

        if ($this->namedKeys($body) !== $this->keyCount($decoded)) {
            throw new TermCheckFailedException(ExternalTextReport::REASON_JSON_AMBIGUOUS, 1799430102);
        }

        return $decoded;
    }

    /**
     * The keys a document json_decode() accepted names: its string tokens,
     * matched one after the other from the start (outside a string a valid
     * document holds no quote), that a colon follows.
     *
     * @throws TermCheckFailedException when the pattern engine gives up
     */
    private function namedKeys(string $body): int
    {
        if (preg_match_all('/"(?:[^"\\\\]++|\\\\.)*+"/', $body, $tokens, PREG_OFFSET_CAPTURE) === false) {
            throw new TermCheckFailedException('JSON key count failed: ' . preg_last_error_msg(), 1799430101);
        }

        $keys = 0;
        foreach ($tokens[0] as [$token, $offset]) {
            if (preg_match('/\G\s*+:/', $body, $colon, 0, $offset + strlen($token)) === 1) {
                ++$keys;
            }
        }

        return $keys;
    }

    private function keyCount(mixed $value): int
    {
        if ($value instanceof stdClass) {
            $count = 0;
            foreach (get_object_vars($value) as $member) {
                $count += 1 + $this->keyCount($member);
            }

            return $count;
        }

        if (is_array($value)) {
            $count = 0;
            foreach ($value as $item) {
                $count += $this->keyCount($item);
            }

            return $count;
        }

        return 0;
    }

    /**
     * Every key and every string value of a decoded document: every key of
     * an object, whatever it looks like, and no index of a list.
     *
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        $parts = [];
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $parts[] = [(string) $key];
                $parts[] = $this->strings($item);
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                $parts[] = $this->strings($item);
            }
        }

        return array_merge(...$parts);
    }

    /**
     * The text a judge reads: the delivered text itself. The pipeline judges
     * the strings of a JSON answer (ADR-288 R9), because a wizard places those
     * strings; a delivered text is used as it is, keys and every duplicate
     * included, so that is what is judged.
     */
    private function judgedText(string $text): string
    {
        return $text;
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
                // The judge reads the text raw; it may quote a passage of an
                // escaped JSON string decoded, and that passage is in the text.
                $this->passageForms($text),
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

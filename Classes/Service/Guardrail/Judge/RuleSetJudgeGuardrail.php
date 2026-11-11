<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Judge;

use Netresearch\NrLlm\Domain\Enum\RuleJudgeMode;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailHint;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Guardrail\StreamAnnotatingGuardrailInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolverInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermMatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Throwable;

use function array_map;
use function array_walk_recursive;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function preg_replace;
use function trim;

/**
 * Checks an output against the rule set of its configuration with a second
 * model (ADR-288).
 *
 * Off unless a configuration opts in. In report mode the output passes with a
 * hint per broken rule; a failed check passes with a hint that the text was not
 * checked. In block mode a broken rule stops the output with a message that
 * quotes the rule and the passage, and a failed check stops it too: an output
 * that could switch off its own check by confusing the judge has not passed.
 *
 * Only a finished text is judged: no tool round, no image description, nothing
 * in a run that offers tools (the chat, an editor action), and nothing shorter
 * than {@see self::MIN_WORDS}. A run without tools, a queued task or a field
 * fill, stores its answer, so that answer is judged. A JSON answer is judged by its
 * strings, as that is the text a wizard places on a page.
 *
 * Runs after the other guardrails, so it reads a redacted text and costs no
 * call when a term list already stopped the output.
 *
 * Mandatory in the sense of ADR-106: the opt-in is on the configuration.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
#[AsTaggedItem(priority: -1000)]
final readonly class RuleSetJudgeGuardrail implements StreamAnnotatingGuardrailInterface
{
    public const IDENTIFIER = 'rule-set-judge';

    /** Below this an answer is a label, a score or a code, not prose a rule speaks about. */
    public const MIN_WORDS = 30;

    public function __construct(
        private RuleSetResolver $ruleSets,
        private RuleJudge $judge,
        private RuleJudgeMessages $messages,
        private TermMatcher $matcher,
        private LlmConfigurationRepository $configurations,
        private TrustZoneResolver $trustZones,
        private TermListResolverInterface $users,
        private JudgeReentry $reentry,
        private LoggerInterface $logger,
    ) {}

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function isMandatory(): bool
    {
        return true;
    }

    /**
     * The rule set belongs to a configuration, and a call without context has none.
     */
    public function checkOutput(CompletionResponse $response): GuardrailResult
    {
        return GuardrailResult::allow();
    }

    public function checkOutputInContext(CompletionResponse $response, ProviderCallContext $context): GuardrailResult
    {
        $configuration = $context->configuration;
        $mode          = $configuration?->getRuleJudgeModeEnum() ?? RuleJudgeMode::OFF;
        if (!$configuration instanceof LlmConfiguration || $mode === RuleJudgeMode::OFF || !$this->judges($response, $context)) {
            return GuardrailResult::allow();
        }

        $text = $this->judgedText($response->content);
        if ($this->matcher->wordCount($text) < self::MIN_WORDS) {
            return GuardrailResult::allow();
        }

        $messages = $this->messagesFor($context);
        try {
            $violations = $this->reentry->during(fn (): array => $this->violations($text, $configuration, $context));
        } catch (Throwable $failure) {
            $this->logger->warning('The rule-set judge gave no usable answer.', [
                'configuration' => $configuration->getIdentifier(),
                'mode'          => $mode->value,
                'exception'     => $failure,
            ]);

            return $mode === RuleJudgeMode::BLOCK
                ? GuardrailResult::deny($messages->blockedUnavailable())
                : GuardrailResult::allowWithHints([new GuardrailHint(self::IDENTIFIER, GuardrailHint::KIND_UNAVAILABLE, $messages->unavailable())]);
        }

        if ($violations === []) {
            return GuardrailResult::allow();
        }

        if ($mode === RuleJudgeMode::BLOCK) {
            return GuardrailResult::deny($messages->blocked($violations), $messages->blockedForAudit($violations));
        }

        return GuardrailResult::allowWithHints(array_map(
            fn (JudgeViolation $violation): GuardrailHint => new GuardrailHint(
                self::IDENTIFIER,
                GuardrailHint::KIND_VIOLATION,
                $messages->violation($violation),
                $violation->rule->text,
                $violation->passage,
            ),
            $violations,
        ));
    }

    /**
     * Block mode holds the stream, as a term list does: the text appears once,
     * after the check.
     */
    public function holdsStream(ProviderCallContext $context): bool
    {
        return $context->configuration?->getRuleJudgeModeEnum() === RuleJudgeMode::BLOCK && $this->judgesStream($context);
    }

    /**
     * Report mode streams live and reports once the text is complete.
     */
    public function annotatesStream(ProviderCallContext $context): bool
    {
        return $context->configuration?->getRuleJudgeModeEnum() === RuleJudgeMode::REPORT && $this->judgesStream($context);
    }

    /**
     * @return list<JudgeViolation>
     *
     * @throws JudgeUnavailableException
     */
    private function violations(string $text, LlmConfiguration $configuration, ProviderCallContext $context): array
    {
        $rules   = $this->ruleSets->forTags($configuration->getRuleJudgeTagList());
        $judging = $this->judgingConfiguration($configuration);
        $this->assertMayReceive($configuration, $judging, $rules);

        $userUid = $context->metadata[BudgetMiddleware::METADATA_BE_USER_UID] ?? null;

        return $this->judge->judge($text, $rules, $judging, $context->correlationId, is_int($userUid) ? $userUid : null);
    }

    private function judgingConfiguration(LlmConfiguration $configuration): LlmConfiguration
    {
        $uid = $configuration->getRuleJudgeConfiguration();
        if ($uid <= 0 || $uid === $configuration->getUid()) {
            throw new JudgeUnavailableException('No judging configuration is chosen.', 9422928822);
        }

        $judging = $this->configurations->findByUid($uid);
        if (!$judging instanceof LlmConfiguration || !$judging->isActive()) {
            throw new JudgeUnavailableException('The judging configuration does not exist or is not active.', 4624017905);
        }

        return $judging;
    }

    /**
     * The judge sends the output and the rules to another provider. It may not
     * reach a provider less trusted than the one that wrote the text, nor one
     * whose zone does not admit a rule snippet's declared class.
     */
    private function assertMayReceive(LlmConfiguration $configuration, LlmConfiguration $judging, RuleSet $rules): void
    {
        $own   = $this->trustZones->zoneFor($configuration);
        $judge = $this->trustZones->zoneFor($judging);
        if (TrustZone::leastTrusted($own, $judge) !== $own) {
            throw new JudgeUnavailableException('The judging configuration reaches a less trusted provider than the judged one.', 2088642690);
        }

        foreach ($rules->dataClasses as $class) {
            if (!$judge->permits($class)) {
                throw new JudgeUnavailableException('A rule snippet declares a data class the judging configuration may not receive.', 8136373669);
            }
        }
    }

    private function judges(CompletionResponse $response, ProviderCallContext $context): bool
    {
        return !$this->reentry->isActive()
            && !$response->hasToolCalls()
            && $context->operation !== ProviderOperation::Vision
            && !$this->offersTools($context);
    }

    private function judgesStream(ProviderCallContext $context): bool
    {
        return !$this->reentry->isActive() && !$this->offersTools($context);
    }

    /**
     * A run that offers tools writes through them, and its answer is
     * conversation, as for a term list (ADR-238 amendment). A run without
     * tools, a queued task or a field fill, stores its answer and is judged.
     */
    private function offersTools(ProviderCallContext $context): bool
    {
        return isset($context->metadata[GuardrailMiddleware::METADATA_RUN_READ_TOOLS]);
    }

    /**
     * The answer, or the strings of a JSON answer: inside raw JSON the escapes
     * glue words together, and the keys are no text.
     */
    private function judgedText(string $content): string
    {
        $body    = preg_replace('/(?:^\s*```[a-z]*\s*)|(?:\s*```\s*$)/i', '', $content) ?? $content;
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $content;
        }

        $strings = [];
        array_walk_recursive($decoded, static function (mixed $value) use (&$strings): void {
            if (is_string($value) && trim($value) !== '') {
                $strings[] = $value;
            }
        });

        return implode("\n\n", $strings);
    }

    private function messagesFor(ProviderCallContext $context): RuleJudgeMessages
    {
        $userUid = $context->metadata[BudgetMiddleware::METADATA_BE_USER_UID] ?? 0;
        if (!is_int($userUid) || $userUid <= 0) {
            return $this->messages;
        }

        try {
            return $this->messages->inLanguage($this->users->languageOfUser($userUid));
        } catch (Throwable) {
            return $this->messages;
        }
    }
}

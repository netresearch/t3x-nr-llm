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
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ProviderRepository;
use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailHint;
use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
use Netresearch\NrLlm\Domain\ValueObject\ProviderIdentifier;
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

use function array_filter;
use function array_map;
use function array_values;
use function array_walk_recursive;
use function explode;
use function implode;
use function in_array;
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
 * A caller may name further configurations whose guardrails apply too
 * (ADR-290). Each one with its judge on is judged with its own rule set by its
 * own judging configuration, and the strictest mode of all decides.
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
        private ProviderRepository $providers,
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
        if ($this->reentry->isActive()) {
            return GuardrailResult::allow();
        }

        // The configuration of the call and every further one the caller named
        // whose judge is on (ADR-290); the strictest mode among them decides.
        $judged = $this->judgedConfigurations($context);
        $mode   = $this->strictestMode($judged);
        if ($mode === RuleJudgeMode::OFF) {
            return GuardrailResult::allow();
        }

        $readTools = $this->readToolsOfRun($context);
        if ($readTools === null && !$this->judges($response, $context)) {
            return GuardrailResult::allow();
        }

        // In a run that offers tools the output is what a writing tool would
        // store; the answer there is conversation (ADR-238 amendment).
        $text = $readTools !== null ? $this->writtenText($response, $readTools) : $this->judgedText($response->content);
        if ($this->matcher->wordCount($text) < self::MIN_WORDS) {
            return GuardrailResult::allow();
        }

        $messages   = $this->messagesFor($context);
        $violations = [];
        $current    = null;
        try {
            // Each side with its own rule set and its own judging
            // configuration, under its own trust-zone check (ADR-290).
            foreach ($judged as $configuration) {
                $current    = $configuration;
                $violations = $this->joined($violations, $this->reentry->during(fn (): array => $this->violations($text, $response, $configuration, $context)));
            }
        } catch (Throwable $failure) {
            $this->logger->warning('The rule-set judge gave no usable answer.', [
                'configuration' => $current?->getIdentifier() ?? '',
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
            return GuardrailResult::deny(
                $readTools !== null ? $messages->blockedWrite($violations) : $messages->blocked($violations),
                $messages->blockedForAudit($violations),
            );
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
        return $this->judgesStream($context) && $this->strictestMode($this->judgedConfigurations($context)) === RuleJudgeMode::BLOCK;
    }

    /**
     * Report mode streams live and reports once the text is complete.
     */
    public function annotatesStream(ProviderCallContext $context): bool
    {
        return $this->judgesStream($context) && $this->strictestMode($this->judgedConfigurations($context)) === RuleJudgeMode::REPORT;
    }

    /**
     * The configuration of the call, then each further configuration the
     * caller named (ADR-290), as far as its judge is on. A named one that does
     * not exist judges nothing. An inactive one still judges: its term lists
     * apply too, and a check is added, never dropped.
     *
     * @return list<LlmConfiguration>
     */
    private function judgedConfigurations(ProviderCallContext $context): array
    {
        $own = $context->configuration;
        if (!$own instanceof LlmConfiguration) {
            return [];
        }

        $configurations = [$own];
        $seen           = [$own->getUid()];
        $named          = $context->metadata[ProviderCallContext::METADATA_GUARDRAIL_CONFIGURATIONS] ?? '';
        foreach (explode(',', is_string($named) ? $named : '') as $value) {
            $uid = (int) trim($value);
            if ($uid <= 0 || in_array($uid, $seen, true)) {
                continue;
            }

            $seen[]        = $uid;
            $configuration = $this->configurations->findByUid($uid);
            if ($configuration instanceof LlmConfiguration) {
                $configurations[] = $configuration;
            }
        }

        return array_values(array_filter(
            $configurations,
            static fn (LlmConfiguration $configuration): bool => $configuration->getRuleJudgeModeEnum() !== RuleJudgeMode::OFF,
        ));
    }

    /**
     * Block over report over off: a stricter judge is never relaxed by a
     * milder one.
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

    /**
     * Violations of two sides, a rule broken at the same passage once.
     *
     * @param list<JudgeViolation> $violations
     * @param list<JudgeViolation> $more
     *
     * @return list<JudgeViolation>
     */
    private function joined(array $violations, array $more): array
    {
        foreach ($more as $violation) {
            $known = array_filter(
                $violations,
                static fn (JudgeViolation $seen): bool => $seen->rule->text === $violation->rule->text && $seen->passage === $violation->passage,
            );
            if ($known === []) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @return list<JudgeViolation>
     *
     * @throws JudgeUnavailableException
     */
    private function violations(string $text, CompletionResponse $response, LlmConfiguration $configuration, ProviderCallContext $context): array
    {
        $rules   = $this->ruleSets->forTags($configuration->getRuleJudgeTagList());
        $judging = $this->judgingConfiguration($configuration);
        // The zone of the provider that wrote the text: the call's own
        // configuration, also when a named configuration's judge asks
        // (ADR-290). The named side brings its rules and its judge, not the
        // provider of the text.
        $this->assertMayReceive($this->judgedZone($response, $context->configuration ?? $configuration), $judging, $rules);

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
     * The zone of the provider that wrote the text, failing closed.
     *
     * The provider that served the call where the response names it, else the
     * configuration's own provider. Where neither is known, or the provider
     * declares no zone, the text is treated as written in the most trusted
     * zone, so only a judge in that zone is asked. The usual default for an
     * unknown zone, the least trusted one, would let any judge pass here: it
     * is the right default for where data may go, and the wrong one for where
     * it came from.
     */
    private function judgedZone(CompletionResponse $response, LlmConfiguration $configuration): TrustZone
    {
        $provider   = null;
        $provenance = GeneratorProvenance::fromArray($response->metadata[GeneratorProvenance::METADATA_KEY] ?? null);
        if ($provenance instanceof GeneratorProvenance && $provenance->providerIdentifier !== '') {
            $provider = $this->providers->findOneByIdentifier(new ProviderIdentifier($provenance->providerIdentifier));
        }

        $provider ??= $configuration->getProvider();

        return $provider instanceof Provider
            ? TrustZone::tryFrom($provider->getTrustZone()) ?? TrustZone::LOCAL
            : TrustZone::LOCAL;
    }

    /**
     * The judge sends the output and the rules to another provider. It may not
     * reach a provider less trusted than the one that wrote the text, nor one
     * whose zone does not admit a rule snippet's declared class. The judging
     * side counts every provider it can reach, its fallbacks included.
     */
    private function assertMayReceive(TrustZone $own, LlmConfiguration $judging, RuleSet $rules): void
    {
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
        return !$response->hasToolCalls()
            && $context->operation !== ProviderOperation::Vision;
    }

    /**
     * The read tools of a run that offers tools, null for every other call.
     *
     * @return list<string>|null
     */
    private function readToolsOfRun(ProviderCallContext $context): ?array
    {
        $names = $context->metadata[GuardrailMiddleware::METADATA_RUN_READ_TOOLS] ?? null;

        return is_array($names) ? array_values(array_filter($names, is_string(...))) : null;
    }

    /**
     * The strings a writing tool of this response would store: the arguments
     * of every tool call that does not declare a read.
     *
     * @param list<string> $readTools
     */
    private function writtenText(CompletionResponse $response, array $readTools): string
    {
        $strings = [];
        foreach ($response->toolCalls ?? [] as $toolCall) {
            if (in_array($toolCall->name, $readTools, true)) {
                continue;
            }

            // array_walk_recursive() takes a reference; the arguments are readonly.
            $arguments = $toolCall->arguments;
            array_walk_recursive($arguments, static function (mixed $value) use (&$strings): void {
                if (is_string($value) && trim($value) !== '') {
                    $strings[] = $value;
                }
            });
        }

        return implode("\n\n", $strings);
    }

    private function judgesStream(ProviderCallContext $context): bool
    {
        return !$this->reentry->isActive() && !$this->offersTools($context);
    }

    /**
     * A run that offers tools writes through them, and its answer is
     * conversation, as for a term list (ADR-238 amendment): its stream is
     * neither held nor annotated, and its writes are judged in the response
     * that proposes them. A run without tools, a queued task or a field fill,
     * stores its answer and is judged like any call.
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

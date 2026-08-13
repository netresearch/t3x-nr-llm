<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Context;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;

/**
 * How sensitive the context a configuration injects is (ADR-144).
 *
 * Tool OUTPUT has been classified since ADR-094; what a run puts IN has not.
 * This closes that half for the three sources an operator can actually declare
 * on — the snippets a configuration composes, the skills attached to it, and
 * its own system prompt.
 *
 * The system prompt joined the two later (ADR-155). ADR-144 left it out because
 * a system prompt sits on a configuration that already knows its provider, so a
 * declaration could constrain nothing that was not already fixed. That holds for
 * a FIXED-mode configuration and not for a criteria-mode one, which knows no
 * provider until routing runs: once the zone comes from the resolved model
 * (ADR-149), a declared class decides which models the configuration may
 * resolve to.
 *
 * The TASK INPUT is still deliberately not classified. ADR-144's second argument
 * is untouched by any of this: the accepted input is whatever the caller passed
 * this second, with no per-record home a declaration could live in.
 *
 * Classification is the strictest declaration among the sources, because that
 * is what the send as a whole carries — one CONFIDENTIAL snippet makes the
 * whole prompt confidential, regardless of what accompanies it.
 *
 * @internal
 */
final readonly class InputContextClassifier
{
    public function __construct(
        private ?ConfigurationSnippetResolver $snippetResolver = null,
    ) {}

    public function classify(LlmConfiguration $configuration): InputContextClassification
    {
        return self::strictest($this->sources($configuration));
    }

    /**
     * Every source this configuration injects, in composition order, each with
     * the class it declared — or none, for a source nobody classified.
     *
     * The per-source view a readout needs (ADR-151): the gate's single
     * effective answer says a call is (or would be) refused, not which of six
     * snippets is the reason. {@see self::classify()} is the fold over exactly
     * this list, so the readout and the gate can never disagree about what a
     * configuration carries.
     *
     * `$forcedSnippets` / `$forcedSkills` are the per-RUN additions a caller
     * injects on top of the configuration (the playground's forced set). They
     * really do reach the wire, so a readout that omitted them would
     * under-report the run.
     *
     * Since ADR-164 the gate reads THIS method with the same forced set rather
     * than {@see self::classify()}, so the panel and the ceiling now answer for
     * the same source list. `classify()` deliberately keeps its narrower
     * configuration-only meaning: it is the fold a caller wants when the
     * question really is "what does this configuration carry", independent of
     * any one run.
     *
     * A forced source the configuration already carries is listed once: the
     * loop's own assembly drops the duplicate text, so counting it twice would
     * describe an injection that does not happen.
     *
     * Names only, never text — for the reason given on {@see self::snippetName()}.
     *
     * @param list<PromptSnippet> $forcedSnippets
     * @param list<Skill>         $forcedSkills
     *
     * @return list<InputContextClassification>
     */
    public function sources(LlmConfiguration $configuration, array $forcedSnippets = [], array $forcedSkills = []): array
    {
        $sources = [
            // Composition order: the system prompt is message 0, so it leads.
            ...$this->fromSystemPrompt($configuration),
            ...$this->fromSnippets($configuration),
            ...$this->fromSkills($configuration),
        ];
        $seen    = [];
        foreach ($sources as $source) {
            $seen[$source->source] = true;
        }

        foreach ($forcedSnippets as $snippet) {
            $this->append($sources, $seen, $this->snippetName($snippet->getIdentifier(), $snippet->getName()), $snippet->getDataClassEnum());
        }

        foreach ($forcedSkills as $skill) {
            $this->append($sources, $seen, $this->skillName($skill->getName()), $skill->getDataClassEnum());
        }

        return $sources;
    }

    /**
     * The strictest declaration across a source list, keeping the source that
     * set it — the fold {@see self::classify()} is, exposed so a caller holding
     * an already-fetched list (a readout over the forced set) folds that list
     * instead of paying for a second pass over the repository.
     *
     * @param list<InputContextClassification> $sources
     */
    public static function strictest(array $sources): InputContextClassification
    {
        $result = InputContextClassification::undeclared();
        foreach ($sources as $source) {
            $result = $result->withStricter($source);
        }

        return $result;
    }

    /**
     * @param list<InputContextClassification> $sources
     * @param array<string, true>              $seen
     */
    private function append(array &$sources, array &$seen, string $name, ?ToolDataClass $class): void
    {
        if (isset($seen[$name])) {
            return;
        }

        $seen[$name] = true;
        $sources[]   = $class instanceof ToolDataClass
            ? InputContextClassification::of($class, $name)
            : InputContextClassification::undeclaredFrom($name);
    }

    /**
     * @return list<InputContextClassification>
     */
    private function fromSnippets(LlmConfiguration $configuration): array
    {
        if (!$this->snippetResolver instanceof ConfigurationSnippetResolver) {
            return [];
        }

        $sources = [];
        foreach ($this->snippetResolver->selectedSnippets($configuration) as $snippet) {
            $name  = $this->snippetName($snippet->getIdentifier(), $snippet->getName());
            $class = $snippet->getDataClassEnum();

            $sources[] = $class === null
                ? InputContextClassification::undeclaredFrom($name)
                : InputContextClassification::of($class, $name);
        }

        return $sources;
    }

    /**
     * @return list<InputContextClassification>
     */
    private function fromSkills(LlmConfiguration $configuration): array
    {
        $sources = [];
        foreach (SkillInjectionService::toList($configuration->getSkills()) as $skill) {
            $name  = $this->skillName($skill->getName());
            $class = $skill->getDataClassEnum();

            $sources[] = $class === null
                ? InputContextClassification::undeclaredFrom($name)
                : InputContextClassification::of($class, $name);
        }

        return $sources;
    }

    /**
     * The class the configuration declared for its own system prompt (ADR-155).
     *
     * Named as the configuration, not as its text — the same rule the snippet
     * and skill sources follow, and for the same reason: the refusal this feeds
     * is shown to an operator and written to the audit.
     */
    /**
     * ADR-155's source, in the list shape the other two use.
     *
     * An undeclared prompt contributes NO entry rather than an undeclared one.
     * A snippet or a skill is listed either way because an operator picked it
     * and wants to see it whether or not it carries a class; a system prompt is
     * a field every configuration has, so listing it undeclared would put a row
     * on every readout that says nothing.
     *
     * @return list<InputContextClassification>
     */
    private function fromSystemPrompt(LlmConfiguration $configuration): array
    {
        $class = $configuration->getSystemPromptDataClassEnum();
        if (!$class instanceof ToolDataClass) {
            return [];
        }

        return [InputContextClassification::of($class, 'system prompt of "' . $configuration->getIdentifier() . '"')];
    }

    /**
     * Name a snippet by its identifier where it has one, its display name
     * otherwise. Never its text: the refusal this feeds is shown to an operator
     * and written to the audit, and the whole point of the classification is
     * that the content is sensitive.
     */
    private function snippetName(string $identifier, string $name): string
    {
        $identifier = trim($identifier);

        return 'snippet "' . ($identifier !== '' ? $identifier : $name) . '"';
    }

    /**
     * Name a skill by its display name — the only handle it has, and for the
     * same reason as {@see self::snippetName()} never its prose.
     */
    private function skillName(string $name): string
    {
        return 'skill "' . $name . '"';
    }
}

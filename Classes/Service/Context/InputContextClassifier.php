<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Context;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
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
        return $this->fromSnippets($configuration)
            ->withStricter($this->fromSkills($configuration))
            ->withStricter($this->fromSystemPrompt($configuration));
    }

    private function fromSnippets(LlmConfiguration $configuration): InputContextClassification
    {
        if (!$this->snippetResolver instanceof ConfigurationSnippetResolver) {
            return InputContextClassification::undeclared();
        }

        $result = InputContextClassification::undeclared();
        foreach ($this->snippetResolver->selectedSnippets($configuration) as $snippet) {
            $class = $snippet->getDataClassEnum();
            if ($class === null) {
                continue;
            }

            $result = $result->withStricter(InputContextClassification::of($class, $this->snippetName($snippet->getIdentifier(), $snippet->getName())));
        }

        return $result;
    }

    private function fromSkills(LlmConfiguration $configuration): InputContextClassification
    {
        $result = InputContextClassification::undeclared();
        foreach (SkillInjectionService::toList($configuration->getSkills()) as $skill) {
            $class = $skill->getDataClassEnum();
            if ($class === null) {
                continue;
            }

            $result = $result->withStricter(InputContextClassification::of($class, 'skill "' . $skill->getName() . '"'));
        }

        return $result;
    }

    /**
     * The class the configuration declared for its own system prompt (ADR-155).
     *
     * Named as the configuration, not as its text — the same rule the snippet
     * and skill sources follow, and for the same reason: the refusal this feeds
     * is shown to an operator and written to the audit.
     */
    private function fromSystemPrompt(LlmConfiguration $configuration): InputContextClassification
    {
        $class = $configuration->getSystemPromptDataClassEnum();
        if (!$class instanceof ToolDataClass) {
            return InputContextClassification::undeclared();
        }

        return InputContextClassification::of($class, 'system prompt of "' . $configuration->getIdentifier() . '"');
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
}

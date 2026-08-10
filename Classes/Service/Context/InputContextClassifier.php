<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Context;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\Prompt\ConfigurationSnippetResolver;
use Netresearch\NrLlm\Service\Skill\SkillInjectionService;

/**
 * How sensitive the context a configuration injects is (ADR-144).
 *
 * Tool OUTPUT has been classified since ADR-094; what a run puts IN has not.
 * This closes that half for the two sources an operator can actually declare
 * on — the snippets a configuration composes and the skills attached to it.
 *
 * The system prompt and the task input are deliberately NOT classified here.
 * Neither has a per-record home for a declaration: a system prompt is a field
 * on the configuration that already knows its own provider, and task input is
 * whatever the caller passed this second. A column for them would be a
 * declaration with nowhere to live and no one to set it.
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
        return $this->fromSnippets($configuration)->withStricter($this->fromSkills($configuration));
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

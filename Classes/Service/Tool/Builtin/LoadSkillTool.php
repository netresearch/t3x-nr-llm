<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\ValueObject\SkillCatalogue;
use Netresearch\NrLlm\Domain\ValueObject\SkillCatalogueEntry;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolDataClassInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * Loads an approved skill from the run's catalogue into the run's system
 * message (ADR-214 item 4).
 *
 * The agent loop handles the call itself ({@see \Netresearch\NrLlm\Service\Tool\ToolLoopService}),
 * because the effect is on the run's own transcript: it re-checks the skill
 * at load time, writes the section into the system message before the next
 * model call, and answers with a confirmation that never carries the body,
 * so an instruction never travels in the tool role. This class supplies what
 * the gates read — the spec, the global enable state, the group — and
 * refuses a call that reaches it outside the loop.
 *
 * Offered only when the run's catalogue is not empty. Exempt from the skill
 * allow-list and the configuration's allowed_tool_groups (ADR-214 item 5), so
 * a skill declaring `allowed-tools: []` does not block it; the global tool
 * state and the trust zone still apply. A load grants no tools.
 *
 * Security contract (see {@see ToolInterface}): read-only, not admin-only —
 * it reads only text an administrator approved, and only into the run.
 */
final readonly class LoadSkillTool implements ToolInterface, ToolDataClassInterface
{
    public const NAME = 'load_skill';

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'load_skill',
            'Load an approved skill of this installation into your instructions, by its uid from the skill catalogue. '
            . 'Load a skill when the task matches its description.',
            [
                'type'       => 'object',
                'properties' => [
                    'skill_uid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of a skill in the catalogue.',
                    ],
                ],
                'required' => ['skill_uid'],
            ],
        );
    }

    /**
     * The spec the loop offers for one run: the catalogue the model may load
     * from, listed in the description, and the uids as the only values.
     */
    public function specFor(SkillCatalogue $catalogue): ToolSpec
    {
        $spec = $this->getSpec();

        return ToolSpec::function(
            'load_skill',
            $spec->description . "\nSkill catalogue (uid: name — description):\n" . $catalogue->describe(),
            [
                'type'       => 'object',
                'properties' => [
                    'skill_uid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of a skill in the catalogue.',
                        'enum'        => array_map(static fn(SkillCatalogueEntry $entry): int => $entry->skillUid, $catalogue->entries),
                    ],
                ],
                'required' => ['skill_uid'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        return ToolResult::error('load_skill runs only inside an agent run that offers a skill catalogue.');
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        return 'configuration';
    }

    /**
     * What reaches the provider through a load is approved skill prose, which
     * editors write; the result itself carries only a confirmation. Declared
     * rather than taken from the group, whose default (internal configuration)
     * would keep loading away from every external provider. The skills' own
     * data classes are gated with the run's input context (ADR-155).
     */
    public function getDataClass(): ToolDataClass
    {
        return ToolDataClass::EDITOR_CONTENT;
    }
}

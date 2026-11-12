<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

/**
 * A publication profile: one agent skill the server role publishes (spec
 * 051 A2A-SV4, ADR-283). It binds a registered domain handler, the LLM
 * configuration and the tools the run may use.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aSkillProfile
{
    /**
     * @param list<string> $tags
     * @param list<string> $examples
     * @param list<string> $tools
     * @param list<string> $inputModes
     * @param list<string> $outputModes
     */
    public function __construct(
        public int $uid,
        public string $skillId,
        public string $name,
        public string $description,
        public array $tags,
        public array $examples,
        public string $handler,
        public int $configurationUid,
        public array $tools,
        public array $inputModes,
        public array $outputModes,
        public int $maxActiveTasks,
        public bool $public,
        public bool $mayWrite,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $int    = static fn (string $key): int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;
        $string = static fn (string $key): string => is_string($row[$key] ?? null) ? trim($row[$key]) : '';

        return new self(
            $int('uid'),
            $string('skill_id'),
            $string('name'),
            $string('description'),
            self::list($string('tags'), ','),
            self::list($string('examples'), "\n"),
            $string('handler'),
            $int('configuration_uid'),
            self::list($string('tools'), ','),
            self::list($string('input_modes'), ',') ?: ['text/plain'],
            self::list($string('output_modes'), ',') ?: ['text/plain'],
            $int('max_active_tasks'),
            $int('public') === 1,
            $int('may_write') === 1,
        );
    }

    /**
     * The card's `AgentSkill` (A2A-SV3).
     *
     * @return array<string, mixed>
     */
    public function toAgentSkill(): array
    {
        $skill = [
            'id'          => $this->skillId,
            'name'        => $this->name !== '' ? $this->name : $this->skillId,
            'description' => $this->description !== '' ? $this->description : $this->name,
            'tags'        => $this->tags !== [] ? $this->tags : [$this->skillId],
        ];
        if ($this->examples !== []) {
            $skill['examples'] = $this->examples;
        }

        $skill['inputModes']  = $this->inputModes;
        $skill['outputModes'] = $this->outputModes;

        return $skill;
    }

    /**
     * @param non-empty-string $separator
     *
     * @return list<string>
     */
    private static function list(string $value, string $separator): array
    {
        return array_values(array_filter(array_map(trim(...), explode($separator, $value)), static fn (string $item): bool => $item !== ''));
    }
}

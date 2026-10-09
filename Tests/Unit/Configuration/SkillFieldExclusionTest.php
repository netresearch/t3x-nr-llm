<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The fields that decide what a skill may say, which tools it grants and
 * whether its approval holds carry `exclude => true` (ADR-214 item 3), so a
 * group granted tables_modify on the skill tables cannot write them through
 * the DataHandler unless an administrator grants the field. `readOnly` alone
 * keeps a field out of FormEngine only.
 *
 * The other direction is asserted as well: the content fields stay outside
 * the exclude list, because their protection for a synced skill is the
 * digest's integrity check, and a backend-authored skill (ADR-214 item 3)
 * needs them writable.
 */
#[CoversNothing]
final class SkillFieldExclusionTest extends TestCase
{
    private const EXCLUDED = [
        'tx_nrllm_skill' => [
            // The eight of ADR-214 item 3 that live on the skill table…
            'trust_level', 'body_checksum', 'version_digest', 'enabled', 'source',
            // …and the sync-managed fields the integrity, tool and pin checks read.
            'allowed_tools', 'support_status', 'orphaned', 'raw_frontmatter', 'hidden',
        ],
        'tx_nrllm_skill_source' => [
            'trust_level', 'type',
            // What the source vouches for, and whether it vouches at all.
            'hidden', 'enabled', 'url', 'ref', 'pinned_sha', 'expected_fingerprint', 'github_token',
        ],
    ];

    private const NOT_EXCLUDED = [
        'tx_nrllm_skill'        => ['name', 'identifier', 'description', 'body', 'process'],
        'tx_nrllm_skill_source' => ['title'],
    ];

    #[Test]
    public function theFieldsThatDecideTrustAreExcluded(): void
    {
        foreach (self::EXCLUDED as $table => $fields) {
            $columns = $this->columns($table);
            foreach ($fields as $field) {
                self::assertArrayHasKey($field, $columns, $table . '.' . $field);
                self::assertTrue(($columns[$field]['exclude'] ?? false) === true, $table . '.' . $field . ' must carry exclude => true');
            }
        }
    }

    #[Test]
    public function theContentFieldsAreNotExcluded(): void
    {
        foreach (self::NOT_EXCLUDED as $table => $fields) {
            $columns = $this->columns($table);
            foreach ($fields as $field) {
                self::assertArrayHasKey($field, $columns, $table . '.' . $field);
                self::assertFalse(($columns[$field]['exclude'] ?? false) === true, $table . '.' . $field . ' must not be excluded');
            }
        }
    }

    #[Test]
    public function theDigestedContentOfASyncedSkillIsReadOnlyInTheForm(): void
    {
        $columns = $this->columns('tx_nrllm_skill');
        foreach (['name', 'description', 'body'] as $field) {
            self::assertTrue(($columns[$field]['config']['readOnly'] ?? false) === true, $field . ' is read-only for a synced skill');
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function columns(string $table): array
    {
        $tca = require dirname(__DIR__, 3) . '/Configuration/TCA/' . $table . '.php';
        self::assertIsArray($tca);
        $columns = $tca['columns'] ?? [];
        self::assertIsArray($columns);

        /** @var array<string, array<string, mixed>> $columns */
        return $columns;
    }
}

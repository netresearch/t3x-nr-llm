<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SuspendedRunState::class)]
final class SuspendedRunStateTest extends TestCase
{
    #[Test]
    public function anInputPauseRoundTripsItsToolNameAndSchema(): void
    {
        $state = new SuspendedRunState(
            messages: [['role' => 'user', 'content' => 'go']],
            pendingCalls: [['id' => 'call_1', 'name' => 'ask_user', 'arguments' => []]],
            iterations: 2,
            promptTokens: 5,
            completionTokens: 3,
            allowedToolNames: ['ask_user'],
            options: ['temperature' => 0.4],
            inputToolName: 'ask_user',
            inputSchema: ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
        );

        $restored = SuspendedRunState::fromArray($state->toArray());

        self::assertSame('ask_user', $restored->inputToolName);
        self::assertSame(['type' => 'object', 'properties' => ['city' => ['type' => 'string']]], $restored->inputSchema);
        self::assertSame(2, $restored->iterations);
        self::assertSame(['ask_user'], $restored->allowedToolNames);
    }

    #[Test]
    public function anApprovalEraRowWithoutInputKeysDefaultsToNullAndEmpty(): void
    {
        // Back-compat: a row persisted before ADR-105 carries neither key.
        $legacy = [
            'messages'         => [['role' => 'user', 'content' => 'go']],
            'pendingCalls'     => [],
            'iterations'       => 1,
            'promptTokens'     => 0,
            'completionTokens' => 0,
            'allowedToolNames' => null,
            'options'          => [],
        ];

        $restored = SuspendedRunState::fromArray($legacy);

        self::assertNull($restored->inputToolName);
        self::assertSame([], $restored->inputSchema);
    }

    #[Test]
    public function malformedInputKeysDegradeDefensively(): void
    {
        $restored = SuspendedRunState::fromArray([
            'messages'      => [],
            'pendingCalls'  => [],
            'inputToolName' => 42,          // not a string
            'inputSchema'   => 'not-an-array',
        ]);

        self::assertNull($restored->inputToolName);
        self::assertSame([], $restored->inputSchema);
    }

    /**
     * ADR-136: an entry that is not an array at all is dropped from
     * `pendingCalls` AND renumbers the calls behind it. The preview indices
     * count the raw list, so they must be translated onto the filtered one —
     * otherwise every preview after the dropped entry describes its neighbour.
     */
    #[Test]
    public function previewIndicesFollowTheirCallWhenAnEntryIsDroppedEntirely(): void
    {
        $restored = SuspendedRunState::fromArray([
            'messages'     => [],
            'pendingCalls' => [
                'not-an-array',
                ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'update_page_metadata', 'arguments' => ['uid' => 1]]],
                ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'update_page_metadata', 'arguments' => ['uid' => 2]]],
            ],
            'callPreviews' => [
                ['index' => 1, 'tool' => 'update_page_metadata', 'lines' => ['about uid 1'], 'failed' => false],
                ['index' => 2, 'tool' => 'update_page_metadata', 'lines' => ['about uid 2'], 'failed' => false],
            ],
        ]);

        self::assertCount(2, $restored->pendingCalls);
        self::assertSame(
            [
                ['index' => 0, 'tool' => 'update_page_metadata', 'lines' => ['about uid 1'], 'failed' => false],
                ['index' => 1, 'tool' => 'update_page_metadata', 'lines' => ['about uid 2'], 'failed' => false],
            ],
            $restored->callPreviews,
        );
    }

    #[Test]
    public function aPreviewWhoseCallDidNotSurviveIsDropped(): void
    {
        $restored = SuspendedRunState::fromArray([
            'messages'     => [],
            'pendingCalls' => [
                'not-an-array',
                ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'write_thing', 'arguments' => []]],
            ],
            'callPreviews' => [
                ['index' => 0, 'tool' => 'write_thing', 'lines' => ['about the unusable entry'], 'failed' => false],
            ],
        ]);

        self::assertSame([], $restored->callPreviews);
    }

    #[Test]
    public function theForcedSetSurvivesTheRoundTrip(): void
    {
        // ADR-165: a resume runs in a different process from the suspend, so
        // the run's forced sources reach the ADR-164 ceiling only if the state
        // carries their uids.
        $state = new SuspendedRunState([], [], 1, 0, 0, forcedSnippetUids: [41, 7], forcedSkillUids: [77]);

        // Through real JSON, because that is the round trip the AgentRun row
        // makes: an int list survives it, and this is where it would not.
        $decoded = json_decode(json_encode($state->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        $restored = SuspendedRunState::fromArray($decoded);

        self::assertSame([41, 7], $restored->forcedSnippetUids);
        self::assertSame([77], $restored->forcedSkillUids);
    }

    #[Test]
    public function aRowWrittenBeforeTheForcedSetExistedRehydratesWithoutIt(): void
    {
        // Every state suspended before ADR-165 lacks both keys, and a running
        // installation has such rows. They must resume, not fail.
        $restored = SuspendedRunState::fromArray(['messages' => [], 'pendingCalls' => []]);

        self::assertSame([], $restored->forcedSnippetUids);
        self::assertSame([], $restored->forcedSkillUids);
    }

    #[Test]
    public function anUnusableUidIsDroppedRatherThanHandedToARepository(): void
    {
        // JSON round-trips and hand-edited rows can carry anything. A uid of 0
        // or below identifies no record, and a non-numeric entry is not a uid
        // at all; both would only produce a lookup that answers nothing.
        $restored = SuspendedRunState::fromArray([
            'messages'          => [],
            'pendingCalls'      => [],
            'forcedSnippetUids' => [41, 0, -3, 'nine', '12', null, ['nested']],
        ]);

        self::assertSame([41, 12], $restored->forcedSnippetUids);
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Privacy;

use Closure;
use DateTimeImmutable;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRole;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTimestamp;
use Netresearch\NrLlm\Service\Privacy\A2aContentRedaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A structural member keeps its string only in the form the protocol gives
 * it; free text under a structural name is redacted (spec 051 A2A-R13).
 */
#[CoversClass(A2aContentRedaction::class)]
final class A2aContentRedactionTest extends TestCase
{
    #[Test]
    public function anIdOfAnotherFormIsRedacted(): void
    {
        $kept = $this->redacted([
            'messageId'        => '019a7b2c-5d3e-7f10-8a2b-3c4d5e6f7a8b',
            'taskId'           => '01JCDE7Z5Q8M3N4P5R6S7T8V9W',
            'artifactId'       => 'tck_abc-1:2.3',
            'contextId'        => 'call alice@example.com',
            'id'               => 'Alice Example, Main Street 5',
            'referenceTaskIds' => ['task-1', 'ask bob@example.com'],
        ]);

        self::assertSame('019a7b2c-5d3e-7f10-8a2b-3c4d5e6f7a8b', $kept['messageId']);
        self::assertSame('01JCDE7Z5Q8M3N4P5R6S7T8V9W', $kept['taskId']);
        self::assertSame('tck_abc-1:2.3', $kept['artifactId']);
        self::assertSame('[redacted]', $kept['contextId']);
        self::assertSame('[redacted]', $kept['id']);
        self::assertSame(['task-1', '[redacted]'], $kept['referenceTaskIds']);
    }

    #[Test]
    public function aValueOutsideTheMembersEnumIsRedacted(): void
    {
        $kept   = $this->redacted([['role' => 'ROLE_AGENT', 'kind' => 'message'], ['role' => 'tell carol@example.com', 'kind' => 'see carol@example.com']]);
        $status = $this->redacted(['state' => 'TASK_STATE_COMPLETED', 'timestamp' => '2026-11-12T16:30:00.123Z', 'status' => ['state' => 'done for carol@example.com', 'timestamp' => 'carol@example.com']]);

        self::assertSame([['role' => 'ROLE_AGENT', 'kind' => 'message'], ['role' => '[redacted]', 'kind' => '[redacted]']], $kept);
        self::assertSame(['state' => 'TASK_STATE_COMPLETED', 'timestamp' => '2026-11-12T16:30:00.123Z', 'status' => ['state' => '[redacted]', 'timestamp' => '[redacted]']], $status);
    }

    /**
     * The redaction writes the enum values out (it may not depend on the A2A
     * module); every case of the protocol enums is kept.
     */
    #[Test]
    public function everyRoleAndTaskStateOfTheProtocolIsKept(): void
    {
        $roles  = array_map(static fn (A2aRole $role): array => ['role' => $role->value], A2aRole::cases());
        $states = array_map(static fn (A2aTaskState $state): array => ['state' => $state->value], A2aTaskState::cases());

        self::assertSame($roles, $this->redacted($roles));
        self::assertSame($states, $this->redacted($states));
        $timestamps = [['timestamp' => A2aTimestamp::format(new DateTimeImmutable('2026-11-12 16:30:00.123'))], ['timestamp' => '2026-11-10T21:54:29.148533Z']];
        self::assertSame($timestamps, $this->redacted($timestamps));
    }

    #[Test]
    public function aMediaTypeOfAnotherFormIsRedactedAndDroppedFromAPart(): void
    {
        $kept = $this->redacted([
            'mediaType' => 'text/plain dave@example.com',
            'parts'     => [
                ['text' => 'a', 'mediaType' => 'text/plain; charset=utf-8'],
                ['text' => 'b', 'mediaType' => 'text/plain; note=dave@example.com'],
                ['text' => 'c', 'mediaType' => 'application/vnd.example+json'],
            ],
        ]);

        self::assertSame('[redacted]', $kept['mediaType']);
        self::assertSame([
            ['text' => '[redacted]', 'mediaType' => 'text/plain; charset=utf-8'],
            ['text' => '[redacted]'],
            ['text' => '[redacted]', 'mediaType' => 'application/vnd.example+json'],
        ], $kept['parts']);
    }

    #[Test]
    public function anExtensionThatIsNoPlainUriIsRedacted(): void
    {
        $kept = $this->redacted(['extensions' => [
            'https://a2a.example/extensions/timestamp/v1',
            'https://a2a.example/ext?user=erin@example.com',
            'https://erin:secret@a2a.example/ext',
            'erin@example.com',
        ]]);

        self::assertSame(['https://a2a.example/extensions/timestamp/v1', '[redacted]', '[redacted]', '[redacted]'], $kept['extensions']);
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function redacted(array $value): array
    {
        $result = A2aContentRedaction::redacted($value, $this->redactor());
        self::assertIsArray($result);

        return $result;
    }

    /**
     * @return Closure(string): string
     */
    private function redactor(): Closure
    {
        return static fn (string $text): string => '[redacted]';
    }
}

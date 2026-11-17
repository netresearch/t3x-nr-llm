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
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aRunOutputCheck;
use Netresearch\NrLlm\Service\Editorial\ExternalTextReport;
use Netresearch\NrLlm\Service\Privacy\A2aContentRedaction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
     * A data part that only claims to be an editorial report (a client or a
     * model can write one) keeps nothing but the tokens a report consists of.
     */
    #[Test]
    public function aPartClaimingToBeAnEditorialReportKeepsNoFreeText(): void
    {
        $kept = $this->redacted(['parts' => [['data' => [
            'kind'              => 'nr_llm.editorial_report',
            'secret'            => 'x',
            'editorialState'    => 'call carol@example.com',
            'checkedTextDigest' => 'sha256:' . str_repeat('a', 64),
            'generator'         => ['configuration' => 'carol@example.com'],
            'check'             => [
                'status'   => 'see carol@example.com',
                'blocks'   => 'carol@example.com',
                'reasons'  => ['rule_set_empty', 'mail carol@example.com'],
                'findings' => [['subject' => 'carol@example.com']],
                'secret'   => 'x',
            ],
        ], 'mediaType' => 'application/json']]]);

        $json = (string) json_encode($kept);
        self::assertStringNotContainsString('secret', $json);
        self::assertStringNotContainsString('carol', $json);
        self::assertStringNotContainsString(str_repeat('a', 64), $json);
        self::assertSame([
            'kind'           => 'nr_llm.editorial_report',
            'version'        => 1,
            'editorialState' => 'needs_human_review',
            'check'          => ['status' => 'not_checked', 'blocks' => true, 'reasons' => ['rule_set_empty'], 'findings' => [], 'findingsWithheld' => true],
        ], $kept['parts'][0]['data'] ?? null);
    }

    /**
     * A real report keeps its state, status and reasons, and withholds its
     * findings and digests.
     */
    #[Test]
    public function anEditorialReportKeepsItsStatusAndWithholdsItsFindings(): void
    {
        $kept = $this->redacted(['parts' => [['data' => [
            'kind'                => 'nr_llm.editorial_report',
            'version'             => 1,
            'editorialState'      => 'needs_revision',
            'checkedTextDigest'   => 'sha256:' . str_repeat('b', 64),
            'deliveredTextDigest' => 'sha256:' . str_repeat('b', 64),
            'check'               => ['status' => 'violation', 'blocks' => false, 'reasons' => [], 'findings' => [['subject' => 'Rabatt', 'passage' => 'Rabatt gern']], 'findingsWithheld' => false],
        ], 'mediaType' => 'application/json']]]);

        self::assertSame([
            'kind'           => 'nr_llm.editorial_report',
            'version'        => 1,
            'editorialState' => 'needs_revision',
            'check'          => ['status' => 'violation', 'blocks' => false, 'reasons' => [], 'findings' => [], 'findingsWithheld' => true],
        ], $kept['parts'][0]['data'] ?? null);
    }

    /**
     * The rebuild redacts the text the draft named and drops the digests that
     * bound the report to it, so the client no longer holds the text that
     * passed: a draft is rebuilt as needing a human review.
     */
    #[Test]
    public function aDraftRebuiltAtRedactedNeedsAHumanReview(): void
    {
        $kept = $this->redacted(['parts' => [['data' => [
            'kind'           => 'nr_llm.editorial_report',
            'version'        => 1,
            'editorialState' => 'draft',
            'check'          => ['status' => 'passed', 'blocks' => false, 'reasons' => [], 'findings' => [], 'findingsWithheld' => false],
        ], 'mediaType' => 'application/json']]]);

        self::assertSame('needs_human_review', $kept['parts'][0]['data']['editorialState'] ?? null);
        self::assertSame('passed', $kept['parts'][0]['data']['check']['status'] ?? null);
    }

    /**
     * Every reason a check can give survives the redaction, so a new reason
     * cannot be dropped silently.
     */
    #[Test]
    public function everyReasonOfACheckIsKept(): void
    {
        $reasons = [];
        foreach ([ExternalTextReport::class, A2aRunOutputCheck::class] as $class) {
            foreach ((new ReflectionClass($class))->getReflectionConstants() as $constant) {
                if (str_starts_with($constant->getName(), 'REASON_') && is_string($constant->getValue())) {
                    $reasons[] = $constant->getValue();
                }
            }
        }

        $kept = $this->redacted(['parts' => [['data' => ['kind' => 'nr_llm.editorial_report', 'check' => ['reasons' => $reasons]]]]]);

        self::assertSame($reasons, $kept['parts'][0]['data']['check']['reasons'] ?? null);
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

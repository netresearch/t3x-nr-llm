<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunLineage;
use Netresearch\NrLlm\Domain\ValueObject\SkillInvocation;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The invocation API's value objects (ADR-228): what a caller hands in and
 * what a run stores as its lineage. Each is read back strictly — a stored or
 * queued value that does not have the exact shape reads as unreadable, never
 * as a different, weaker value.
 */
#[CoversClass(SubjectRecord::class)]
#[CoversClass(SkillInvocation::class)]
#[CoversClass(RunLineage::class)]
#[CoversClass(AgentRun::class)]
final class ProcessInvocationValueObjectsTest extends TestCase
{
    #[Test]
    public function aSubjectRecordRoundTrips(): void
    {
        $subject = new SubjectRecord(new RecordReference('tt_content', 12), 1);

        self::assertSame(['table' => 'tt_content', 'uid' => 12, 'language' => 1], $subject->toArray());
        self::assertEquals($subject, SubjectRecord::fromArray($subject->toArray()));
        self::assertSame(0, (new SubjectRecord(new RecordReference('pages', 1)))->language);
    }

    #[Test]
    public function aSubjectRecordRefusesANegativeLanguage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1793500001);
        $subject = new SubjectRecord(new RecordReference('pages', 1), -1);

        // S1848: the instantiation must be used; name what was accepted.
        self::fail('A negative language was accepted: ' . $subject->language);
    }

    #[Test]
    public function aMalformedSubjectRecordReadsAsUnreadable(): void
    {
        foreach ([
            'not an array'       => 'pages:1',
            'a string uid'       => ['table' => 'pages', 'uid' => '1', 'language' => 0],
            'no language'        => ['table' => 'pages', 'uid' => 1],
            'a negative language' => ['table' => 'pages', 'uid' => 1, 'language' => -1],
            'a malformed table'  => ['table' => 'pages; drop', 'uid' => 1, 'language' => 0],
            'a zero uid'         => ['table' => 'pages', 'uid' => 0, 'language' => 0],
        ] as $case => $data) {
            self::assertNull(SubjectRecord::fromArray($data), $case);
        }
    }

    #[Test]
    public function anInvocationRoundTripsWithAndWithoutASubject(): void
    {
        $bare = new SkillInvocation(5);
        self::assertSame(['skill' => 5, 'subject' => null], $bare->toArray());
        self::assertEquals($bare, SkillInvocation::fromArray($bare->toArray()));

        $about = new SkillInvocation(5, new SubjectRecord(new RecordReference('pages', 3)));
        self::assertEquals($about, SkillInvocation::fromArray($about->toArray()));
    }

    #[Test]
    public function anInvocationRefusesANonPositiveSkillUid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1793500002);
        $invocation = new SkillInvocation(0);

        // S1848: the instantiation must be used; name what was accepted.
        self::fail('A non-positive skill uid was accepted: ' . $invocation->skillUid);
    }

    /**
     * A malformed subject makes the whole invocation unreadable rather than
     * an invocation about no record: the run would otherwise start without
     * the subject check the caller asked for.
     */
    #[Test]
    public function aMalformedInvocationReadsAsUnreadable(): void
    {
        foreach ([
            'not an array'        => 5,
            'a string skill'      => ['skill' => '5', 'subject' => null],
            'a zero skill'        => ['skill' => 0, 'subject' => null],
            'a malformed subject' => ['skill' => 5, 'subject' => ['table' => 'pages']],
        ] as $case => $data) {
            self::assertNull(SkillInvocation::fromArray($data), $case);
        }
    }

    #[Test]
    public function aLineageRoundTripsAndReleasesItsProcess(): void
    {
        $pin     = new SkillPin(5, 10, str_repeat('a', 64));
        $subject = new SubjectRecord(new RecordReference('pages', 1));
        $lineage = new RunLineage(30, $pin, $subject, processSkill: 'tour');

        self::assertTrue($lineage->holdsProcess());
        self::assertEquals($lineage, RunLineage::fromArray($lineage->toArray()));

        $released = $lineage->withReleasedProcess();
        self::assertFalse($released->holdsProcess());
        self::assertTrue($released->released);
        self::assertEquals($pin, $released->processPin, 'the released pin stays on record');
        self::assertSame('tour', $released->processSkill);
        self::assertEquals($released, RunLineage::fromArray($released->toArray()));

        self::assertFalse((new RunLineage(30))->holdsProcess());
    }

    #[Test]
    public function aMalformedLineageReadsAsUnreadable(): void
    {
        $valid = (new RunLineage(30, new SkillPin(5, 10, str_repeat('a', 64))))->toArray();

        foreach ([
            'not an array'           => 'lineage',
            'a string configuration' => ['configuration' => '30'] + $valid,
            'no released flag'       => array_diff_key($valid, ['released' => true]),
            'a malformed pin'        => ['processPin' => ['skill' => 'x']] + $valid,
            'a malformed subject'    => ['subject' => ['table' => 'pages']] + $valid,
            'a non-string skill'     => ['processSkill' => 5] + $valid,
            'no skill'               => array_diff_key($valid, ['processSkill' => true]),
        ] as $case => $data) {
            self::assertNull(RunLineage::fromArray($data), $case);
        }
    }

    /**
     * The run status carries the process state the runtime read (ADR-228):
     * the skill only while the run holds the process, and an unknown state
     * as null. Every other member is carried over unchanged.
     */
    #[Test]
    public function aRunStatusCarriesItsProcessState(): void
    {
        $run = new AgentRun(
            uid: 1,
            uuid: 'run',
            status: 'completed',
            configurationUid: 30,
            configurationIdentifier: 'cfg',
            beUser: 2,
            iterations: 1,
            truncated: false,
            totalPromptTokens: 1,
            totalCompletionTokens: 1,
            totalTokens: 2,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: 'completed',
            startedAt: 1,
            finishedAt: 2,
            crdate: 1,
            requeueCount: 3,
        );

        self::assertNull($run->holdsProcess, 'not read yet');

        $holding = $run->withProcessState(true, 'tour');
        self::assertTrue($holding->holdsProcess);
        self::assertSame('tour', $holding->processSkill);
        self::assertSame(3, $holding->requeueCount);
        self::assertTrue($holding->withoutSuspendedState()->holdsProcess, 'the status projection keeps it');

        self::assertSame('', $run->withProcessState(false, 'tour')->processSkill, 'no skill without a process');
        self::assertNull($run->withProcessState(null, 'tour')->holdsProcess);
        self::assertSame('', $run->withProcessState(null, 'tour')->processSkill);
    }
}

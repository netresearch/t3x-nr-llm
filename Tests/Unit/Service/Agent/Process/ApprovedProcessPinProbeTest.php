<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\Process;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\SkillApproval;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Service\Agent\Process\ApprovedProcessPinProbe;
use Netresearch\NrLlm\Service\Skill\SkillApprovalRepositoryInterface;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A process pin is a pin whose approved version is a process (ADR-214 items
 * 6 and 9), read from the waiting run's stored state and the approval
 * snapshot of the pin's digest.
 */
#[CoversClass(ApprovedProcessPinProbe::class)]
final class ApprovedProcessPinProbeTest extends TestCase
{
    private const SKILL = 4;

    private const SOURCE = 2;

    private const DIGEST = 'sha256:process';

    private InMemorySkillApprovalRepository $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvals = new InMemorySkillApprovalRepository();
    }

    #[Test]
    public function aWaitingRunWhosePinIsApprovedAsAProcessHoldsAProcessPin(): void
    {
        $this->approve(process: true);
        self::assertTrue($this->probe()->holdsProcessPin($this->storedRun(json_encode($this->state([$this->pin()])->toArray()))));
    }

    /**
     * The other direction, and every run that cannot hold one.
     */
    #[Test]
    public function aRunHoldsNoProcessPinUnlessItsStoredPinsSaySo(): void
    {
        $this->approve(process: false);
        $process = json_encode($this->state([$this->pin()])->toArray());

        foreach ([
            'a pin approved as no process' => $this->storedRun($process),
            'no pins at all'               => $this->storedRun(json_encode($this->state([])->toArray())),
            'not suspended'                => $this->storedRun(null),
            'an empty state'               => $this->storedRun(''),
            'an unreadable state'          => $this->storedRun('not-json{'),
        ] as $case => $run) {
            self::assertFalse($this->probe()->holdsProcessPin($run), $case);
        }
    }

    /**
     * A revocation stops the run at its next resume; until then the version
     * it was approved as still decides.
     */
    #[Test]
    public function aRevokedProcessApprovalStillCounts(): void
    {
        $this->approve(process: true);
        $this->approvals->revoke(self::SKILL, self::DIGEST, 1);

        self::assertTrue($this->probe()->anyProcessPin([$this->pin()]));
    }

    /**
     * Fail-closed: a pin nothing approved, or a lookup that fails, counts as a
     * process pin, so the stricter rules apply.
     */
    #[Test]
    public function aPinWhoseApprovalCannotBeReadCountsAsAProcessPin(): void
    {
        $this->approve(process: false);

        self::assertTrue($this->probe()->anyProcessPin([new SkillPin(self::SKILL, self::SOURCE, 'sha256:other')]), 'another digest');
        self::assertTrue($this->probe()->anyProcessPin([new SkillPin(self::SKILL, 9, self::DIGEST)]), 'another source');

        $failing = self::createStub(SkillApprovalRepositoryInterface::class);
        $failing->method('findBySkill')->willThrowException(new RuntimeException('down', 1791602001));
        self::assertTrue((new ApprovedProcessPinProbe($failing))->anyProcessPin([$this->pin()]), 'a failing lookup');

        self::assertFalse($this->probe()->anyProcessPin([]), 'no pins');
        self::assertFalse($this->probe()->anyProcessPin([$this->pin()]), 'the approved non-process version');
    }

    private function approve(bool $process): void
    {
        $this->approvals->add(self::SKILL, self::SOURCE, self::DIGEST, [
            'name'           => 'Tour',
            'description'    => '',
            'body'           => 'Walk through the page.',
            'support_status' => 'full',
            'allowed_tools'  => null,
            'process'        => $process,
        ], 'verified', 1);
        self::assertInstanceOf(SkillApproval::class, $this->approvals->findUnrevoked(self::SKILL, self::SOURCE, self::DIGEST));
    }

    private function pin(): SkillPin
    {
        return new SkillPin(self::SKILL, self::SOURCE, self::DIGEST);
    }

    /**
     * @param list<SkillPin> $pins
     */
    private function state(array $pins): SuspendedRunState
    {
        return (new SuspendedRunState([], [], 1, 0, 0))->withSkillPins($pins);
    }

    private function storedRun(string|false|null $state): AgentRun
    {
        return new AgentRun(
            uid: 1,
            uuid: 'run-1',
            status: 'waiting_for_approval',
            configurationUid: 1,
            configurationIdentifier: 'cfg',
            beUser: 9,
            iterations: 1,
            truncated: false,
            totalPromptTokens: 0,
            totalCompletionTokens: 0,
            totalTokens: 0,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: 0,
            crdate: 0,
            suspendedState: $state === false ? null : $state,
        );
    }

    private function probe(): ApprovedProcessPinProbe
    {
        return new ApprovedProcessPinProbe($this->approvals);
    }
}

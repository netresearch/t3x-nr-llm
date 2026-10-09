<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Exception\SkillInstructionWithdrawnException;
use Netresearch\NrLlm\Service\Skill\SkillComposerFactory;
use Netresearch\NrLlm\Service\Skill\SkillPinCheck;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillRecordLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The pin rules of ADR-214 item 6, each in both directions: a pin that holds
 * passes, and each single condition that breaks it stops the run.
 */
#[CoversClass(SkillPinCheck::class)]
#[CoversClass(SkillInstructionWithdrawnException::class)]
final class SkillPinCheckTest extends TestCase
{
    private const SKILL  = 7;

    private const SOURCE = 3;

    private InMemorySkillApprovalRepository $approvals;

    private FixedSkillSourceLookup $sources;

    private FixedSkillRecordLookup $records;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvals = new InMemorySkillApprovalRepository();
        $this->sources   = new FixedSkillSourceLookup([self::SOURCE => SkillTrustLevel::VERIFIED]);
        $this->records   = new FixedSkillRecordLookup([self::SKILL]);
    }

    #[Test]
    public function aPinWhoseApprovalSourceAndRecordAllHoldPasses(): void
    {
        $pin = $this->approvedPin();

        self::assertNull($this->check()->failure($pin));
        $this->check()->assertHeld([$pin]);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function noPinsMeansNothingToCheck(): void
    {
        $this->check()->assertHeld([]);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function aRevokedApprovalBreaksThePin(): void
    {
        $pin = $this->approvedPin();
        $this->approvals->revoke(self::SKILL, $pin->versionDigest, 1);

        self::assertSame('its approval was revoked', $this->check()->failure($pin));
    }

    #[Test]
    public function anApprovalOfAnotherDigestDoesNotHoldThePin(): void
    {
        $this->approvedPin();
        $other = new SkillPin(self::SKILL, self::SOURCE, SkillVersionDigest::ofFields($this->fields('other body')));

        self::assertSame('its approval was revoked', $this->check()->failure($other));
    }

    #[Test]
    public function anApprovalFromAnotherSourceDoesNotHoldThePin(): void
    {
        $pin   = $this->approvedPin();
        $moved = new SkillPin(self::SKILL, self::SOURCE + 1, $pin->versionDigest);
        $this->sources->levels[self::SOURCE + 1] = SkillTrustLevel::FIRST_PARTY;

        self::assertSame('its approval was revoked', $this->check()->failure($moved));
    }

    #[Test]
    public function aSnapshotThatNoLongerHashesToItsDigestBreaksThePin(): void
    {
        $digest = SkillVersionDigest::ofFields($this->fields('approved body'));
        // The row claims the digest but holds other text — a tampered snapshot.
        $this->approvals->add(self::SKILL, self::SOURCE, $digest, $this->fields('tampered body'), 'verified', 1);

        self::assertSame(
            'its approved text no longer matches its version digest',
            $this->check()->failure(new SkillPin(self::SKILL, self::SOURCE, $digest)),
        );
    }

    #[Test]
    public function aDeletedOrOrphanedSkillBreaksThePin(): void
    {
        $pin                    = $this->approvedPin();
        $this->records->present = [];

        self::assertSame('the skill was deleted, disabled or orphaned', $this->check()->failure($pin));
    }

    #[Test]
    public function aPinStandingInForADamagedEntryNeverHolds(): void
    {
        $this->approvedPin();

        self::assertNotNull($this->check()->failure(new SkillPin(0, 0, '')));
    }

    #[Test]
    public function aSourceDowngradedBelowTheThresholdBreaksThePin(): void
    {
        $pin                                 = $this->approvedPin();
        $this->sources->levels[self::SOURCE] = SkillTrustLevel::COMMUNITY;

        self::assertSame('its source is no longer trusted for instructions', $this->check()->failure($pin));
    }

    #[Test]
    public function aSourceThatIsGoneBreaksThePin(): void
    {
        $pin                    = $this->approvedPin();
        $this->sources->levels  = [];

        self::assertSame('its source is no longer trusted for instructions', $this->check()->failure($pin));
    }

    #[Test]
    public function aSourceAboveTheThresholdKeepsThePin(): void
    {
        $pin                                 = $this->approvedPin();
        $this->sources->levels[self::SOURCE] = SkillTrustLevel::FIRST_PARTY;

        self::assertNull($this->check()->failure($pin));
    }

    #[Test]
    public function theThresholdIsTheConfiguredInstructionLevel(): void
    {
        $pin                                 = $this->approvedPin();
        $this->sources->levels[self::SOURCE] = SkillTrustLevel::COMMUNITY;

        self::assertNull($this->check(['instructionTrustLevel' => 'community'])->failure($pin));
    }

    #[Test]
    public function assertHeldStopsAtTheFirstBrokenPinAndNamesSkillAndReason(): void
    {
        $held    = $this->approvedPin();
        $revoked = new SkillPin(self::SKILL, self::SOURCE, $held->versionDigest);
        $this->approvals->revoke(self::SKILL, $held->versionDigest, 1);

        try {
            $this->check()->assertHeld([$revoked]);
            self::fail('A revoked pin must stop the run.');
        } catch (SkillInstructionWithdrawnException $e) {
            self::assertSame($revoked, $e->pin);
            self::assertSame('Release notes', $e->skillName);
            self::assertSame('its approval was revoked', $e->reason);
            self::assertStringContainsString('"Release notes"', $e->getMessage());
            self::assertStringContainsString('its approval was revoked', $e->getMessage());
            self::assertSame(1791500101, $e->getCode());
        }
    }

    /**
     * @param array<string, mixed> $skillsConfig
     */
    private function check(array $skillsConfig = []): SkillPinCheck
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['skills' => $skillsConfig]);

        return new SkillPinCheck(
            $this->approvals,
            $this->sources,
            $this->records,
            new SkillComposerFactory($extensionConfiguration),
        );
    }

    private function approvedPin(): SkillPin
    {
        $fields = $this->fields('approved body');
        $digest = SkillVersionDigest::ofFields($fields);
        $this->approvals->add(self::SKILL, self::SOURCE, $digest, $fields, 'verified', 1);

        return new SkillPin(self::SKILL, self::SOURCE, $digest);
    }

    /**
     * @return array{name: string, description: string, body: string, support_status: string, allowed_tools: list<string>|null, process: bool}
     */
    private function fields(string $body): array
    {
        return [
            'name'           => 'Release notes',
            'description'    => 'Writes release notes',
            'body'           => $body,
            'support_status' => 'full',
            'allowed_tools'  => null,
            'process'        => false,
        ];
    }
}

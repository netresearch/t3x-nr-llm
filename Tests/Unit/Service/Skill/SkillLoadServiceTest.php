<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Domain\ValueObject\SkillCatalogue;
use Netresearch\NrLlm\Domain\ValueObject\SkillPin;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillLoadService;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillLoadLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue and the load decision (ADR-214 item 4): every condition of
 * the catalogue is re-checked at the moment of the load, a load is
 * idempotent per skill and digest, and the result never carries the body.
 */
#[CoversClass(SkillLoadService::class)]
#[CoversClass(SkillComposer::class)]
final class SkillLoadServiceTest extends TestCase
{
    private const CONFIGURATION_UID = 9;

    private const BODY = 'Answer in the house style: short sentences.';

    private InMemorySkillApprovalRepository $approvals;

    private FixedSkillLoadLookup $lookup;

    protected function setUp(): void
    {
        $this->approvals = new InMemorySkillApprovalRepository();
        $this->lookup    = new FixedSkillLoadLookup();
    }

    #[Test]
    public function theCatalogueListsAnApprovedOnDemandInstructionByUidNameAndDescription(): void
    {
        $skill = $this->approvedOnDemand(7);

        $catalogue = $this->service()->catalogue($this->configuration($skill));

        self::assertCount(1, $catalogue->entries);
        self::assertSame(7, $catalogue->entries[0]->skillUid);
        self::assertSame('House style', $catalogue->entries[0]->name);
        self::assertSame('How we write.', $catalogue->entries[0]->description);
        self::assertSame($skill->getVersionDigest(), $catalogue->entries[0]->versionDigest);
        self::assertStringNotContainsString(self::BODY, $catalogue->describe(), 'the catalogue never carries a body');
    }

    #[Test]
    public function theCatalogueLeavesOutAnUnapprovedAProcessADisabledAndAnAlwaysSkill(): void
    {
        $unapproved = $this->skill(1);
        $process    = $this->skill(2);
        $process->setProcess(true);
        $process->setVersionDigest(SkillVersionDigest::of($process));
        $this->approve($process);
        $disabled = $this->approvedOnDemand(3);
        $disabled->setEnabled(false);

        $always = $this->approvedOnDemand(4);

        $configuration = $this->configuration($unapproved, $process, $disabled);
        $configuration->addSkill($always);

        self::assertTrue($this->service()->catalogue($configuration)->isEmpty());
    }

    #[Test]
    public function aLoadOfACatalogueSkillReturnsTheSectionAndItsPinButAResultWithoutTheBody(): void
    {
        $skill         = $this->approvedOnDemand(7);
        $configuration = $this->configuration($skill);

        $outcome = $this->service()->load(7, $this->service()->catalogue($configuration), [], $configuration);

        self::assertFalse($outcome->isError);
        self::assertTrue($outcome->appendsSection());
        self::assertStringContainsString(self::BODY, $outcome->section);
        self::assertStringNotContainsString(self::BODY, $outcome->message);
        self::assertEquals(new SkillPin(7, 1, $skill->getVersionDigest()), $outcome->pin);
    }

    #[Test]
    public function aUidOutsideTheCatalogueIsRefused(): void
    {
        $skill         = $this->approvedOnDemand(7);
        $configuration = $this->configuration($skill);

        $outcome = $this->service()->load(8, $this->service()->catalogue($configuration), [], $configuration);

        self::assertTrue($outcome->isError);
        self::assertFalse($outcome->appendsSection());
    }

    /**
     * The catalogue is the run's, not the configuration's: a skill attached
     * and approved since the run started, but not in its catalogue, is
     * refused although every other condition holds.
     */
    #[Test]
    public function anOtherwiseLoadableSkillOutsideTheRunsCatalogueIsRefused(): void
    {
        $configuration = $this->configuration($this->approvedOnDemand(8));

        $outcome = $this->service()->load(8, new SkillCatalogue(), [], $configuration);

        self::assertTrue($outcome->isError);
        self::assertFalse($outcome->appendsSection());
        self::assertTrue($this->service()->load(8, $this->service()->catalogue($configuration), [], $configuration)->appendsSection(), 'the same skill loads from a catalogue that lists it');
    }

    #[Test]
    public function aSkillHeldAtTheSameDigestIsAlreadyLoadedAndAppendsNothing(): void
    {
        $skill         = $this->approvedOnDemand(7);
        $configuration = $this->configuration($skill);
        $held          = [new SkillPin(7, 1, $skill->getVersionDigest())];

        $outcome = $this->service()->load(7, $this->service()->catalogue($configuration), $held, $configuration);

        self::assertFalse($outcome->isError);
        self::assertFalse($outcome->appendsSection());
        self::assertStringContainsString('already loaded', $outcome->message);
    }

    #[Test]
    public function aSkillHeldAtAnotherDigestIsRefusedAndKeepsItsVersion(): void
    {
        $skill         = $this->approvedOnDemand(7);
        $configuration = $this->configuration($skill);
        $held          = [new SkillPin(7, 1, '1:' . str_repeat('a', 64))];

        $outcome = $this->service()->load(7, $this->service()->catalogue($configuration), $held, $configuration);

        self::assertTrue($outcome->isError);
        self::assertFalse($outcome->appendsSection());
    }

    #[Test]
    public function aSkillDetachedSinceTheCatalogueWasBuiltIsRefused(): void
    {
        $skill         = $this->approvedOnDemand(7);
        $configuration = $this->configuration($skill);
        $catalogue     = $this->service()->catalogue($configuration);
        $this->lookup->onDemand = [];

        $outcome = $this->service()->load(7, $catalogue, [], $configuration);

        self::assertTrue($outcome->isError);
        self::assertFalse($outcome->appendsSection());
    }

    #[Test]
    public function aSkillWhoseStoredDigestChangedOrThatWasDisabledSinceIsRefused(): void
    {
        $skill         = $this->approvedOnDemand(7);
        $configuration = $this->configuration($skill);
        $catalogue     = $this->service()->catalogue($configuration);

        $this->lookup->digests[7] = '1:' . str_repeat('b', 64);
        self::assertTrue($this->service()->load(7, $catalogue, [], $configuration)->isError, 'a re-synced record');

        unset($this->lookup->digests[7]);
        self::assertTrue($this->service()->load(7, $catalogue, [], $configuration)->isError, 'a disabled, hidden, orphaned or process record');
    }

    #[Test]
    public function aVersionRevokedSinceTheCatalogueWasBuiltIsRefused(): void
    {
        $skill         = $this->approvedOnDemand(7);
        $configuration = $this->configuration($skill);
        $catalogue     = $this->service()->catalogue($configuration);
        $this->approvals->revoke(7, $skill->getVersionDigest(), 1);

        $outcome = $this->service()->load(7, $catalogue, [], $configuration);

        self::assertTrue($outcome->isError);
        self::assertFalse($outcome->appendsSection());
    }

    #[Test]
    public function aSectionJoinsTheApprovedSkillsHeadingOnceAndOtherwiseBringsIt(): void
    {
        $first = SkillComposer::appendInstructionSection('Identity.', "### Skill: A\nbody a\n");
        self::assertStringStartsWith("Identity.\n\n## Approved skills\n", $first);

        $second = SkillComposer::appendInstructionSection($first, "### Skill: B\nbody b\n");
        self::assertSame(1, substr_count($second, '## Approved skills'));
        self::assertStringEndsWith("body a\n\n### Skill: B\nbody b", $second);
    }

    private function service(): SkillLoadService
    {
        return new SkillLoadService(
            new SkillComposer(
                SkillComposer::DEFAULT_MAX_BYTES,
                SkillTrustLevel::UNTRUSTED,
                new SkillInstructionPolicy($this->approvals, new FixedSkillSourceLookup([1 => SkillTrustLevel::VERIFIED]), SkillTrustLevel::VERIFIED),
            ),
            $this->lookup,
        );
    }

    private function configuration(Skill ...$onDemand): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', self::CONFIGURATION_UID);
        foreach ($onDemand as $skill) {
            $configuration->addSkillOnDemand($skill);
        }

        return $configuration;
    }

    private function approvedOnDemand(int $uid): Skill
    {
        $skill = $this->skill($uid);
        $this->approve($skill);

        return $skill;
    }

    private function approve(Skill $skill): void
    {
        $uid = (int)$skill->getUid();
        $this->approvals->add($uid, 1, $skill->getVersionDigest(), SkillVersionDigest::fieldsOf($skill), 'verified', 1);
        $this->lookup->onDemand[self::CONFIGURATION_UID][] = $uid;
        $this->lookup->digests[$uid]                       = $skill->getVersionDigest();
    }

    private function skill(int $uid): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', $uid);
        $skill->setSource(1);
        $skill->setIdentifier('style-' . $uid);
        $skill->setName('House style');
        $skill->setDescription('How we write.');
        $skill->setBody(self::BODY);
        $skill->setBodyChecksum(hash('sha256', self::BODY));
        $skill->setSupportStatus('full');
        $skill->setEnabled(true);
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }
}

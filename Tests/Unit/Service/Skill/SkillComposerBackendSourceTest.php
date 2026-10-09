<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A skill authored in the backend (ADR-214 item 3): admitted by its source
 * record's trust level, verified by the digest of its current fields, and an
 * instruction only while an approval names that computed digest.
 */
#[CoversClass(SkillComposer::class)]
final class SkillComposerBackendSourceTest extends TestCase
{
    private const BACKEND = 20;

    private const SYNCED = 21;

    private InMemorySkillApprovalRepository $approvals;

    private FixedSkillSourceLookup $sources;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvals = new InMemorySkillApprovalRepository();
        $this->sources   = new FixedSkillSourceLookup(
            [self::BACKEND => SkillTrustLevel::VERIFIED, self::SYNCED => SkillTrustLevel::VERIFIED],
            [self::BACKEND => SkillSourceType::BACKEND],
        );
    }

    #[Test]
    public function aBackendSkillIsAdmittedByItsSourcesLevelNotByTheUntrustedDefaultOfItsOwnColumn(): void
    {
        $skill = $this->backendSkill('Guide', 'House style.');

        $result = $this->composer(SkillTrustLevel::VERIFIED)->composeBlock([$skill], []);

        self::assertSame(['backend-1'], $result->included);
    }

    #[Test]
    public function aBackendSkillOfASourceBelowTheMinimumIsNotAdmitted(): void
    {
        $this->sources->levels[self::BACKEND] = SkillTrustLevel::COMMUNITY;

        $result = $this->composer(SkillTrustLevel::VERIFIED)->composeBlock([$this->backendSkill('Guide', 'House style.')], []);

        self::assertSame([], $result->included);
    }

    #[Test]
    public function aSyncedSkillIsStillAdmittedByItsDenormalisedColumn(): void
    {
        $skill = $this->backendSkill('Guide', 'House style.');
        $skill->setSource(self::SYNCED);
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        self::assertSame([], $this->composer(SkillTrustLevel::VERIFIED)->composeBlock([$skill], [])->included);

        $skill->setTrustLevel(SkillTrustLevel::VERIFIED->value);

        self::assertSame(['backend-1'], $this->composer(SkillTrustLevel::VERIFIED)->composeBlock([$skill], [])->included);
    }

    #[Test]
    public function anEditedBackendSkillIsComposedAndNotSkippedAsTampered(): void
    {
        $skill = $this->backendSkill('Guide', 'House style.');
        $skill->setBody('Edited in the backend.');

        $result = $this->composer()->composeBlock([$skill], []);

        self::assertSame([], $result->warnings);
        self::assertStringContainsString('Edited in the backend.', $result->block);
    }

    #[Test]
    public function anApprovedBackendVersionInstructsAndAnEditTakesItOutUntilApprovedAgain(): void
    {
        $skill = $this->backendSkill('Guide', 'House style.');
        $this->approvals->add(1, self::BACKEND, SkillVersionDigest::of($skill), SkillVersionDigest::fieldsOf($skill), 'verified', 1);

        self::assertStringContainsString('House style.', $this->composer()->composeBlock([$skill], [])->instructions);

        $skill->setBody('Edited after the approval.');
        $result = $this->composer()->composeBlock([$skill], []);

        self::assertSame('', $result->instructions);
        self::assertStringContainsString('Edited after the approval.', $result->block);
    }

    /**
     * A synced skill with the same empty stored digest is a legacy row, and
     * a legacy row never instructs; a backend skill never stores one.
     */
    #[Test]
    public function aBackendSkillWithoutAStoredDigestIsNotALegacyRow(): void
    {
        $skill = $this->backendSkill('Guide', 'House style.');
        self::assertSame('', $skill->getVersionDigest());
        $this->approvals->add(1, self::BACKEND, SkillVersionDigest::of($skill), SkillVersionDigest::fieldsOf($skill), 'verified', 1);

        self::assertNotSame('', $this->composer()->composeBlock([$skill], [])->instructions);

        $skill->setSource(self::SYNCED);
        $this->approvals->add(1, self::SYNCED, SkillVersionDigest::of($skill), SkillVersionDigest::fieldsOf($skill), 'verified', 1);

        self::assertSame('', $this->composer()->composeBlock([$skill], [])->instructions);
    }

    #[Test]
    public function anUnapprovedBackendSkillDeclaresTheEmptyListWhateverItsFieldSays(): void
    {
        $skill = $this->backendSkill('Guide', 'House style.');
        $skill->setAllowedTools('["delete_record"]');

        self::assertSame([], $this->composer()->declaredTools($skill));

        $skill->setAllowedTools('');

        self::assertSame([], $this->composer()->declaredTools($skill), 'no declaration in the field does not lift the restriction either');
    }

    #[Test]
    public function aBackendSkillDeclaresTheToolsOfItsLatestApprovedVersionNotTheLiveField(): void
    {
        $skill = $this->backendSkill('Guide', 'House style.');
        $skill->setAllowedTools('["get_page"]');

        $this->approvals->add(1, self::BACKEND, SkillVersionDigest::of($skill), SkillVersionDigest::fieldsOf($skill), 'verified', 1);

        $skill->setAllowedTools('["get_page","delete_record"]');

        self::assertSame(['get_page'], $this->composer()->declaredTools($skill));
    }

    #[Test]
    public function anApprovalFromAnotherSourceGrantsABackendSkillNothing(): void
    {
        $skill = $this->backendSkill('Guide', 'House style.');
        $skill->setAllowedTools('["get_page"]');

        $this->approvals->add(1, self::SYNCED, SkillVersionDigest::of($skill), SkillVersionDigest::fieldsOf($skill), 'verified', 1);

        self::assertSame([], $this->composer()->declaredTools($skill));
    }

    #[Test]
    public function aRevokedApprovalGrantsABackendSkillNothing(): void
    {
        $skill = $this->backendSkill('Guide', 'House style.');
        $skill->setAllowedTools('["get_page"]');

        $this->approvals->add(1, self::BACKEND, SkillVersionDigest::of($skill), SkillVersionDigest::fieldsOf($skill), 'verified', 1);
        $this->approvals->revoke(1, SkillVersionDigest::of($skill), 1);

        self::assertSame([], $this->composer()->declaredTools($skill));
    }

    #[Test]
    public function aSyncedSkillDeclaresItsStoredField(): void
    {
        $skill = $this->syncedSkill('["get_page"]');

        self::assertSame(['get_page'], $this->composer()->declaredTools($skill));

        $skill = $this->syncedSkill('');

        self::assertNull($this->composer()->declaredTools($skill));
    }

    /**
     * A synced skill edited after the sync is skipped by compose; its widened
     * field must not widen the run either.
     */
    #[Test]
    public function aSyncedSkillThatFailsItsIntegrityCheckDeclaresTheEmptyList(): void
    {
        $skill = $this->syncedSkill('["get_page"]');
        $skill->setAllowedTools('["get_page","delete_record"]');

        self::assertSame([], $this->composer()->declaredTools($skill));
    }

    #[Test]
    public function aProcessSkillDeclaresTheEmptyListOnTheAttachedPath(): void
    {
        $skill = $this->syncedSkill('["get_page"]', process: true);

        self::assertSame([], $this->composer()->declaredTools($skill));
    }

    /**
     * Missing, hidden and disabled sources all read as "no source" through the
     * lookup; none vouches for a declaration.
     */
    #[Test]
    public function aSkillWithoutAnActiveSourceDeclaresTheEmptyList(): void
    {
        $skill = $this->syncedSkill('["get_page"]');
        unset($this->sources->levels[self::SYNCED]);

        self::assertSame([], $this->composer()->declaredTools($skill));

        $backend = $this->backendSkill('Guide', 'House style.');
        $backend->setAllowedTools('["get_page"]');

        $this->approvals->add(1, self::BACKEND, SkillVersionDigest::of($backend), SkillVersionDigest::fieldsOf($backend), 'verified', 1);
        unset($this->sources->levels[self::BACKEND]);

        self::assertSame([], $this->composer()->declaredTools($backend), 'an approval does not help a skill whose source is gone');
    }

    #[Test]
    public function aComposerWithoutASourceLookupKeepsTheStoredField(): void
    {
        $skill = $this->backendSkill('Guide', 'House style.');
        $skill->setAllowedTools('["get_page"]');

        self::assertSame(['get_page'], (new SkillComposer())->declaredTools($skill));
    }

    private function syncedSkill(string $tools, bool $process = false): Skill
    {
        $skill = $this->backendSkill('Guide', 'House style.');
        $skill->setSource(self::SYNCED);
        $skill->setAllowedTools($tools);
        $skill->setProcess($process);
        $skill->setBodyChecksum(hash('sha256', 'House style.'));
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }

    private function composer(SkillTrustLevel $minimum = SkillTrustLevel::UNTRUSTED): SkillComposer
    {
        return new SkillComposer(
            SkillComposer::DEFAULT_MAX_BYTES,
            $minimum,
            new SkillInstructionPolicy($this->approvals, $this->sources, SkillTrustLevel::VERIFIED),
            $this->sources,
        );
    }

    private function backendSkill(string $name, string $body): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', 1);
        $skill->setSource(self::BACKEND);
        $skill->setIdentifier('backend-1');
        $skill->setName($name);
        $skill->setDescription($name . ' description');
        $skill->setBody($body);
        $skill->setSupportStatus('full');
        $skill->setEnabled(true);

        return $skill;
    }
}

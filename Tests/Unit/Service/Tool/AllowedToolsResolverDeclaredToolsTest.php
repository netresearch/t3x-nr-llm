<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Skill\SkillInstructionPolicy;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\FixedSkillSourceLookup;
use Netresearch\NrLlm\Tests\Unit\Service\Skill\Fixture\InMemorySkillApprovalRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The run allow-list reads what a skill may declare, not its live field
 * (ADR-214 item 3): the resolver goes through
 * {@see SkillComposer::declaredTools()}, both for a configuration and for a
 * run's start-time list.
 */
#[CoversClass(AllowedToolsResolver::class)]
final class AllowedToolsResolverDeclaredToolsTest extends TestCase
{
    private const BACKEND = 20;

    private const SYNCED  = 21;

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

    /**
     * An author's unapproved edit grants nothing, and — being a declaration —
     * it also never leaves the run unrestricted.
     */
    #[Test]
    public function anUnapprovedBackendSkillRestrictsTheRunToNothing(): void
    {
        $skill = $this->backendSkill('["get_page","delete_record"]');

        self::assertSame([], $this->resolver()->resolve($this->configuration($skill)));
        self::assertSame([], $this->resolver()->resolveForRun($this->configuration(), [$skill])->toolNames);
    }

    #[Test]
    public function anApprovedBackendSkillGrantsItsApprovedToolsEvenAfterAWideningEdit(): void
    {
        $skill = $this->backendSkill('["get_page"]');
        $this->approvals->add(1, self::BACKEND, SkillVersionDigest::of($skill), SkillVersionDigest::fieldsOf($skill), 'verified', 1);
        $skill->setAllowedTools('["get_page","delete_record"]');

        self::assertSame(['get_page'], $this->resolver()->resolve($this->configuration($skill)));
        self::assertSame(['get_page'], $this->resolver()->resolveForRun($this->configuration($skill), [])->toolNames);
    }

    #[Test]
    public function aSyncedSkillEditedAfterTheSyncGrantsNothing(): void
    {
        $skill = $this->syncedSkill('["get_page"]');
        $skill->setAllowedTools('["get_page","delete_record"]');

        self::assertSame([], $this->resolver()->resolve($this->configuration($skill)));
    }

    #[Test]
    public function anIntactSyncedSkillGrantsItsStoredDeclaration(): void
    {
        self::assertSame(['get_page'], $this->resolver()->resolve($this->configuration($this->syncedSkill('["get_page"]'))));
        self::assertNull($this->resolver()->resolve($this->configuration($this->syncedSkill(''))), 'no declaration stays no opinion');
    }

    /**
     * ADR-214 item 5: an attached process skill the run does not invoke adds
     * neither tools nor a restriction. The normal skill alone decides, and
     * without any declaring skill the run is unrestricted.
     */
    #[Test]
    public function anAttachedProcessSkillThatIsNotInvokedContributesNothing(): void
    {
        $tour   = $this->processSkill('["update_content_element"]');
        $normal = $this->syncedSkill('["get_page"]');

        self::assertSame(['get_page'], $this->resolver()->resolveForRun($this->configuration($tour, $normal), [])->toolNames);
        self::assertSame(['get_page'], $this->resolver()->resolve($this->configuration($tour, $normal)));
        self::assertNull($this->resolver()->resolveForRun($this->configuration($tour, $this->syncedSkill('')), [])->toolNames, 'no declaring skill: no restriction');
        self::assertNull($this->resolver()->resolveForRun($this->configuration(), [$tour])->toolNames, 'forced is not invoked');
    }

    #[Test]
    public function aRunThatInvokesTheProcessSkillGetsItsTools(): void
    {
        $tour   = $this->processSkill('["update_content_element"]');
        $normal = $this->syncedSkill('["get_page"]');

        self::assertSame(
            ['update_content_element', 'get_page'],
            $this->resolver()->resolveForRun($this->configuration($tour, $normal), [], null, [$tour])->toolNames,
        );
    }

    /**
     * Fail closed: an invoked process skill whose declaration nothing vouches
     * for, or that declares nothing, restricts the run to nothing — never "no
     * opinion".
     */
    #[Test]
    public function anInvokedProcessSkillWithoutAVouchedDeclarationRestrictsTheRunToNothing(): void
    {
        $edited = $this->processSkill('["update_content_element"]');
        $edited->setAllowedTools('["update_content_element","delete_record"]');

        self::assertSame([], $this->resolver()->resolveForRun($this->configuration($edited), [], null, [$edited])->toolNames);

        $undeclared = $this->processSkill('');

        self::assertSame([], $this->resolver()->resolveForRun($this->configuration($undeclared), [], null, [$undeclared])->toolNames);
    }

    /**
     * The process marker of a backend skill is read from the approved
     * version, like its tools: switching it on in the form does not turn an
     * approved restriction into "no opinion".
     */
    #[Test]
    public function aBackendSkillSwitchedToProcessAfterApprovalKeepsItsApprovedRestriction(): void
    {
        $skill = $this->backendSkill('["get_page"]');
        $this->approvals->add(1, self::BACKEND, SkillVersionDigest::of($skill), SkillVersionDigest::fieldsOf($skill), 'verified', 1);
        $skill->setProcess(true);

        self::assertSame(['get_page'], $this->resolver()->resolve($this->configuration($skill)));
        self::assertSame(['get_page'], $this->resolver()->resolveForRun($this->configuration($skill), [])->toolNames);
    }

    /**
     * The other direction: an approved process version stays a process
     * version when the form switches the marker off, so a run that does not
     * invoke it does not get its tools.
     */
    #[Test]
    public function aBackendProcessSkillSwitchedOffAfterApprovalStillNeedsAnInvocation(): void
    {
        $tour = $this->backendSkill('["update_content_element"]');
        $tour->setProcess(true);

        $this->approvals->add(1, self::BACKEND, SkillVersionDigest::of($tour), SkillVersionDigest::fieldsOf($tour), 'verified', 1);
        $tour->setProcess(false);
        $normal = $this->syncedSkill('["get_page"]');

        self::assertSame(['get_page'], $this->resolver()->resolveForRun($this->configuration($tour, $normal), [])->toolNames);
        self::assertSame(
            ['update_content_element', 'get_page'],
            $this->resolver()->resolveForRun($this->configuration($tour, $normal), [], null, [$tour])->toolNames,
        );
    }

    /**
     * An invoked skill that is not effective (here: disabled) grants nothing
     * and still restricts the run; an effective one grants its tools.
     */
    #[Test]
    public function anInvokedSkillThatIsNotEffectiveRestrictsTheRunToNothing(): void
    {
        $tour = $this->processSkill('["update_content_element"]');
        $tour->setEnabled(false);

        self::assertSame([], $this->resolver()->resolveForRun($this->configuration(), [], null, [$tour])->toolNames);

        $tour->setEnabled(true);

        self::assertSame(['update_content_element'], $this->resolver()->resolveForRun($this->configuration(), [], null, [$tour])->toolNames);
    }

    /**
     * Renaming a skill's identifier onto another skill of the same source
     * changes no digest. The allow-list runs over every admitted record, so the
     * renamed process skill cannot shadow the restricting one; an invocation
     * still adds its tools.
     */
    #[Test]
    public function aTwinOnTheSameSourceCannotShadowARestrictingSkill(): void
    {
        $tour = $this->backendSkill('["update_content_element"]');
        $tour->_setProperty('uid', 7);
        $tour->setIdentifier('guide');
        $tour->setProcess(true);

        $this->approvals->add(7, self::BACKEND, SkillVersionDigest::of($tour), SkillVersionDigest::fieldsOf($tour), 'verified', 1);

        $guide = $this->backendSkill('["get_page"]');
        $guide->setIdentifier('guide');

        $this->approvals->add(1, self::BACKEND, SkillVersionDigest::of($guide), SkillVersionDigest::fieldsOf($guide), 'verified', 1);

        self::assertSame(['get_page'], $this->resolver()->resolveForRun($this->configuration($tour, $guide), [])->toolNames);
        self::assertSame(['get_page'], $this->resolver()->resolve($this->configuration($tour, $guide)));
        self::assertSame(
            ['update_content_element', 'get_page'],
            $this->resolver()->resolveForRun($this->configuration($tour, $guide), [], null, [$tour])->toolNames,
        );
    }

    /**
     * The sync orphans a record whose identifier it no longer finds, for
     * example after a rename. An enabled orphan still restricts the run; a
     * disabled skill is an administrator's decision and drops out.
     */
    #[Test]
    public function anOrphanedRestrictingSkillStillRestrictsTheRun(): void
    {
        $skill = $this->syncedSkill('["get_page"]');
        $skill->setOrphaned(true);

        self::assertSame([], $this->resolver()->resolveForRun($this->configuration($skill), [])->toolNames);
        self::assertSame([], $this->resolver()->resolve($this->configuration($skill)));

        $skill->setEnabled(false);

        self::assertNull($this->resolver()->resolveForRun($this->configuration($skill), [])->toolNames);
    }

    private function processSkill(string $tools): Skill
    {
        $skill = $this->backendSkill($tools);
        $skill->_setProperty('uid', 3);
        $skill->setSource(self::SYNCED);
        $skill->setIdentifier('tour-1');
        $skill->setProcess(true);
        $skill->setTrustLevel(SkillTrustLevel::VERIFIED->value);
        $skill->setBodyChecksum(hash('sha256', 'House style.'));
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }

    private function resolver(): AllowedToolsResolver
    {
        $composer = new SkillComposer(
            SkillComposer::DEFAULT_MAX_BYTES,
            SkillTrustLevel::UNTRUSTED,
            new SkillInstructionPolicy($this->approvals, $this->sources, SkillTrustLevel::VERIFIED),
            $this->sources,
        );

        return new AllowedToolsResolver($composer, new ToolRegistry([]));
    }

    private function configuration(Skill ...$skills): LlmConfiguration
    {
        $configuration = new LlmConfiguration();
        foreach ($skills as $skill) {
            $configuration->addSkill($skill);
        }

        return $configuration;
    }

    private function backendSkill(string $tools): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', 1);
        $skill->setSource(self::BACKEND);
        $skill->setIdentifier('backend-1');
        $skill->setName('Guide');
        $skill->setDescription('Guide description');
        $skill->setBody('House style.');
        $skill->setSupportStatus('full');
        $skill->setAllowedTools($tools);
        $skill->setEnabled(true);

        return $skill;
    }

    private function syncedSkill(string $tools): Skill
    {
        $skill = $this->backendSkill($tools);
        $skill->_setProperty('uid', 2);
        $skill->setSource(self::SYNCED);
        $skill->setIdentifier('synced-1');
        $skill->setTrustLevel(SkillTrustLevel::VERIFIED->value);
        $skill->setBodyChecksum(hash('sha256', 'House style.'));
        $skill->setVersionDigest(SkillVersionDigest::of($skill));

        return $skill;
    }
}

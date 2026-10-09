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

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SkillSourceType;
use Netresearch\NrLlm\Domain\Enum\SkillTrustLevel;
use Netresearch\NrLlm\Domain\ValueObject\SkillSourceFacts;
use Netresearch\NrLlm\Service\Skill\SkillRecordLookup;
use Netresearch\NrLlm\Service\Skill\SkillSourceLookup;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The two stored facts the pin rules read now rather than from an object the
 * worker loaded earlier (ADR-214 items 2 and 6): what a source vouches for, and
 * whether a skill record still exists.
 */
#[CoversClass(SkillSourceLookup::class)]
#[CoversClass(SkillRecordLookup::class)]
final class SkillPinLookupTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function anActiveSourceVouchesWithItsStoredLevel(): void
    {
        $this->source(10);

        $facts = (new SkillSourceLookup($this->pool()))->find(10);

        self::assertInstanceOf(SkillSourceFacts::class, $facts);
        self::assertSame(SkillTrustLevel::VERIFIED, $facts->trustLevel);
    }

    #[Test]
    public function aHiddenSourceVouchesForNothing(): void
    {
        $this->source(10, ['hidden' => 1]);

        self::assertNull((new SkillSourceLookup($this->pool()))->find(10));
    }

    #[Test]
    public function aDisabledSourceVouchesForNothing(): void
    {
        $this->source(10, ['enabled' => 0]);

        self::assertNull((new SkillSourceLookup($this->pool()))->find(10));
    }

    #[Test]
    public function aDeletedSourceVouchesForNothing(): void
    {
        $this->source(10, ['deleted' => 1]);

        self::assertNull((new SkillSourceLookup($this->pool()))->find(10));
    }

    #[Test]
    public function anExistingSkillRecordIsFound(): void
    {
        $this->skillRecord(5);

        self::assertTrue((new SkillRecordLookup($this->pool()))->isActive(5));
    }

    #[Test]
    public function anOrphanedDeletedOrMissingSkillRecordIsNot(): void
    {
        $this->skillRecord(5, ['orphaned' => 1]);
        $this->skillRecord(6, ['deleted' => 1]);
        $lookup = new SkillRecordLookup($this->pool());

        self::assertFalse($lookup->isActive(5));
        self::assertFalse($lookup->isActive(6));
        self::assertFalse($lookup->isActive(7));
        self::assertFalse($lookup->isActive(0));
    }

    /**
     * A skill the sync auto-disabled or the injection scan force-disabled must
     * stop steering a suspended run, as must a hidden one.
     */
    #[Test]
    public function aDisabledOrHiddenSkillRecordIsNotActive(): void
    {
        $this->skillRecord(5, ['enabled' => 0]);
        $this->skillRecord(6, ['hidden' => 1]);
        $lookup = new SkillRecordLookup($this->pool());

        self::assertFalse($lookup->isActive(5));
        self::assertFalse($lookup->isActive(6));
    }

    /**
     * @param array<string, int> $overrides
     */
    private function source(int $uid, array $overrides = []): void
    {
        $this->pool()->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', $overrides + [
            'uid'         => $uid,
            'title'       => 'House skills',
            'type'        => 'repo',
            'trust_level' => 'verified',
        ]);
    }

    /**
     * @param array<string, int> $overrides
     */
    private function skillRecord(int $uid, array $overrides = []): void
    {
        $this->pool()->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', $overrides + [
            'uid'        => $uid,
            'source'     => 10,
            'identifier' => 'skill-' . $uid,
            'name'       => 'Skill ' . $uid,
            'enabled'    => 1,
        ]);
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }

    #[Test]
    public function sourceLookupReturnsExactlyTheRequestedUidAndType(): void
    {
        $this->source(10);
        $this->source(20);
        $connection = $this->pool()->getConnectionForTable('tx_nrllm_skill_source');
        $connection->update(
            'tx_nrllm_skill_source',
            ['type' => 'single_file', 'trust_level' => 'community'],
            ['uid' => 10],
        );
        $connection->update(
            'tx_nrllm_skill_source',
            ['type' => 'marketplace', 'trust_level' => 'first_party'],
            ['uid' => 20],
        );
        $lookup = new SkillSourceLookup($this->pool());
        $facts = $lookup->find(20);
        self::assertInstanceOf(SkillSourceFacts::class, $facts);
        self::assertSame(20, $facts->uid);
        self::assertSame(SkillSourceType::MARKETPLACE, $facts->type);
        self::assertSame(SkillTrustLevel::FIRST_PARTY, $facts->trustLevel);
        self::assertNull($lookup->find(99));
        self::assertNull($lookup->find(0));
        self::assertNull($lookup->find(-10));
    }

    #[Test]
    public function oneLookupInstanceReadsAChangedSourceFromTheDatabaseImmediately(): void
    {
        $this->source(10);
        $lookup = new SkillSourceLookup($this->pool());
        $before = $lookup->find(10);
        self::assertInstanceOf(SkillSourceFacts::class, $before);
        self::assertSame(SkillTrustLevel::VERIFIED, $before->trustLevel);
        self::assertSame(SkillSourceType::REPO, $before->type);
        $connection = $this->pool()->getConnectionForTable('tx_nrllm_skill_source');
        $connection->update(
            'tx_nrllm_skill_source',
            ['type' => 'single_file', 'trust_level' => 'untrusted'],
            ['uid' => 10],
        );
        $after = $lookup->find(10);
        self::assertInstanceOf(SkillSourceFacts::class, $after);
        self::assertSame(10, $after->uid);
        self::assertSame(SkillTrustLevel::UNTRUSTED, $after->trustLevel);
        self::assertSame(SkillSourceType::SINGLE_FILE, $after->type);
        self::assertNotSame($before, $after);
        $connection->update(
            'tx_nrllm_skill_source',
            ['enabled' => 0],
            ['uid' => 10],
        );
        self::assertNull($lookup->find(10));
        $connection->update(
            'tx_nrllm_skill_source',
            ['enabled' => 1, 'hidden' => 1],
            ['uid' => 10],
        );
        self::assertNull($lookup->find(10));
        $connection->update(
            'tx_nrllm_skill_source',
            ['hidden' => 0, 'deleted' => 1],
            ['uid' => 10],
        );
        self::assertNull($lookup->find(10));
        $connection->delete('tx_nrllm_skill_source', ['uid' => 10]);
        self::assertNull($lookup->find(10));
    }

    #[Test]
    public function unknownStoredProvenanceCannotGrantPublisherTrust(): void
    {
        $this->source(10);
        $this
            ->pool()
            ->getConnectionForTable('tx_nrllm_skill_source')
            ->update(
                'tx_nrllm_skill_source',
                [
                    'type' => 'unexpected-type',
                    'trust_level' => 'unexpected-level',
                ],
                ['uid' => 10],
            );
        $facts = (new SkillSourceLookup($this->pool()))->find(10);
        self::assertInstanceOf(SkillSourceFacts::class, $facts);
        self::assertSame(10, $facts->uid);
        self::assertNull($facts->type);
        self::assertSame(SkillTrustLevel::UNTRUSTED, $facts->trustLevel);
    }
}

<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SyncStatus;
use Netresearch\NrLlm\Service\Skill\SkillSyncLeaseRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;

#[CoversClass(SkillSyncLeaseRepository::class)]
final class SkillSyncLeaseRepositoryTest extends AbstractFunctionalTestCase
{
    private const NOW = 1700000000;

    private const TABLE = 'tx_nrllm_skill_source';

    private const OLD_OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const NEW_OWNER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * @return iterable<string,array{int,bool}>
     */
    public static function leaseAges(): iterable
    {
        yield 'uninitialized' => [0, true];
        yield '181 seconds ago' => [self::NOW - 181, true];
        yield '180 seconds ago' => [self::NOW - 180, false];
        yield '179 seconds ago' => [self::NOW - 179, false];
        yield 'same second' => [self::NOW, false];
        yield '179 seconds future' => [self::NOW + 179, false];
        yield '180 seconds future' => [self::NOW + 180, false];
        yield '181 seconds future' => [self::NOW + 181, true];
    }

    #[Test]
    #[DataProvider('leaseAges')]
    public function claimUsesPersistedStatusAndExactAbsoluteExpiry(
        int $heartbeat,
        bool $stale,
    ): void {
        $this->source($heartbeat);
        $before = $this->row();
        self::assertSame(
            $stale,
            $this->leases()->claim(10, self::NEW_OWNER, self::NOW),
        );
        $after = $this->row();
        if ($stale) {
            self::assertSame(self::NEW_OWNER, $after['sync_lock_token']);
            self::assertSame(SyncStatus::SYNCING->value, $after['sync_status']);
            self::assertSame(self::NOW, (int)$after['last_synced']);
        } else {
            self::assertSame($before, $after);
        }
    }

    #[Test]
    #[DataProvider('leaseAges')]
    public function renewalCannotReviveAnExpiredOwner(
        int $heartbeat,
        bool $stale,
    ): void {
        $this->source($heartbeat);
        $before = $this->row();
        self::assertSame(
            !$stale,
            $this->leases()->renew(10, self::OLD_OWNER, self::NOW),
        );
        $after = $this->row();
        if ($stale) {
            self::assertSame($before, $after);
        } else {
            self::assertSame(self::OLD_OWNER, $after['sync_lock_token']);
            self::assertSame(self::NOW, (int)$after['last_synced']);
            self::assertSame(1, (int)$after['sync_lock_version']);
        }
    }

    #[Test]
    #[DataProvider('leaseAges')]
    public function reclaimOnlyChangesCurrentlyStaleRows(
        int $heartbeat,
        bool $stale,
    ): void {
        $this->source($heartbeat);
        $before = $this->row();
        self::assertSame($stale, $this->leases()->reclaim(10, self::NOW));
        $after = $this->row();
        self::assertIsString($after['sync_error']);
        if ($stale) {
            self::assertSame(SyncStatus::ERROR->value, $after['sync_status']);
            self::assertSame('', $after['sync_lock_token']);
            self::assertStringContainsString(
                'interrupted',
                $after['sync_error'],
            );
        } else {
            self::assertSame($before, $after);
        }
    }

    #[Test]
    public function sameSecondRenewalsAndReplacementTokensRemainDistinct(): void
    {
        $this->source(self::NOW, SyncStatus::OK);
        $leases = $this->leases();
        self::assertTrue($leases->claim(10, self::OLD_OWNER, self::NOW));
        self::assertTrue($leases->renew(10, self::OLD_OWNER, self::NOW));
        self::assertTrue($leases->renew(10, self::OLD_OWNER, self::NOW));
        self::assertSame(2, (int)$this->row()['sync_lock_version']);
        self::assertTrue(
            $leases->release(
                10,
                self::OLD_OWNER,
                SyncStatus::OK,
                self::NOW,
                '',
                'old-sha',
            ),
        );
        self::assertTrue($leases->claim(10, self::NEW_OWNER, self::NOW));
        $before = $this->row();
        self::assertFalse($leases->renew(10, self::OLD_OWNER, self::NOW));
        self::assertFalse(
            $leases->release(
                10,
                self::OLD_OWNER,
                SyncStatus::ERROR,
                self::NOW,
                'old-error',
                'old-overwrite',
            ),
        );
        self::assertSame(
            $before,
            $this->row(),
            'The old worker must not change any successor bookkeeping, even at the same timestamp.',
        );
    }

    #[Test]
    public function completionAndRenewalDefeatAStaleReclaimer(): void
    {
        $this->source(self::NOW - 181);
        $leases = $this->leases();
        $this
            ->connection()
            ->update(self::TABLE, ['last_synced' => self::NOW], ['uid' => 10]);
        $renewed = $this->row();
        self::assertFalse($leases->reclaim(10, self::NOW));
        self::assertSame($renewed, $this->row());
        $this
            ->connection()
            ->update(
                self::TABLE,
                [
                    'last_synced' => self::NOW - 181,
                    'sync_status' => SyncStatus::OK->value,
                ],
                ['uid' => 10],
            );
        $completed = $this->row();
        self::assertFalse($leases->reclaim(10, self::NOW));
        self::assertSame($completed, $this->row());
        self::assertFalse($leases->renew(10, self::OLD_OWNER, self::NOW));
        self::assertFalse(
            $leases->release(
                10,
                self::OLD_OWNER,
                SyncStatus::ERROR,
                self::NOW,
                'old-error',
                null,
            ),
        );
        self::assertSame($completed, $this->row());
    }

    /**
     * @return iterable<string,array{bool}>
     */
    public static function deletionCases(): iterable
    {
        yield 'physical' => [false];
        yield 'TYPO3 soft deletion' => [true];
    }

    #[Test]
    #[DataProvider('deletionCases')]
    public function deletedRowsCannotBeClaimedRenewedReclaimedOrReleased(
        bool $soft,
    ): void {
        $this->source(self::NOW - 181);
        if ($soft) {
            $this
                ->connection()
                ->update(self::TABLE, ['deleted' => 1], ['uid' => 10]);
        } else {
            $this->connection()->delete(self::TABLE, ['uid' => 10]);
        }

        $before = $this
            ->connection()
            ->select(['*'], self::TABLE, ['uid' => 10])
            ->fetchAssociative();
        $leases = $this->leases();
        self::assertFalse($leases->exists(10));
        self::assertFalse($leases->claim(10, self::NEW_OWNER, self::NOW));
        self::assertFalse($leases->renew(10, self::OLD_OWNER, self::NOW));
        self::assertFalse($leases->reclaim(10, self::NOW));
        self::assertFalse(
            $leases->release(
                10,
                self::OLD_OWNER,
                SyncStatus::ERROR,
                self::NOW,
                'old-error',
                'old-sha',
            ),
        );
        self::assertNull($leases->bookkeeping(10));
        self::assertSame(
            $before,
            $this
                ->connection()
                ->select(['*'], self::TABLE, ['uid' => 10])
                ->fetchAssociative(),
        );
    }

    #[Test]
    public function publicationRejectsLostOwnershipBeforeCallingTheWriter(): void
    {
        $this->source(self::NOW);
        $this
            ->connection()
            ->update(self::TABLE, ['sync_lock_token' => self::NEW_OWNER], ['uid' => 10]);
        $before = $this->row();
        $writes = 0;
        try {
            $this
                ->leases()
                ->publication(
                    10,
                    self::OLD_OWNER,
                    self::NOW,
                    static function () use (&$writes): void {
                        ++$writes;
                    },
                );
            self::fail('A stale owner cannot publish.');
        } catch (RuntimeException $failure) {
            self::assertSame(1781650221, $failure->getCode());
        }

        self::assertSame(0, $writes);
        self::assertSame($before, $this->row());
    }

    #[Test]
    public function publicationCommitsSkillsAuditAndCompletionOnTheSameConnection(): void
    {
        $this->source(self::NOW);
        $leases = $this->leases();
        self::assertTrue($leases->hasOnePublicationConnection());
        $result = $leases->publication(
            10,
            self::OLD_OWNER,
            self::NOW,
            function () use ($leases): string {
                self::assertGreaterThan(
                    0,
                    $this->connection()->getTransactionNestingLevel(),
                );
                $this
                    ->connection()
                    ->insert(
                        'tx_nrllm_skill',
                        [
                            'source' => 10,
                            'identifier' => '10:one',
                            'body' => 'literal',
                        ],
                    );
                $this
                    ->connection()
                    ->insert(
                        'tx_nrllm_skill_audit',
                        ['source_uid' => 10, 'event' => 'ingested'],
                    );
                self::assertTrue(
                    $leases->release(
                        10,
                        self::OLD_OWNER,
                        SyncStatus::OK,
                        self::NOW,
                        '',
                        'sha',
                    ),
                );
                return 'published';
            },
        );
        self::assertSame('published', $result);
        self::assertSame(
            'literal',
            $this
                ->connection()
                ->select(['body'], 'tx_nrllm_skill', ['identifier' => '10:one'])
                ->fetchOne(),
        );
        self::assertSame(
            1,
            $this
                ->connection()
                ->count('*', 'tx_nrllm_skill_audit', ['source_uid' => 10]),
        );
        self::assertSame(SyncStatus::OK->value, $this->row()['sync_status']);
        self::assertSame('', $this->row()['sync_lock_token']);
        self::assertSame('sha', $this->row()['pinned_sha']);
        self::assertSame(0, $this->connection()->getTransactionNestingLevel());
    }

    #[Test]
    public function publicationFailureRollsBackEveryTableAndLeaseUpdate(): void
    {
        $this->source(self::NOW);
        $before = $this->row();
        $expected = new RuntimeException('controlled publication failure', 221);
        $caught = null;
        try {
            $this
                ->leases()
                ->publication(
                    10,
                    self::OLD_OWNER,
                    self::NOW,
                    function () use ($expected): never {
                        $this->connection()->insert(
                            'tx_nrllm_skill',
                            ['source' => 10, 'identifier' => '10:one'],
                        );
                        $this->connection()->insert(
                            'tx_nrllm_skill_audit',
                            ['source_uid' => 10, 'event' => 'ingested'],
                        );
                        throw $expected;
                    },
                );
        } catch (RuntimeException $failure) {
            $caught = $failure;
        }

        self::assertSame(
            $expected,
            $caught,
            'Publication must propagate the exact writer failure.',
        );

        self::assertSame(
            0,
            $this->connection()->count('*', 'tx_nrllm_skill', []),
        );
        self::assertSame(
            0,
            $this->connection()->count('*', 'tx_nrllm_skill_audit', []),
        );
        self::assertSame($before, $this->row());
        self::assertSame(0, $this->connection()->getTransactionNestingLevel());
    }

    private function leases(): SkillSyncLeaseRepository
    {
        return new SkillSyncLeaseRepository($this->getConnectionPool());
    }

    private function connection(): Connection
    {
        return $this->getConnectionPool()->getConnectionForTable(self::TABLE);
    }

    private function source(
        int $heartbeat,
        SyncStatus $status = SyncStatus::SYNCING,
    ): void {
        $this
            ->connection()
            ->insert(
                self::TABLE,
                [
                    'uid' => 10,
                    'sync_status' => $status->value,
                    'last_synced' => $heartbeat,
                    'sync_lock_token' => self::OLD_OWNER,
                    'sync_error' => 'previous',
                    'pinned_sha' => 'previous-sha',
                ],
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function row(): array
    {
        $row = $this
            ->connection()
            ->select(['*'], self::TABLE, ['uid' => 10])
            ->fetchAssociative();
        self::assertIsArray($row);
        return $row;
    }
}

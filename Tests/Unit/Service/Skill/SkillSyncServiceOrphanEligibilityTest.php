<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SyncStatus;
use Netresearch\NrLlm\Domain\Model\SkillSource;
use Netresearch\NrLlm\Domain\Repository\SkillRepository;
use Netresearch\NrLlm\Domain\Repository\SkillSourceRepository;
use Netresearch\NrLlm\Service\Skill\GitHubClientInterface;
use Netresearch\NrLlm\Service\Skill\MarketplaceParser;
use Netresearch\NrLlm\Service\Skill\SkillDiscovery;
use Netresearch\NrLlm\Service\Skill\SkillMarkdownParser;
use Netresearch\NrLlm\Service\Skill\SkillSyncService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use ReflectionClass;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Unit coverage for the marketplace orphaning decision (`orphanEligible()`) and
 * its `inScope()` helper.
 *
 * These pure decision methods carry the data-loss risk of the sync — deciding
 * whether an upstream-absent skill is genuinely removed (orphan it) or merely
 * the victim of a transient child-repo failure (protect it). They are otherwise
 * only exercised by functional tests, which are NOT part of the blocking CI
 * job; these unit tests gate the logic on every merge.
 *
 * The methods are private and take only string/array arguments (no TYPO3
 * persistence is touched), so the service is constructed with stub collaborators
 * and the methods are invoked via reflection — mirroring how other unit tests in
 * this suite exercise private logic.
 */
#[CoversClass(SkillSyncService::class)]
final class SkillSyncServiceOrphanEligibilityTest extends AbstractUnitTestCase
{
    private SkillSyncService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        // None of these collaborators are touched when invoking orphanEligible()/inScope()
        // directly; they only satisfy the constructor signature. The two repositories are
        // `final` (PHPUnit cannot double them) but their parameterless Extbase constructor
        // is side-effect free — initializeObject() is only invoked by the DI container — so a
        // real, never-queried instance is the right test double here.
        $this->subject = new SkillSyncService(
            self::createStub(GitHubClientInterface::class),
            new SkillMarkdownParser(),
            new MarketplaceParser(),
            new SkillDiscovery(),
            new SkillRepository(),
            new SkillSourceRepository(),
            self::createStub(PersistenceManagerInterface::class),
            new NullLogger(),
        );
    }

    /**
     * @param list<string> $reachedPrefixes
     * @param list<string> $listedPrefixes
     */
    private function orphanEligible(string $identifier, array $reachedPrefixes, array $listedPrefixes): bool
    {
        $method = (new ReflectionClass($this->subject))->getMethod('orphanEligible');

        return (bool)$method->invoke($this->subject, $identifier, $reachedPrefixes, $listedPrefixes);
    }

    private function isLockActive(SkillSource $source, int $now): bool
    {
        $method = (new ReflectionClass($this->subject))->getMethod('isLockActive');

        return (bool)$method->invoke($this->subject, $source, $now);
    }

    private function sourceWith(SyncStatus $status, int $lastSynced): SkillSource
    {
        $source = new SkillSource();
        $source->setSyncStatus($status->value);
        $source->setLastSynced($lastSynced);

        return $source;
    }

    #[Test]
    public function recentSyncingHeartbeatIsAnActiveLock(): void
    {
        // SYNCING with a heartbeat inside the stale window => a concurrent sync is running.
        self::assertTrue($this->isLockActive($this->sourceWith(SyncStatus::SYNCING, 1_000), 1_000));
        self::assertTrue($this->isLockActive($this->sourceWith(SyncStatus::SYNCING, 1_000), 1_500));
        // Small clock skew / backwards NTP nudge (heartbeat slightly in the
        // future) is still an active lock, not a permanent wedge.
        self::assertTrue($this->isLockActive($this->sourceWith(SyncStatus::SYNCING, 1_000), 995));
    }

    #[Test]
    public function staleOrNeverSetSyncingHeartbeatIsReclaimable(): void
    {
        // Heartbeat older than STALE_LOCK_SECONDS (600) => a crashed sync, reclaimable.
        self::assertFalse($this->isLockActive($this->sourceWith(SyncStatus::SYNCING, 1_000), 1_601));
        // Never-set heartbeat (0) => reclaimable regardless of "now".
        self::assertFalse($this->isLockActive($this->sourceWith(SyncStatus::SYNCING, 0), 1_000));
        // Heartbeat FAR in the future (large clock correction / corruption) is
        // treated as stale, so it cannot lock the source indefinitely.
        self::assertFalse($this->isLockActive($this->sourceWith(SyncStatus::SYNCING, 1_000), 300));
    }

    #[Test]
    public function nonSyncingStatusIsNeverALock(): void
    {
        self::assertFalse($this->isLockActive($this->sourceWith(SyncStatus::OK, 1_000), 1_000));
        self::assertFalse($this->isLockActive($this->sourceWith(SyncStatus::ERROR, 1_000), 1_000));
    }

    /**
     * @param list<string> $prefixes
     */
    private function inScope(string $identifier, array $prefixes): bool
    {
        $method = (new ReflectionClass($this->subject))->getMethod('inScope');

        return (bool)$method->invoke($this->subject, $identifier, $prefixes);
    }

    #[Test]
    public function skillWhoseReachedPrefixWasVisitedIsOrphanEligible(): void
    {
        // The child repo was successfully visited this run and the skill was absent there:
        // it was genuinely removed upstream, so orphaning is allowed.
        self::assertTrue(
            $this->orphanEligible(
                '30:p1/repoa/skills/x/SKILL.md',
                reachedPrefixes: ['30:p1/repoa/'],
                listedPrefixes: ['30:p1/repoa/'],
            ),
            'a skill whose prefix was reached must be orphan-eligible',
        );
    }

    #[Test]
    public function skillInListedButUnreachedPrefixIsProtected(): void
    {
        // The plugin is still listed in the index but its child repo could not be reached
        // this run (a transient failure): the skill must NOT be orphaned.
        self::assertFalse(
            $this->orphanEligible(
                '30:p2/repob/skills/x/SKILL.md',
                reachedPrefixes: ['30:p1/repoa/'],
                listedPrefixes: ['30:p1/repoa/', '30:p2/repob/'],
            ),
            'a skill in a listed-but-unreached prefix (transient failure) must be protected',
        );
    }

    #[Test]
    public function skillInDeListedPrefixIsOrphanEligible(): void
    {
        // The plugin is no longer listed in the index at all (de-listed): its skills are
        // orphan-eligible even though its prefix was never reached this run.
        self::assertTrue(
            $this->orphanEligible(
                '30:p2/repob/skills/x/SKILL.md',
                reachedPrefixes: ['30:p1/repoa/'],
                listedPrefixes: ['30:p1/repoa/'],
            ),
            'a skill in a de-listed prefix must be orphan-eligible',
        );
    }

    #[Test]
    public function reachedTakesPrecedenceWhenPrefixIsBothReachedAndListed(): void
    {
        // Reached implies orphan-eligible regardless of listing — a reached prefix is also
        // a listed one, so the "reached" branch must win over the "not listed" branch.
        self::assertTrue(
            $this->orphanEligible(
                '30:p1/repoa/skills/x/SKILL.md',
                reachedPrefixes: ['30:p1/repoa/', '30:p2/repob/'],
                listedPrefixes: ['30:p1/repoa/', '30:p2/repob/'],
            ),
        );
    }

    #[Test]
    public function emptyReachedAndListedOrphansEverything(): void
    {
        // The marketplace-applicable edge where reached/listed are both empty (e.g. an empty
        // index): nothing is reached and nothing is listed, so the skill is not "in scope" of
        // any listed prefix and is therefore orphan-eligible (de-listed semantics).
        self::assertTrue(
            $this->orphanEligible('30:p1/repoa/skills/x/SKILL.md', reachedPrefixes: [], listedPrefixes: []),
            'with no reached and no listed prefixes the skill is treated as de-listed and orphan-eligible',
        );
    }

    #[Test]
    public function inScopeMatchesByPrefix(): void
    {
        self::assertTrue($this->inScope('30:p1/repoa/skills/x/SKILL.md', ['30:p1/repoa/']));
    }

    #[Test]
    public function inScopeRejectsNonMatchingPrefix(): void
    {
        self::assertFalse($this->inScope('30:p2/repob/skills/x/SKILL.md', ['30:p1/repoa/']));
    }

    #[Test]
    public function inScopeWithNoPrefixesIsFalse(): void
    {
        self::assertFalse($this->inScope('30:p1/repoa/skills/x/SKILL.md', []));
    }

    /**
     * A prefix is matched only as a leading substring; a prefix appearing mid-identifier
     * must not match (str_starts_with semantics).
     */
    #[Test]
    #[DataProvider('prefixMatchCases')]
    public function inScopeUsesStartsWithSemantics(string $identifier, string $prefix, bool $expected): void
    {
        self::assertSame($expected, $this->inScope($identifier, [$prefix]));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function prefixMatchCases(): iterable
    {
        yield 'exact leading prefix' => ['30:p1/repoa/SKILL.md', '30:p1/repoa/', true];
        yield 'prefix mid-string does not match' => ['30:other/30:p1/repoa/SKILL.md', '30:p1/repoa/', false];
        yield 'different source uid prefix' => ['31:p1/repoa/SKILL.md', '30:p1/repoa/', false];
    }
}

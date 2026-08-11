<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Governance;

use Netresearch\NrLlm\Domain\ValueObject\SimulationActor;
use Netresearch\NrLlm\Service\Governance\SimulationActorDirectory;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The picker's claim, held against the database (ADR-157): it cannot offer an
 * account the rest of the backend hides.
 *
 * That rests entirely on the query builder's DEFAULT restrictions — nothing in
 * `actors()` names `deleted` or `disable` — so the fixture carries one of each.
 * Dropping the restriction container, or building the query through a
 * connection instead, would keep every other assertion green while offering
 * accounts an operator cannot see anywhere else in the backend.
 */
#[CoversClass(SimulationActorDirectory::class)]
final class SimulationActorDirectoryTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // uid 1 admin, uid 2 editor, uid 3 deleted, uid 4 disabled.
        $this->importFixture('SimulationActorDirectory.csv');
    }

    #[Test]
    public function offersEverySelectableBackendUserOrderedByUsername(): void
    {
        $actors = $this->directory()->actors();

        self::assertSame(['admin', 'editor'], $this->usernames($actors));
        self::assertSame(1, $actors[0]->uid);
        self::assertSame(2, $actors[1]->uid);
    }

    #[Test]
    public function neitherADeletedNorADisabledAccountIsOffered(): void
    {
        // Ordered by username, both would sort BETWEEN the two selectable rows,
        // so an unfiltered read cannot pass the assertion by accident.
        $usernames = $this->usernames($this->directory()->actors());

        self::assertNotContains('deleted-editor', $usernames);
        self::assertNotContains('disabled-editor', $usernames);
        self::assertCount(2, $usernames);
    }

    #[Test]
    public function theAdminFlagIsCarriedForTheLabel(): void
    {
        // Label only: the tool gate is asked through the live user the resolver
        // loads, never through this row. Getting it wrong here would mislabel
        // the picker, not grant anything.
        $actors = $this->directory()->actors();

        self::assertTrue($actors[0]->admin, 'uid 1 is the admin');
        self::assertFalse($actors[1]->admin, 'uid 2 is not');
        self::assertTrue($actors[0]->resolved, 'a picker entry is a real row');
    }

    private function directory(): SimulationActorDirectory
    {
        return new SimulationActorDirectory($this->getConnectionPool());
    }

    /**
     * @param list<SimulationActor> $actors
     *
     * @return list<string>
     */
    private function usernames(array $actors): array
    {
        return array_map(static fn(SimulationActor $actor): string => $actor->username, $actors);
    }
}

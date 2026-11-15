<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ObservedState;
use Netresearch\NrLlm\Service\Tool\InvocationDigests;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * What a state digest is computed from (ADR-298): the listed fields of a
 * record as the run's workspace shows it, read with the deleted restriction
 * only, and nothing when that workspace shows no such row.
 */
#[CoversClass(InvocationDigests::class)]
final class InvocationDigestsObserveTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
    ];

    private const DRAFT = 1;

    private const OTHER_DRAFT = 2;

    private const LIVE = 10;

    private const WITH_DRAFT = 11;

    private const DRAFT_VERSION = 12;

    private const NEW_IN_DRAFT = 13;

    private const OTHER_DRAFT_VERSION = 14;

    private const DELETED = 15;

    private const DELETED_IN_DRAFT = 16;

    private const DELETE_PLACEHOLDER = 17;

    private const HIDDEN = 18;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $pool = $this->getService(ConnectionPool::class);
        $pool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Draft', 'members' => '']);
        $pool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Other', 'members' => '']);

        foreach ([
            [self::LIVE, 'Live', 0, 0, 0, 0, 0],
            [self::WITH_DRAFT, 'Live with a draft', 0, 0, 0, 0, 0],
            [self::DRAFT_VERSION, 'The draft', self::DRAFT, self::WITH_DRAFT, 0, 0, 0],
            [self::NEW_IN_DRAFT, 'New in the draft', self::DRAFT, 0, 1, 0, 0],
            [self::OTHER_DRAFT_VERSION, 'The other draft', self::OTHER_DRAFT, self::LIVE, 0, 0, 0],
            [self::DELETED, 'Deleted', 0, 0, 0, 1, 0],
            [self::DELETED_IN_DRAFT, 'Deleted in the draft', 0, 0, 0, 0, 0],
            [self::DELETE_PLACEHOLDER, 'Deleted in the draft', self::DRAFT, self::DELETED_IN_DRAFT, 2, 0, 0],
            [self::HIDDEN, 'Hidden', 0, 0, 0, 0, 1],
        ] as [$uid, $title, $workspace, $original, $state, $deleted, $hidden]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid'        => $uid, 'pid' => 0, 'title' => $title, 'doktype' => 1,
                't3ver_wsid' => $workspace, 't3ver_oid' => $original, 't3ver_state' => $state, 'deleted' => $deleted, 'hidden' => $hidden,
            ]);
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * Live shows the live row; a draft workspace shows its version under the
     * live uid and a record new there; the values are the fields listed, as
     * strings, with null kept, in a digest that names table, uid and fields.
     */
    #[Test]
    public function aRecordIsObservedAsTheRunsWorkspaceShowsIt(): void
    {
        $live = $this->observe(self::WITH_DRAFT, ['title'], 0);
        self::assertInstanceOf(ObservedState::class, $live);
        self::assertSame(['title' => 'Live with a draft'], $live->values);

        $draft = $this->observe(self::WITH_DRAFT, ['title', 'doktype', 'subtitle'], self::DRAFT);
        self::assertInstanceOf(ObservedState::class, $draft);
        self::assertSame(['doktype' => '1', 'subtitle' => '', 'title' => 'The draft'], $draft->values);
        self::assertNotSame($live->digest, $this->observe(self::WITH_DRAFT, ['title'], self::DRAFT)?->digest);

        self::assertSame(['title' => 'New in the draft'], $this->observe(self::NEW_IN_DRAFT, ['title'], self::DRAFT)?->values);
        self::assertSame(['title' => 'Live'], $this->observe(self::LIVE, ['title'], self::DRAFT)?->values, 'a version of another workspace is not shown');
        self::assertSame(['title' => 'Hidden'], $this->observe(self::HIDDEN, ['title'], 0)?->values, 'a hidden record has a state');
    }

    /**
     * The digest covers the table, the uid and every listed field: the same
     * fields in another order have the same digest, another uid or another
     * field list does not.
     */
    #[Test]
    public function theDigestNamesTheRecordAndTheFields(): void
    {
        $one = $this->observe(self::LIVE, ['title', 'doktype'], 0);
        self::assertNotNull($one);
        self::assertSame($one->digest, $this->observe(self::LIVE, ['doktype', 'title'], 0)?->digest);
        self::assertNotSame($one->digest, $this->observe(self::LIVE, ['title'], 0)?->digest);
        self::assertNotSame($this->observe(self::LIVE, ['doktype'], 0)?->digest, $this->observe(self::HIDDEN, ['doktype'], 0)?->digest);

        $this->getService(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['title' => 'Changed'], ['uid' => self::LIVE]);
        self::assertNotSame($one->digest, $this->observe(self::LIVE, ['title', 'doktype'], 0)?->digest);
    }

    #[Test]
    public function noStateWhenTheWorkspaceShowsNoSuchRow(): void
    {
        self::assertNull($this->observe(self::DELETED, ['title'], 0), 'deleted');
        self::assertNull($this->observe(self::DELETED_IN_DRAFT, ['title'], self::DRAFT), 'deleted in the draft workspace');
        self::assertNotNull($this->observe(self::DELETED_IN_DRAFT, ['title'], 0), 'positive control: live still shows it');
        self::assertNull($this->observe(self::DRAFT_VERSION, ['title'], self::DRAFT), 'a version row under its own uid');
        self::assertNull($this->observe(self::OTHER_DRAFT_VERSION, ['title'], 0), 'a version of another workspace, from live');
        self::assertNull($this->observe(self::NEW_IN_DRAFT, ['title'], 0), 'a record new in a draft, from live');
        self::assertNull($this->observe(999, ['title'], 0), 'no row');
    }

    #[Test]
    public function noStateForAnUnknownTableAMissingColumnOrNoFields(): void
    {
        self::assertNull($this->observe(self::LIVE, [], 0));
        self::assertNull($this->observe(self::LIVE, ['title', 'no_such_column'], 0));
        self::assertNull($this->digests()->observe('tx_no_such_table', 1, ['title'], ToolExecutionContext::fromBackendUser($this->adminIn(0))));

        // A table without versioning has a state in live and none from a
        // draft workspace.
        self::assertNotNull($this->digests()->observe('be_groups', $this->group(), ['title'], ToolExecutionContext::fromBackendUser($this->adminIn(0))));
        self::assertNull($this->digests()->observe('be_groups', $this->group(), ['title'], ToolExecutionContext::fromBackendUser($this->adminIn(self::DRAFT))));
    }

    /**
     * The workspace is the run's: a user in a draft workspace whose run did
     * not record that workspace reads live, as the writing tools write there.
     */
    #[Test]
    public function aRunThatDidNotRecordTheWorkspaceReadsLive(): void
    {
        $user    = $this->adminIn(self::DRAFT);
        $context = new ToolExecutionContext(AiActorContext::backendUser(1, true), $user);

        self::assertSame(['title' => 'Live with a draft'], $this->digests()->observe('pages', self::WITH_DRAFT, ['title'], $context)?->values);
    }

    /**
     * @param list<string> $fields
     */
    private function observe(int $uid, array $fields, int $workspace): ?ObservedState
    {
        return $this->digests()->observe('pages', $uid, $fields, ToolExecutionContext::fromBackendUser($this->adminIn($workspace)));
    }

    private function digests(): InvocationDigests
    {
        return $this->getService(InvocationDigests::class);
    }

    private function adminIn(int $workspace): BackendUserAuthentication
    {
        $admin = $this->setUpBackendUser(1);
        self::assertTrue($admin->setTemporaryWorkspace($workspace));
        self::assertSame($workspace, $admin->workspace, 'positive control: the user is in the workspace');

        return $admin;
    }

    private function group(): int
    {
        $connection = $this->getService(ConnectionPool::class)->getConnectionForTable('be_groups');
        $connection->insert('be_groups', ['pid' => 0, 'title' => 'Editors']);

        return (int) $connection->lastInsertId();
    }
}

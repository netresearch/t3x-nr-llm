<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\SubjectRecord;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\Builtin\HighlightRecordTool;
use Netresearch\NrLlm\Service\Tool\TableReadAccessService;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The highlight builtin accepts only targets registered from the run's
 * subject record (ADR-214 item 9, ADR-228): the record itself and, for a
 * page, its content elements in the subject's language or in all languages.
 */
#[CoversClass(HighlightRecordTool::class)]
final class HighlightRecordToolTest extends AbstractFunctionalTestCase
{
    private BackendUserAuthentication $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->admin = $this->setUpBackendUser(1);

        $pool = $this->pool();
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Home']);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 2, 'pid' => 0, 'title' => 'About']);
        foreach ([
            [10, 1, 0, 0],
            [11, 1, 1, 0],
            [12, 1, -1, 0],
            [13, 2, 0, 0],
            [14, 1, 0, 1],
        ] as [$uid, $pid, $language, $deleted]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid'              => $uid,
                'pid'              => $pid,
                'sys_language_uid' => $language,
                'deleted'          => $deleted,
                'header'           => 'Element ' . $uid,
            ]);
        }

        // A new element in workspace 3, which the administrator is not in.
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 15, 'pid' => 1, 't3ver_wsid' => 3, 't3ver_state' => 1, 'header' => 'Workspace draft']);
    }

    #[Test]
    public function theSubjectRecordItselfIsATarget(): void
    {
        $result = $this->call('pages', 1, $this->page(1));

        self::assertFalse($result->isError);
        self::assertSame('pages:1', (string)$result->highlightTarget);
    }

    #[Test]
    public function aContentElementOnTheSubjectPageInItsLanguageOrAllLanguagesIsATarget(): void
    {
        self::assertSame('tt_content:10', (string)$this->call('tt_content', 10, $this->page(1))->highlightTarget);
        self::assertSame('tt_content:12', (string)$this->call('tt_content', 12, $this->page(1))->highlightTarget, 'all languages');
        self::assertSame('tt_content:11', (string)$this->call('tt_content', 11, $this->page(1, 1))->highlightTarget, 'the subject language');
    }

    #[Test]
    public function anythingElseIsRefused(): void
    {
        foreach ([
            'another page'                    => ['pages', 2, $this->page(1)],
            'an element on another page'      => ['tt_content', 13, $this->page(1)],
            'an element in another language'  => ['tt_content', 11, $this->page(1)],
            'a deleted element'               => ['tt_content', 14, $this->page(1)],
            'a draft in another workspace'    => ['tt_content', 15, $this->page(1)],
            'an element that does not exist'  => ['tt_content', 99, $this->page(1)],
            'another table'                   => ['sys_file', 10, $this->page(1)],
            // Element 10 sits on pid 1: a non-page subject with uid 1 must
            // not reach it through the pid match.
            'an element of a non-page subject' => ['tt_content', 10, new SubjectRecord(new RecordReference('tt_content', 1))],
            'a malformed table'               => ['pages; drop', 1, $this->page(1)],
        ] as $case => [$table, $uid, $subject]) {
            $result = $this->call($table, $uid, $subject);
            self::assertTrue($result->isError, $case);
            self::assertNull($result->highlightTarget, $case);
        }
    }

    /**
     * A content element is a target only for an acting user who may read
     * `tt_content`; the subject record itself was checked at the start.
     */
    #[Test]
    public function aContentElementNeedsReadAccessToContent(): void
    {
        $pool = $this->pool();
        $pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => 1, 'pid' => 0, 'title' => 'Content', 'tables_select' => 'pages,tt_content']);

        $editor = $this->setUpBackendUser(2);
        $tool   = new HighlightRecordTool($pool, new TableReadAccessService());
        $result = $tool->execute(['table' => 'tt_content', 'uid' => 10], new ToolExecutionContext(AiActorContext::backendUser(2), $editor, subjectRecord: $this->page(1)));
        self::assertTrue($result->isError, 'no table access');

        $pool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '1'], ['uid' => 2]);
        $editor = $this->setUpBackendUser(2);
        $result = $tool->execute(['table' => 'tt_content', 'uid' => 10], new ToolExecutionContext(AiActorContext::backendUser(2), $editor, subjectRecord: $this->page(1)));
        self::assertSame('tt_content:10', (string)$result->highlightTarget);
    }

    #[Test]
    public function aRunAboutNoRecordHighlightsNothing(): void
    {
        $result = (new HighlightRecordTool($this->pool(), new TableReadAccessService()))
            ->execute(['table' => 'pages', 'uid' => 1], new ToolExecutionContext(AiActorContext::backendUser(1, isAdmin: true), $this->admin));

        self::assertTrue($result->isError);
        self::assertNull($result->highlightTarget);
    }

    #[Test]
    public function malformedArgumentsAreRefused(): void
    {
        foreach ([['table' => 'pages', 'uid' => '1'], ['uid' => 1], ['table' => 1, 'uid' => 1]] as $arguments) {
            $result = (new HighlightRecordTool($this->pool(), new TableReadAccessService()))
                ->execute($arguments, $this->context($this->page(1)));
            self::assertTrue($result->isError);
        }
    }

    #[Test]
    public function itShipsDisabledAndIsOfferedToNonAdmins(): void
    {
        $tool = new HighlightRecordTool($this->pool(), new TableReadAccessService());

        self::assertFalse($tool->isEnabledByDefault());
        self::assertFalse($tool->requiresAdmin());
        self::assertSame(HighlightRecordTool::NAME, $tool->getSpec()->name);
    }

    private function call(string $table, int $uid, SubjectRecord $subject): ToolResult
    {
        return (new HighlightRecordTool($this->pool(), new TableReadAccessService()))
            ->execute(['table' => $table, 'uid' => $uid], $this->context($subject));
    }

    private function context(SubjectRecord $subject): ToolExecutionContext
    {
        return new ToolExecutionContext(AiActorContext::backendUser(1, isAdmin: true), $this->admin, subjectRecord: $subject);
    }

    private function page(int $uid, int $language = 0): SubjectRecord
    {
        return new SubjectRecord(new RecordReference('pages', $uid), $language);
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }
}

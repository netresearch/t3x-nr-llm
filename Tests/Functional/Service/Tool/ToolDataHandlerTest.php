<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Error;
use Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\CountsCacheClearsHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\FailsLikeAFlashMessageHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheFailingHookTrait;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RunsANestedToolDataHandlerHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The DataHandler every writing tool runs (ADR-206). A hook of the
 * installation that throws after the last write of a run no longer takes the
 * tool down with it: the steps the DataHandler skipped still run, the failure
 * is recorded for the tool loop, and the tool's own read-back decides. A
 * failure during the writes is rethrown after those steps.
 *
 * The ambient backend user has no session, as in every CLI process — the agent
 * worker's user is built by {@see \Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver}
 * the same way. That is the condition under which a hook that stores a flash
 * message in the session fails.
 */
#[CoversClass(ToolDataHandler::class)]
final class ToolDataHandlerTest extends AbstractFunctionalTestCase
{
    use RegistersTheFailingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend'];

    private const PAGE = 2;

    private const ELEMENT = 21;

    private const OTHER_ELEMENT = 22;

    private ConnectionPool $connectionPool;

    private BackendUserAuthentication $user;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        $this->importFixture('BeUsers.csv');

        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE, 'pid' => 0, 'title' => 'Open', 'doktype' => 1, 'slug' => '/',
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
        foreach ([self::ELEMENT => 'Before', self::OTHER_ELEMENT => 'Other'] as $uid => $header) {
            $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid, 'pid' => self::PAGE, 'colPos' => 0, 'CType' => 'text', 'header' => $header,
            ]);
        }

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        ToolDataHandler::takeFailures();

        // The worker's user, and the one the runs below write as.
        $this->user = $this->useASessionlessAmbientUser(1);

        $this->registerHook('processDatamapClass', FailsLikeAFlashMessageHook::class);
        $this->registerHook('processCmdmapClass', FailsLikeAFlashMessageHook::class);
        $this->registerHook('clearCachePostProc', CountsCacheClearsHook::class . '->record');
    }

    protected function tearDown(): void
    {
        ToolDataHandler::takeFailures();
        $this->unregisterFailingHook();
        $this->unregisterHook('processDatamapClass', RunsANestedToolDataHandlerHook::class);
        $this->unregisterHook('clearCachePostProc', CountsCacheClearsHook::class . '->record');
        CountsCacheClearsHook::reset();
        RunsANestedToolDataHandlerHook::reset();

        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function aHookThatFailsAfterTheWriteLeavesTheRowWrittenAndTheFailureRecorded(): void
    {
        FailsLikeAFlashMessageHook::$failAt = FailsLikeAFlashMessageHook::AFTER_ALL_OPERATIONS;

        $dataHandler = $this->updateHeader('After');

        self::assertSame('After', $this->headerOf(self::ELEMENT));
        $failure = $this->onlyFailure();
        self::assertStringContainsString(FailsLikeAFlashMessageHook::class . '::processDatamap_afterAllOperations() threw Error', $failure);
        self::assertStringContainsString('Call to a member function set() on null', $failure);
        // The DataHandler's own log stays as TYPO3 wrote it: writers that
        // refuse on any entry there must not read a landed write as refused.
        self::assertSame([], $dataHandler->errorLog);
    }

    #[Test]
    public function theStepsTheFailureSkippedStillRun(): void
    {
        FailsLikeAFlashMessageHook::$failAt = FailsLikeAFlashMessageHook::AFTER_ALL_OPERATIONS;

        // A relation written in the same run: its reference index row is
        // written by the step that follows the hooks.
        $this->updateHeader('After', ['records' => 'tt_content_' . self::OTHER_ELEMENT]);

        self::assertContains(self::PAGE, CountsCacheClearsHook::$pages, 'The cache of the written page was not flushed.');
        self::assertSame(1, $this->referenceIndexRowsFrom(self::ELEMENT, 'records'), 'The reference index of the written relation was not updated.');
        self::assertFalse(
            $this->runtimeCache()->has('core-datahandler-elementsToBeDeleted'),
            'The run left its registry of elements to be deleted behind for the next run in this process.',
        );
    }

    /**
     * The cache flush runs hooks of its own, so the run can also fail in one
     * of its finishing steps. The flush is not retried — it would fail the
     * same way — but its queue is emptied, or the next run in this process
     * would replay it; the step after it still runs. The failure is named by
     * the hook, not by the core function that called it.
     */
    #[Test]
    public function aCacheHookThatFailsIsNamedAndItsQueueDoesNotOutliveTheRun(): void
    {
        CountsCacheClearsHook::$fail = true;

        $this->updateHeader('After');

        self::assertSame('After', $this->headerOf(self::ELEMENT));
        self::assertFalse(
            $this->runtimeCache()->has('core-datahandler-elementsToBeDeleted'),
            'The reset after the failing cache flush did not run.',
        );
        self::assertSame([], (new ReflectionProperty(DataHandler::class, 'recordsToClearCacheFor'))->getValue());
        self::assertStringContainsString(
            CountsCacheClearsHook::class . '::record() threw RuntimeException: A test cache hook fails',
            $this->onlyFailure(),
        );
    }

    /**
     * During the writes a run may have written some records and not others,
     * so "failed" is the honest answer: the failure is rethrown, after the
     * finishing steps ran, and nothing is recorded for a note.
     */
    /**
     * The after-hook fails, and then the cache flush the recovery runs fails
     * as well: its queue is emptied all the same, and the step after it runs.
     */
    #[Test]
    public function aFlushThatFailsWhileTheRunIsFinishedLeavesNoQueue(): void
    {
        FailsLikeAFlashMessageHook::$failAt = FailsLikeAFlashMessageHook::AFTER_ALL_OPERATIONS;
        CountsCacheClearsHook::$fail        = true;

        $this->updateHeader('After');

        self::assertSame([], (new ReflectionProperty(DataHandler::class, 'recordsToClearCacheFor'))->getValue());
        self::assertFalse($this->runtimeCache()->has('core-datahandler-elementsToBeDeleted'));
        self::assertStringContainsString('::processDatamap_afterAllOperations() threw Error', $this->onlyFailure());
    }

    #[Test]
    public function aHookThatFailsDuringTheWritesIsRethrownAfterTheFinishingSteps(): void
    {
        // The first record of the run is written, with a relation; the hook
        // fails on the second.
        FailsLikeAFlashMessageHook::$failAt     = FailsLikeAFlashMessageHook::POST_PROCESS_FIELD_ARRAY;
        FailsLikeAFlashMessageHook::$onlyForUid = self::OTHER_ELEMENT;
        $dataHandler                            = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['tt_content' => [
            self::ELEMENT       => ['header' => 'After', 'records' => 'tt_content_' . self::OTHER_ELEMENT],
            self::OTHER_ELEMENT => ['header' => 'Other after'],
        ]], [], $this->user);

        try {
            $dataHandler->process_datamap();
            self::fail('The failure during the writes was not rethrown.');
        } catch (RuntimeException $failure) {
            // PHPUnit's own failure is a RuntimeException as well.
            self::assertSame(1790000001, $failure->getCode(), $failure->getMessage());
        }

        self::assertSame('After', $this->headerOf(self::ELEMENT));
        self::assertSame('Other', $this->headerOf(self::OTHER_ELEMENT));
        self::assertContains(self::PAGE, CountsCacheClearsHook::$pages, 'The page written before the failure was not flushed.');
        self::assertSame(1, $this->referenceIndexRowsFrom(self::ELEMENT, 'records'), 'The reference index of the record written before the failure was not updated.');
        self::assertSame([], ToolDataHandler::takeFailures());
        self::assertFalse(
            $this->runtimeCache()->has('core-datahandler-elementsToBeDeleted'),
            'The reset did not run before the rethrow.',
        );
    }

    /**
     * Core copies through a DataHandler of its own. A hook that fails in that
     * nested run fails during the writes of the tool's run: the copy may exist
     * while core has not yet recorded its uid, and a tool reading that record
     * would answer "not copied". The failure is rethrown.
     */
    #[Test]
    public function aHookThatFailsInTheRunCoreCopiesThroughIsRethrown(): void
    {
        FailsLikeAFlashMessageHook::$failAt = FailsLikeAFlashMessageHook::AFTER_ALL_OPERATIONS;
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], ['tt_content' => [self::ELEMENT => ['copy' => self::PAGE]]], $this->user);

        try {
            $dataHandler->process_cmdmap();
            self::fail('The failure in the nested copy run was not rethrown.');
        } catch (Error $failure) {
            self::assertSame('Call to a member function set() on null', $failure->getMessage());
        }

        self::assertSame([], ToolDataHandler::takeFailures());
    }

    /**
     * A hook that starts a ToolDataHandler of its own while the tool's run is
     * still writing: the nested run fails after ITS writes, which is during
     * the writes of the tool's run. The tool's run must rethrow, not read the
     * nested run's step as its own.
     */
    #[Test]
    public function aFailureAfterTheWritesOfANestedToolRunIsDuringTheWritesOfTheOuterOne(): void
    {
        $this->registerHook('processDatamapClass', RunsANestedToolDataHandlerHook::class);
        RunsANestedToolDataHandlerHook::$duringTheWrites = true;
        RunsANestedToolDataHandlerHook::$datamap         = ['tt_content' => [self::OTHER_ELEMENT => ['header' => 'Nested']]];
        FailsLikeAFlashMessageHook::$failAt              = FailsLikeAFlashMessageHook::NESTED_AFTER_ALL_OPERATIONS;

        try {
            $this->updateHeader('Outer');
            self::fail('The nested failure was swallowed by the outer run.');
        } catch (Error $failure) {
            self::assertSame('Call to a member function set() on null', $failure->getMessage());
        }

        self::assertSame([], ToolDataHandler::takeFailures());
    }

    #[Test]
    public function aDatabaseFailureIsDescribedWithoutItsMessage(): void
    {
        FailsLikeAFlashMessageHook::$failAt = FailsLikeAFlashMessageHook::AFTER_ALL_OPERATIONS;
        FailsLikeAFlashMessageHook::$throw  = static fn(): RuntimeException => new RuntimeException(
            'Could not save',
            1790000003,
            new PDOException('SQLSTATE[28000] Access denied for user db-user-secret'),
        );

        $this->updateHeader('After');

        $failure = $this->onlyFailure();
        self::assertStringContainsString('threw RuntimeException: a database error, see the TYPO3 log', $failure);
        self::assertStringNotContainsString('db-user-secret', $failure);
    }

    #[Test]
    public function aCredentialInTheMessageDoesNotReachTheDescription(): void
    {
        FailsLikeAFlashMessageHook::$failAt = FailsLikeAFlashMessageHook::AFTER_ALL_OPERATIONS;
        FailsLikeAFlashMessageHook::$throw  = static fn(): RuntimeException => new RuntimeException(
            'POST https://deepl:userinfo-secret-0815@translate.example.com/v2?auth_key=query-secret-4711 failed with Authorization: Bearer sk-proj-abcdefghijklmnopqrstuvwxyz0123456789',
            1790000004,
        );

        $this->updateHeader('After');

        $failure = $this->onlyFailure();
        self::assertStringContainsString('translate.example.com', $failure);
        self::assertStringNotContainsString('query-secret-4711', $failure);
        self::assertStringNotContainsString('userinfo-secret-0815', $failure);
        self::assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz0123456789', $failure);
    }

    #[Test]
    public function aLongMessageIsCut(): void
    {
        FailsLikeAFlashMessageHook::$failAt = FailsLikeAFlashMessageHook::AFTER_ALL_OPERATIONS;
        FailsLikeAFlashMessageHook::$throw  = static fn(): RuntimeException => new RuntimeException(str_repeat('word ', 400), 1790000005);

        $this->updateHeader('After');

        $failure = $this->onlyFailure();
        self::assertSame(301, mb_strlen($failure));
        self::assertStringEndsWith('…', $failure);
    }

    #[Test]
    public function aCommandRunWhoseHookFailsAfterwardsLeavesTheCommandDoneAndTheFailureRecorded(): void
    {
        FailsLikeAFlashMessageHook::$failAt = FailsLikeAFlashMessageHook::AFTER_FINISH;

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], ['tt_content' => [self::ELEMENT => ['delete' => 1]]], $this->user);
        $dataHandler->process_cmdmap();

        self::assertSame(1, $this->deletedOf(self::ELEMENT));
        self::assertStringContainsString(FailsLikeAFlashMessageHook::class . '::processCmdmap_afterFinish() threw Error', $this->onlyFailure());
        self::assertSame([], $dataHandler->errorLog);
        self::assertContains(self::PAGE, CountsCacheClearsHook::$pages, 'The cache of the page the command changed was not flushed.');
    }

    #[Test]
    public function aRunWithoutAFailingHookReportsNothing(): void
    {
        $dataHandler = $this->updateHeader('After');

        self::assertSame('After', $this->headerOf(self::ELEMENT));
        self::assertSame([], $dataHandler->errorLog);
        self::assertSame([], ToolDataHandler::takeFailures());
    }

    #[Test]
    public function aFailureIsHandedOutOnce(): void
    {
        FailsLikeAFlashMessageHook::$failAt = FailsLikeAFlashMessageHook::AFTER_ALL_OPERATIONS;
        $this->updateHeader('After');

        self::assertCount(1, ToolDataHandler::takeFailures());
        self::assertSame([], ToolDataHandler::takeFailures());
    }

    #[Test]
    public function aNestedInstanceLeavesTheFailureToTheRunAroundIt(): void
    {
        $this->registerHook('processDatamapClass', RunsANestedToolDataHandlerHook::class);
        RunsANestedToolDataHandlerHook::$datamap = ['tt_content' => [self::OTHER_ELEMENT => ['header' => 'Nested']]];

        // The outer run is a plain DataHandler: its hooks run the nested
        // instance, and the failing hook fires inside that nested run.
        $outer = GeneralUtility::makeInstance(DataHandler::class);
        $outer->start(['tt_content' => [self::ELEMENT => ['header' => 'Outer']]], [], $this->user);

        FailsLikeAFlashMessageHook::$failAt = FailsLikeAFlashMessageHook::NESTED_AFTER_ALL_OPERATIONS;

        try {
            $outer->process_datamap();
            self::fail('The nested failure did not reach the run around it.');
        } catch (Error $failure) {
            self::assertSame('Call to a member function set() on null', $failure->getMessage());
        }

        self::assertSame([], ToolDataHandler::takeFailures());
    }

    /**
     * @param array<string, mixed> $moreFields
     */
    private function updateHeader(string $header, array $moreFields = []): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['tt_content' => [self::ELEMENT => ['header' => $header] + $moreFields]], [], $this->user);
        $dataHandler->process_datamap();

        return $dataHandler;
    }

    private function onlyFailure(): string
    {
        $failures = ToolDataHandler::takeFailures();
        self::assertCount(1, $failures);

        return $failures[0];
    }

    private function headerOf(int $uid): string
    {
        $row = $this->connectionPool->getConnectionForTable('tt_content')
            ->select(['header'], 'tt_content', ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);
        self::assertIsString($row['header']);

        return $row['header'];
    }

    private function deletedOf(int $uid): int
    {
        // Without restrictions: the default ones hide the deleted row this reads.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $deleted = $queryBuilder->select('deleted')->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchOne();

        return is_numeric($deleted) ? (int)$deleted : -1;
    }

    private function referenceIndexRowsFrom(int $uid, string $field): int
    {
        return $this->connectionPool->getConnectionForTable('sys_refindex')->count(
            '*',
            'sys_refindex',
            ['tablename' => 'tt_content', 'recuid' => $uid, 'field' => $field],
        );
    }

    private function runtimeCache(): FrontendInterface
    {
        $cache = $this->get('cache.runtime');
        self::assertInstanceOf(FrontendInterface::class, $cache);

        return $cache;
    }
}

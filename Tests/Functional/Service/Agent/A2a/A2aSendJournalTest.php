<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aIntentConflictException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aJournalException;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendIntent;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendState;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The send-intent journal (spec 051 A2A-C4 to A2A-C7, A2A-OPS3).
 *
 * Runs on SQLite in the gate and on MariaDB in CI's MariaDB leg; the unique
 * key that decides concurrent duplicates is the database's, so both must
 * enforce it.
 */
#[CoversClass(A2aSendJournal::class)]
#[CoversClass(A2aSendIntent::class)]
#[CoversClass(A2aSendState::class)]
final class A2aSendJournalTest extends AbstractFunctionalTestCase
{
    private const OWNER = 7;

    private const OTHER_OWNER = 8;

    private const AGENT = 3;

    private const DIGEST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function anIntentIsCommittedAsIntendedBeforeAnythingElse(): void
    {
        $begin = $this->journal()->begin(self::OWNER, self::AGENT, 42, 'm-1', self::DIGEST);

        self::assertTrue($begin->created);
        self::assertSame(A2aSendState::INTENDED, $begin->intent->state);
        self::assertSame(42, $begin->intent->runUid);
        self::assertSame('', $begin->intent->remoteTaskId);
        self::assertNull($begin->intent->errorCode);
        self::assertSame(1, $this->rowCount());
        self::assertFalse($this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE)->isTransactionActive());
    }

    #[Test]
    public function theSameOwnerMessageIdAndDigestNameTheSameOperation(): void
    {
        $first  = $this->journal()->begin(self::OWNER, self::AGENT, 0, 'm-1', self::DIGEST);
        $second = $this->journal()->begin(self::OWNER, self::AGENT, 0, 'm-1', self::DIGEST);

        self::assertFalse($second->created, 'the second call must not be allowed to send');
        self::assertSame($first->intent->uid, $second->intent->uid);
        self::assertSame(1, $this->rowCount());
    }

    #[Test]
    public function theSameMessageIdWithAnotherDigestIsRefused(): void
    {
        $this->journal()->begin(self::OWNER, self::AGENT, 0, 'm-1', self::DIGEST);

        try {
            $this->journal()->begin(self::OWNER, self::AGENT, 0, 'm-1', str_repeat('b', 64));
            self::fail('A conflicting intent was accepted');
        } catch (A2aIntentConflictException) {
        }

        self::assertSame(1, $this->rowCount());
        self::assertSame(A2aSendState::INTENDED, $this->journal()->findByMessageId(self::OWNER, 'm-1')?->state);
    }

    #[Test]
    public function theSameMessageIdForAnotherAgentIsAConflictToo(): void
    {
        $this->journal()->begin(self::OWNER, self::AGENT, 0, 'm-1', self::DIGEST);

        $this->expectException(A2aIntentConflictException::class);
        $this->journal()->begin(self::OWNER, self::AGENT + 1, 0, 'm-1', self::DIGEST);
    }

    #[Test]
    public function anotherOwnerHasItsOwnIntentUnderTheSameMessageId(): void
    {
        $mine   = $this->journal()->begin(self::OWNER, self::AGENT, 0, 'm-1', self::DIGEST);
        $theirs = $this->journal()->begin(self::OTHER_OWNER, self::AGENT, 0, 'm-1', self::DIGEST);

        self::assertTrue($theirs->created);
        self::assertNotSame($mine->intent->uid, $theirs->intent->uid);
    }

    #[Test]
    public function noIntentIsWrittenInsideAnOpenTransaction(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE);
        $connection->beginTransaction();

        try {
            $this->journal()->begin(self::OWNER, self::AGENT, 0, 'm-1', self::DIGEST);
            self::fail('An intent was written inside a transaction a rollback could undo');
        } catch (A2aJournalException $e) {
            self::assertSame(1799100210, $e->getCode());
        } finally {
            $connection->rollBack();
        }

        self::assertSame(0, $this->rowCount());
    }

    #[Test]
    public function anIntentNeedsALocalActor(): void
    {
        $this->expectException(A2aJournalException::class);
        $this->expectExceptionCode(1799100211);

        $this->journal()->begin(0, self::AGENT, 0, 'm-1', self::DIGEST);
    }

    #[Test]
    public function anIntentSettlesExactlyOnce(): void
    {
        $intent   = $this->journal()->begin(self::OWNER, self::AGENT, 0, 'm-1', self::DIGEST)->intent;
        $accepted = $this->journal()->accept($intent, 'task-1', 'ctx-1', 'TASK_STATE_SUBMITTED');

        self::assertSame(A2aSendState::ACCEPTED, $accepted->state);
        self::assertSame('task-1', $accepted->remoteTaskId);
        self::assertSame('ctx-1', $accepted->remoteContextId);

        foreach ([
            fn (): A2aSendIntent => $this->journal()->refuse($intent, -32001, 'TASK_NOT_FOUND', 'x'),
            fn (): A2aSendIntent => $this->journal()->markOutcomeUnknown($intent, 'TIMEOUT', 'x'),
            fn (): A2aSendIntent => $this->journal()->answer($intent, 'ctx'),
            fn (): A2aSendIntent => $this->journal()->accept($intent, 'task-2', 'ctx-2', 'TASK_STATE_WORKING'),
        ] as $second) {
            try {
                $second();
                self::fail('A settled intent took a second outcome');
            } catch (A2aJournalException $e) {
                self::assertSame(1799100212, $e->getCode());
            }
        }

        $stored = $this->journal()->findForOwner(self::OWNER, $intent->uid);
        self::assertSame(A2aSendState::ACCEPTED, $stored?->state);
        self::assertSame('task-1', $stored->remoteTaskId);
    }

    #[Test]
    public function everyOutcomeKeepsWhatItNeeds(): void
    {
        $refused = $this->journal()->refuse($this->intent('r'), -32602, 'INVALID_PARAMS', 'bad');
        $local   = $this->journal()->refuse($this->intent('l'), null, 'RESPONSE_TOO_LARGE', 'too large');
        $unknown = $this->journal()->markOutcomeUnknown($this->intent('u'), 'TIMEOUT_AFTER_SEND', 'read timeout');
        $answer  = $this->journal()->answer($this->intent('a'), 'ctx-a');

        self::assertSame([A2aSendState::REFUSED, -32602, 'INVALID_PARAMS'], [$refused->state, $refused->errorCode, $refused->errorReason]);
        self::assertSame([A2aSendState::REFUSED, null, 'RESPONSE_TOO_LARGE'], [$local->state, $local->errorCode, $local->errorReason]);
        self::assertSame([A2aSendState::OUTCOME_UNKNOWN, 'TIMEOUT_AFTER_SEND'], [$unknown->state, $unknown->errorReason]);
        self::assertSame([A2aSendState::ANSWERED, 'ctx-a', ''], [$answer->state, $answer->remoteContextId, $answer->remoteTaskId]);
    }

    #[Test]
    public function theHandleSurvivesARestart(): void
    {
        $intent = $this->intent('m-1');
        $this->journal()->accept($intent, 'task-1', 'ctx-1', 'TASK_STATE_SUBMITTED');

        // A new process: the connection is gone and the service is new.
        $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE)->close();
        $afterRestart = new A2aSendJournal(new ConnectionPool());

        $handle = $afterRestart->findByRemoteTask(self::OWNER, self::AGENT, 'task-1');
        self::assertSame($intent->uid, $handle?->uid);
        self::assertSame(A2aSendState::ACCEPTED, $handle->state);
    }

    #[Test]
    public function anotherOwnerCannotReadAHandle(): void
    {
        $intent = $this->intent('m-1');
        $this->journal()->accept($intent, 'task-1', 'ctx-1', 'TASK_STATE_SUBMITTED');

        self::assertNull($this->journal()->findForOwner(self::OTHER_OWNER, $intent->uid));
        self::assertNull($this->journal()->findByRemoteTask(self::OTHER_OWNER, self::AGENT, 'task-1'));
        self::assertNull($this->journal()->findByMessageId(self::OTHER_OWNER, 'm-1'));
        self::assertSame([], $this->journal()->ownedRemoteTaskIds(self::OTHER_OWNER, self::AGENT, ['task-1']));
        self::assertSame(['task-1'], $this->journal()->ownedRemoteTaskIds(self::OWNER, self::AGENT, ['task-1', 'task-x', '']));
        self::assertSame([], $this->journal()->ownedRemoteTaskIds(self::OWNER, self::AGENT + 1, ['task-1']));
    }

    #[Test]
    public function anotherOwnerCannotRecordTheRemoteStateOfAHandle(): void
    {
        $intent = $this->journal()->accept($this->intent('m-1'), 'task-1', 'ctx-1', 'TASK_STATE_SUBMITTED');
        $forged = new A2aSendIntent($intent->uid, self::OTHER_OWNER, self::AGENT, 0, 'm-1', self::DIGEST, A2aSendState::ACCEPTED);

        $this->journal()->recordRemoteState($forged, 'TASK_STATE_CANCELED');
        $this->journal()->recordRemoteState($intent, 'TASK_STATE_WORKING');

        self::assertSame('TASK_STATE_WORKING', $this->journal()->findForOwner(self::OWNER, $intent->uid)?->lastRemoteState);
    }

    #[Test]
    public function theDatabaseItselfRefusesASecondRowForOneOwnerAndMessageId(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE);
        $row        = ['owner_uid' => self::OWNER, 'agent_uid' => self::AGENT, 'message_id' => 'm-1', 'request_sha256' => self::DIGEST];
        $connection->insert(A2aSendJournal::TABLE, $row);

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert(A2aSendJournal::TABLE, $row);
    }

    #[Test]
    public function aConcurrentSendThatAnotherProcessJournalledFirstSendsNothingHere(): void
    {
        // The other process committed the same intent first. This process
        // tries the insert, hits the unique key, reads that row back, and
        // must not be told it created the intent: it sends nothing.
        $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE)->insert(A2aSendJournal::TABLE, [
            'owner_uid'      => self::OWNER,
            'agent_uid'      => self::AGENT,
            'message_id'     => 'm-race',
            'request_sha256' => self::DIGEST,
            'state'          => 'intended',
        ]);

        $begin = $this->journal()->begin(self::OWNER, self::AGENT, 0, 'm-race', self::DIGEST);

        self::assertFalse($begin->created);
        self::assertSame(1, $this->rowCount());
    }

    #[Test]
    public function anUnreadableStateIsReadAsAnUnknownOutcomeNeverAsSendable(): void
    {
        $intent = $this->intent('m-1');
        $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE)
            ->update(A2aSendJournal::TABLE, ['state' => 'garbled'], ['uid' => $intent->uid]);

        self::assertSame(A2aSendState::OUTCOME_UNKNOWN, $this->journal()->findForOwner(self::OWNER, $intent->uid)?->state);
    }

    private function intent(string $messageId): A2aSendIntent
    {
        return $this->journal()->begin(self::OWNER, self::AGENT, 0, $messageId, self::DIGEST)->intent;
    }

    private function rowCount(): int
    {
        return $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE)->count('*', A2aSendJournal::TABLE, []);
    }

    private function journal(): A2aSendJournal
    {
        // A private service nothing in the container uses yet; built the way
        // the MCP repository tests build theirs.
        return new A2aSendJournal($this->getService(ConnectionPool::class));
    }
}

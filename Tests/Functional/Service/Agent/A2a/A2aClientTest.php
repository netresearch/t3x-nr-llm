<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aSendOutcome;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aAgentUnavailableException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aCallFailedException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aIntentConflictException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aTaskHandleNotFoundException;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorCode;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aSendMessageConfiguration;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The A2A client over a scripted transport (spec 051 A2A-C3 to A2A-C8, A2A-P2,
 * A2A-C2 tenant).
 *
 * The spec names this a unit test; it is functional because "the intent is
 * committed before the first byte" is a statement about a database row.
 */
#[CoversClass(A2aClient::class)]
#[CoversClass(A2aSendOutcome::class)]
final class A2aClientTest extends AbstractFunctionalTestCase
{
    private const OWNER = 11;

    private const OTHER_OWNER = 12;

    private ScriptedA2aTransport $transport;

    private A2aClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new ScriptedA2aTransport();
        $this->client    = new A2aClient($this->journal(), $this->transport, new A2aResponseParser());
    }

    #[Test]
    public function theIntentIsCommittedBeforeTheFirstByteLeaves(): void
    {
        $seen = null;
        $this->transport->then(function (A2aHttpRequest $request) use (&$seen): A2aTransportResult {
            $connection = $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE);
            $seen       = [
                'transaction' => $connection->isTransactionActive(),
                'state'       => $connection->select(['state'], A2aSendJournal::TABLE, ['message_id' => 'm-1'])->fetchOne(),
            ];

            return A2aTransportResult::maybeSent('TIMEOUT_AFTER_SEND', 'x');
        });

        $this->client->sendMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'));

        self::assertSame(['transaction' => false, 'state' => 'intended'], $seen);
    }

    #[Test]
    public function aTaskIsAcceptedAndItsIdsAreStored(): void
    {
        $this->transport->thenJson(['result' => ['task' => [
            'id'        => 'task-1',
            'contextId' => 'ctx-1',
            'status'    => ['state' => 'TASK_STATE_SUBMITTED'],
        ]]]);

        $outcome = $this->client->sendMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'), A2aSendMessageConfiguration::returningImmediately(), 99);

        self::assertTrue($outcome->sent);
        self::assertSame(A2aSendState::ACCEPTED, $outcome->state());
        self::assertTrue($outcome->result?->isTask());
        self::assertSame(['task-1', 'ctx-1', 'TASK_STATE_SUBMITTED', 99], [$outcome->intent->remoteTaskId, $outcome->intent->remoteContextId, $outcome->intent->lastRemoteState, $outcome->intent->runUid]);
    }

    #[Test]
    public function aMessageIsAnAnswerAndNoTask(): void
    {
        $this->transport->thenJson(['result' => ['message' => [
            'messageId' => 'reply',
            'contextId' => 'ctx-2',
            'role'      => 'ROLE_AGENT',
            'parts'     => [['text' => 'direct']],
        ]]]);

        $outcome = $this->client->sendMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'));

        self::assertSame(A2aSendState::ANSWERED, $outcome->state());
        self::assertFalse($outcome->result?->isTask());
        self::assertSame('direct', $outcome->result->message?->text());
        self::assertSame('', $outcome->intent->remoteTaskId);
    }

    #[Test]
    public function theRequestCarriesTheVersionHeaderAndExactlyTheRequestedParams(): void
    {
        $this->transport->thenJson(['result' => ['task' => ['id' => 't', 'status' => ['state' => 'TASK_STATE_SUBMITTED']]]]);

        $this->client->sendMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'), A2aSendMessageConfiguration::returningImmediately());

        $request = $this->transport->requests[0];
        self::assertSame('POST', $request->method);
        self::assertSame('https://agent.example/a2a/jsonrpc', $request->url);
        self::assertSame('1.0', $request->headers['A2A-Version'] ?? null);
        self::assertSame('application/json', $request->headers['Content-Type'] ?? null);
        self::assertSame('vault-credential-id', $request->credential);
        self::assertSame([5, 30, 1048576], [$request->connectTimeoutSeconds, $request->timeoutSeconds, $request->maxResponseBytes]);

        $envelope = ScriptedA2aTransport::envelopeOf($request);
        self::assertSame('SendMessage', $envelope['method']);
        self::assertSame(
            ['message' => ['messageId' => 'm-1', 'role' => 'ROLE_USER', 'parts' => [['text' => 'hello']]], 'configuration' => ['returnImmediately' => true]],
            $envelope['params'],
        );
    }

    #[Test]
    public function theTenantOfTheSelectedInterfaceTravelsWithEveryRequest(): void
    {
        $agent = $this->agent(['interface_tenant' => 'acme']);
        $this->transport->thenJson(['result' => ['task' => ['id' => 't', 'status' => ['state' => 'TASK_STATE_SUBMITTED']]]]);

        $this->client->sendMessage($agent, self::OWNER, A2aMessage::userText('m-1', 'hello'));

        $params = ScriptedA2aTransport::envelopeOf($this->transport->requests[0])['params'] ?? null;
        self::assertIsArray($params);
        self::assertSame('acme', $params['tenant'] ?? null);
    }

    #[Test]
    public function theSameRequestUnderTheSameMessageIdIsSentOnce(): void
    {
        $this->transport->thenJson(['result' => ['task' => ['id' => 'task-1', 'status' => ['state' => 'TASK_STATE_WORKING']]]]);
        $agent = $this->agent();

        $first  = $this->client->sendMessage($agent, self::OWNER, A2aMessage::userText('m-1', 'hello'));
        $second = $this->client->sendMessage($agent, self::OWNER, A2aMessage::userText('m-1', 'hello'));

        self::assertTrue($first->sent);
        self::assertFalse($second->sent);
        self::assertSame($first->intent->uid, $second->intent->uid);
        self::assertSame(A2aSendState::ACCEPTED, $second->state());
        self::assertCount(1, $this->transport->requests);
    }

    #[Test]
    public function anotherRequestUnderTheSameMessageIdIsRefusedBeforeAnythingIsSent(): void
    {
        $this->transport->thenJson(['result' => ['task' => ['id' => 'task-1', 'status' => ['state' => 'TASK_STATE_WORKING']]]]);
        $agent = $this->agent();
        $this->client->sendMessage($agent, self::OWNER, A2aMessage::userText('m-1', 'hello'));

        try {
            $this->client->sendMessage($agent, self::OWNER, A2aMessage::userText('m-1', 'something else'));
            self::fail('A different request under a used message id was accepted');
        } catch (A2aIntentConflictException) {
        }

        self::assertCount(1, $this->transport->requests);
    }

    #[Test]
    public function anUnknownOutcomeIsNeverSentAgainAndANewIntentGetsANewMessageId(): void
    {
        $this->transport
            ->then(static fn (): A2aTransportResult => A2aTransportResult::maybeSent('TIMEOUT_AFTER_SEND', 'read timeout'))
            ->thenJson(['result' => ['task' => ['id' => 'task-2', 'status' => ['state' => 'TASK_STATE_SUBMITTED']]]]);
        $agent = $this->agent();

        $lost  = $this->client->sendMessage($agent, self::OWNER, A2aMessage::userText('m-1', 'hello'));
        $again = $this->client->sendMessage($agent, self::OWNER, A2aMessage::userText('m-1', 'hello'));

        self::assertSame(A2aSendState::OUTCOME_UNKNOWN, $lost->state());
        self::assertSame('TIMEOUT_AFTER_SEND', $lost->intent->errorReason);
        self::assertFalse($again->sent, 'the same intent is not sent a second time');
        self::assertSame(A2aSendState::OUTCOME_UNKNOWN, $again->state());
        self::assertCount(1, $this->transport->requests);

        $resent = $this->client->sendMessage($agent, self::OWNER, A2aMessage::userText(A2aClient::newMessageId(), 'hello'));
        self::assertTrue($resent->sent);
        self::assertNotSame($lost->intent->uid, $resent->intent->uid);
        self::assertCount(2, $this->transport->requests);
    }

    /**
     * @return iterable<string, array{A2aTransportResult, A2aSendState, string, ?int}>
     */
    public static function transportOutcomes(): iterable
    {
        yield 'address refused, nothing sent' => [A2aTransportResult::notSent('ADDRESS_REFUSED', 'private'), A2aSendState::REFUSED, 'ADDRESS_REFUSED', null];
        yield 'connect failed, nothing sent' => [A2aTransportResult::notSent('CONNECT_FAILED', 'refused'), A2aSendState::REFUSED, 'CONNECT_FAILED', null];
        yield 'timeout after send' => [A2aTransportResult::maybeSent('TIMEOUT_AFTER_SEND', 'x'), A2aSendState::OUTCOME_UNKNOWN, 'TIMEOUT_AFTER_SEND', null];
        yield 'connection reset' => [A2aTransportResult::maybeSent('CONNECTION_LOST', 'reset'), A2aSendState::OUTCOME_UNKNOWN, 'CONNECTION_LOST', null];
        yield '5xx without a JSON-RPC body' => [A2aTransportResult::response(502, 'text/html', '', '<h1>Bad Gateway</h1>', false), A2aSendState::OUTCOME_UNKNOWN, 'SERVER_ERROR_WITHOUT_RESPONSE', null];
        yield '503 with an empty body' => [A2aTransportResult::response(503, '', '', '', false), A2aSendState::OUTCOME_UNKNOWN, 'SERVER_ERROR_WITHOUT_RESPONSE', null];
        yield 'response above the size limit' => [A2aTransportResult::response(200, 'application/json', '', '{"jsonrpc":"2.0"', true), A2aSendState::REFUSED, 'RESPONSE_TOO_LARGE', null];
        yield 'redirect' => [A2aTransportResult::response(307, '', '', '', false), A2aSendState::REFUSED, 'REDIRECT_NOT_FOLLOWED', null];
        yield '200 that is not JSON-RPC' => [A2aTransportResult::response(200, 'application/json', '', '{"ok":true}', false), A2aSendState::REFUSED, 'INVALID_AGENT_RESPONSE', null];
        yield '200 that is HTML' => [A2aTransportResult::response(200, 'text/html', '', '<html></html>', false), A2aSendState::REFUSED, 'INVALID_AGENT_RESPONSE', null];
        yield 'a response to another request' => [A2aTransportResult::response(200, 'application/json', '', '{"jsonrpc":"2.0","id":"someone-else","result":{"task":{"id":"t","status":{"state":"TASK_STATE_WORKING"}}}}', false), A2aSendState::REFUSED, 'INVALID_AGENT_RESPONSE', null];
    }

    #[Test]
    #[DataProvider('transportOutcomes')]
    public function everyTransportResultEndsInExactlyOneJournalState(A2aTransportResult $result, A2aSendState $state, string $reason, ?int $code): void
    {
        $this->transport->then(static fn (): A2aTransportResult => $result);

        $outcome = $this->client->sendMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'));

        self::assertSame($state, $outcome->state());
        self::assertSame($reason, $outcome->intent->errorReason);
        self::assertSame($code, $outcome->intent->errorCode);
        self::assertNull($outcome->result);
        self::assertCount(1, $this->transport->requests, 'nothing is retried');
    }

    #[Test]
    public function aJsonRpcErrorIsARefusalWithCodeAndReason(): void
    {
        $this->transport->thenJson(['error' => [
            'code'    => -32005,
            'message' => 'Content type not supported',
            'data'    => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'CONTENT_TYPE_NOT_SUPPORTED', 'domain' => 'a2a-protocol.org']],
        ]]);

        $outcome = $this->client->sendMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'));

        self::assertSame(A2aSendState::REFUSED, $outcome->state());
        self::assertSame(-32005, $outcome->intent->errorCode);
        self::assertSame('CONTENT_TYPE_NOT_SUPPORTED', $outcome->intent->errorReason);
        self::assertTrue($outcome->remoteError?->is(A2aErrorCode::CONTENT_TYPE_NOT_SUPPORTED));
    }

    #[Test]
    public function aResponseOfAnUnknownKindIsAnUnknownOutcome(): void
    {
        $this->transport->thenJson(['result' => ['liveSession' => ['id' => 'x']]]);

        $outcome = $this->client->sendMessage($this->agent(), self::OWNER, A2aMessage::userText('m-1', 'hello'));

        self::assertSame(A2aSendState::OUTCOME_UNKNOWN, $outcome->state());
        self::assertSame('UNKNOWN_RESPONSE_KIND', $outcome->intent->errorReason);
    }

    /**
     * @return iterable<string, array{array<string, int|string>, string}>
     */
    public static function unusableRecords(): iterable
    {
        yield 'no data class' => [['data_class' => ''], 'no data class'];
        yield 'disabled' => [['enabled' => 0], 'not enabled'];
        yield 'card not imported' => [['interface_url' => ''], 'not been imported'];
        yield 'card changed' => [['card_changed' => 1], 'save the record again'];
    }

    /**
     * @param array<string, int|string> $values
     */
    #[Test]
    #[DataProvider('unusableRecords')]
    public function anUnusableRecordJournalsAndSendsNothing(array $values, string $why): void
    {
        $agent = $this->agent($values);

        try {
            $this->client->sendMessage($agent, self::OWNER, A2aMessage::userText('m-1', 'hello'));
            self::fail('An unusable record was used');
        } catch (A2aAgentUnavailableException $e) {
            self::assertStringContainsString($why, $e->getMessage());
        }

        self::assertSame([], $this->transport->requests);
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE)->count('*', A2aSendJournal::TABLE, []));
    }

    #[Test]
    public function theOwnerReadsTheTaskAndItsStateIsRecordedOnTheHandle(): void
    {
        $agent = $this->agent();
        $this->acceptTask($agent, 'task-1');
        $this->transport->thenJson(['result' => [
            'id'        => 'task-1',
            'status'    => ['state' => 'TASK_STATE_COMPLETED', 'timestamp' => '2026-11-11T10:00:00.123Z'],
            'artifacts' => [['artifactId' => 'a', 'parts' => [['text' => 'done']]]],
        ]]);

        $task = $this->client->getTask($agent, self::OWNER, 'task-1', 0);

        self::assertSame(A2aTaskState::COMPLETED, $task->status->state);
        self::assertSame('done', $task->artifactText());
        self::assertSame(['id' => 'task-1', 'historyLength' => 0], ScriptedA2aTransport::envelopeOf($this->transport->requests[1])['params']);
        self::assertSame('TASK_STATE_COMPLETED', $this->journal()->findByRemoteTask(self::OWNER, (int) $agent->getUid(), 'task-1')?->lastRemoteState);
    }

    #[Test]
    public function anotherUserCannotReadOrCancelAHandleAndTheRemoteIsNotAsked(): void
    {
        $agent = $this->agent();
        $this->acceptTask($agent, 'task-1');

        foreach ([
            fn (): mixed => $this->client->getTask($agent, self::OTHER_OWNER, 'task-1'),
            fn (): mixed => $this->client->cancelTask($agent, self::OTHER_OWNER, 'task-1'),
            fn (): mixed => $this->client->getTask($agent, self::OWNER, 'task-nobody-sent'),
        ] as $call) {
            try {
                $call();
                self::fail('A task without a handle was reached');
            } catch (A2aTaskHandleNotFoundException) {
            }
        }

        self::assertCount(1, $this->transport->requests, 'only the original send went out');
    }

    #[Test]
    public function theOwnerCancelsAndTheAnswerIsRecorded(): void
    {
        $agent = $this->agent();
        $this->acceptTask($agent, 'task-1');
        $this->transport->thenJson(['result' => ['id' => 'task-1', 'status' => ['state' => 'TASK_STATE_CANCELED']]]);

        $task = $this->client->cancelTask($agent, self::OWNER, 'task-1');

        self::assertSame(A2aTaskState::CANCELED, $task->status->state);
        self::assertSame('CancelTask', ScriptedA2aTransport::envelopeOf($this->transport->requests[1])['method']);
        self::assertSame('TASK_STATE_CANCELED', $this->journal()->findByRemoteTask(self::OWNER, (int) $agent->getUid(), 'task-1')?->lastRemoteState);
    }

    #[Test]
    public function aRemoteErrorOnGetTaskIsReportedWithItsCode(): void
    {
        $agent = $this->agent();
        $this->acceptTask($agent, 'task-1');
        $this->transport->thenJson(['error' => ['code' => -32002, 'message' => 'Task cannot be canceled']]);

        try {
            $this->client->cancelTask($agent, self::OWNER, 'task-1');
            self::fail('A remote error was swallowed');
        } catch (A2aCallFailedException $e) {
            self::assertSame(-32002, $e->remoteError?->code);
            self::assertSame('TASK_NOT_CANCELABLE', $e->reason);
        }
    }

    #[Test]
    public function aRemoteListReturnsOnlyTheCallersTasksAndCreatesNoHandle(): void
    {
        $agent = $this->agent();
        $this->acceptTask($agent, 'mine');
        $this->client = new A2aClient($this->journal(), $this->transport, new A2aResponseParser());
        $other        = new A2aClient($this->journal(), $this->transport, new A2aResponseParser());
        $this->transport->thenJson(['result' => ['task' => ['id' => 'theirs', 'status' => ['state' => 'TASK_STATE_SUBMITTED']]]]);
        $other->sendMessage($agent, self::OTHER_OWNER, A2aMessage::userText('m-other', 'hi'));
        $rowsBefore = $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE)->count('*', A2aSendJournal::TABLE, []);

        $this->transport->thenJson(['result' => [
            'tasks' => [
                ['id' => 'theirs', 'status' => ['state' => 'TASK_STATE_WORKING']],
                ['id' => 'mine', 'status' => ['state' => 'TASK_STATE_WORKING']],
                ['id' => 'nobodys', 'status' => ['state' => 'TASK_STATE_WORKING']],
            ],
            'nextPageToken' => 'next',
            'pageSize'      => 3,
            'totalSize'     => 40,
        ]]);

        $list = $this->client->listTasks($agent, self::OWNER);

        self::assertSame(['mine'], array_map(static fn (A2aTask $task): string => $task->id, $list->tasks));
        self::assertSame(1, $list->totalSize, "the remote total counts other users' tasks and is not passed on");
        self::assertSame('next', $list->nextPageToken);
        self::assertSame($rowsBefore, $this->getConnectionPool()->getConnectionForTable(A2aSendJournal::TABLE)->count('*', A2aSendJournal::TABLE, []));
    }

    private function acceptTask(A2aRemoteAgent $agent, string $taskId): void
    {
        $this->transport->thenJson(['result' => ['task' => ['id' => $taskId, 'status' => ['state' => 'TASK_STATE_SUBMITTED']]]]);
        $outcome = $this->client->sendMessage($agent, self::OWNER, A2aMessage::userText(A2aClient::newMessageId(), 'hello'));
        self::assertSame(A2aSendState::ACCEPTED, $outcome->state());
    }

    /**
     * @param array<string, int|string> $values
     */
    private function agent(array $values = []): A2aRemoteAgent
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_a2a_agent');
        $connection->insert('tx_nrllm_a2a_agent', $values + [
            'identifier'    => 'agent' . random_int(1000, 9999),
            'title'         => 'Agent',
            'card_url'      => 'https://agent.example/.well-known/agent-card.json',
            'interface_url' => 'https://agent.example/a2a/jsonrpc',
            'credential'    => 'vault-credential-id',
            'data_class'    => ToolDataClass::PUBLIC_CONTENT->value,
            'enabled'       => 1,
        ]);

        $agent = $this->getService(A2aRemoteAgentRepository::class)->findByUid((int) $connection->lastInsertId());
        self::assertInstanceOf(A2aRemoteAgent::class, $agent);

        return $agent;
    }

    private function journal(): A2aSendJournal
    {
        return new A2aSendJournal($this->getService(ConnectionPool::class));
    }
}

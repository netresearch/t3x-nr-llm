<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Closure;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aAuthenticator;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClientRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aPushConfigStore;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aPushDeliverer;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcServer;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerSettings;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aTaskView;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskProjector;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\ScriptedA2aTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The push notification sender of the server role (spec 051 A2A-PUSH1 to
 * A2A-PUSH5): owner scoping, sealed and masked credential, no call from the
 * request path, the outbox written with the event, each delivery outcome,
 * and no attempt after a delete.
 */
#[CoversClass(A2aPushConfigStore::class)]
#[CoversClass(A2aPushDeliverer::class)]
#[CoversClass(A2aRpcServer::class)]
#[CoversClass(A2aTaskProjector::class)]
final class A2aPushSenderTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;

    /** A public IP literal: the guard needs no DNS for it. */
    private const HOOK = 'https://93.184.215.14/hook';

    private const SECRET = 'receiver-secret-0123456789';

    private const TOKEN = 'client-token-abc';

    private ScriptedA2aTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServer();
        $this->serverSettings(['push' => '1'] + self::SERVER_SETTINGS);
        $this->transport = new ScriptedA2aTransport();
    }

    protected function tearDown(): void
    {
        $this->serverSettings(null);
        $this->getService(CacheManager::class)->getCache('ratelimiter')->flush();
        parent::tearDown();
    }

    /**
     * A2A-OPS2, A2A-P7: the card declares push while it is on; off (the
     * default), every push method and an embedded config answer -32003.
     */
    #[Test]
    public function pushIsRefusedWhileItIsSwitchedOff(): void
    {
        self::assertTrue(self::j($this->agentCard(), 'capabilities.pushNotifications'), 'declared while on');
        $this->serverSettings(self::SERVER_SETTINGS);
        self::assertFalse(self::j($this->agentCard(), 'capabilities.pushNotifications'));
        $task = $this->newTask();

        foreach (['CreateTaskPushNotificationConfig', 'GetTaskPushNotificationConfig', 'ListTaskPushNotificationConfigs', 'DeleteTaskPushNotificationConfig'] as $method) {
            self::assertSame(-32003, $this->errorOf($this->call($method, ['taskId' => $task, 'id' => 'c-1', 'url' => self::HOOK]))['code'], $method);
        }

        $embedded = $this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true, 'taskPushNotificationConfig' => ['url' => self::HOOK]]]);
        self::assertSame(-32003, $this->errorOf($embedded)['code']);
        self::assertSame(0, $this->rows(A2aPushConfigStore::TABLE));
    }

    /**
     * A2A-PUSH1, A2A-PUSH2: Create, Get, List and Delete on the client's own
     * task; the credential is sealed at rest and masked in every answer, the
     * token is returned and stored sealed too.
     */
    #[Test]
    public function configsAreStoredSealedAndAnsweredMasked(): void
    {
        $task    = $this->newTask();
        $created = $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));

        self::assertSame(['id' => 'c-1', 'taskId' => $task, 'url' => self::HOOK, 'token' => self::TOKEN, 'authentication' => ['scheme' => 'Bearer', 'credentials' => A2aPushConfigStore::MASK]], $created);
        self::assertSame($created, $this->resultOf($this->call('GetTaskPushNotificationConfig', ['taskId' => $task, 'id' => 'c-1'])));
        self::assertSame(['configs' => [$created], 'nextPageToken' => ''], $this->resultOf($this->call('ListTaskPushNotificationConfigs', ['taskId' => $task])));

        $row = $this->db(A2aPushConfigStore::TABLE)->select(['token', 'credentials'], A2aPushConfigStore::TABLE)->fetchAssociative();
        self::assertIsArray($row);
        self::assertStringNotContainsString(self::SECRET, self::text($row['credentials']));
        self::assertStringNotContainsString(self::TOKEN, self::text($row['token']));
        self::assertSame(self::SECRET, $this->getService(AgentStateCodec::class)->decode(self::text($row['credentials']), AgentStateCodec::PURPOSE_A2A_PUSH_CREDENTIAL));

        self::assertSame([], $this->resultOf($this->call('DeleteTaskPushNotificationConfig', ['taskId' => $task, 'id' => 'c-1'])));
        self::assertSame([], $this->resultOf($this->call('DeleteTaskPushNotificationConfig', ['taskId' => $task, 'id' => 'c-1'])), 'Delete is idempotent');
        self::assertSame(-32001, $this->errorOf($this->call('GetTaskPushNotificationConfig', ['taskId' => $task, 'id' => 'c-1']))['code']);
        self::assertSame(0, $this->rows(A2aPushConfigStore::TABLE));
    }

    /**
     * A2A-SEC2: another client's task answers like an unknown one, on every
     * push method, and its configs stay as they are.
     */
    #[Test]
    public function anotherClientsTaskIsNotFound(): void
    {
        $theirs = $this->newTask(4);
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($theirs, 'c-1'), 4));

        foreach (['CreateTaskPushNotificationConfig' => $this->config($theirs, 'c-1'), 'GetTaskPushNotificationConfig' => ['taskId' => $theirs, 'id' => 'c-1'], 'ListTaskPushNotificationConfigs' => ['taskId' => $theirs], 'DeleteTaskPushNotificationConfig' => ['taskId' => $theirs, 'id' => 'c-1']] as $method => $params) {
            $foreign = $this->call($method, $params, 1);
            $unknown = $this->call($method, ['taskId' => '00000000-0000-4000-8000-000000000000'] + $params, 1);
            self::assertSame(-32001, $this->errorOf($foreign)['code'], $method);
            self::assertSame(json_encode($foreign), json_encode($unknown), $method);
        }

        self::assertSame(1, $this->rows(A2aPushConfigStore::TABLE), 'the owner keeps the config');
    }

    /**
     * A2A-PUSH1: no update method; a Create with an existing id replaces the
     * config, and its pending deliveries end with it. The per-task bound
     * counts configs, a replacement included.
     */
    #[Test]
    public function aCreateWithAnExistingIdReplacesTheConfig(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->cancel($task);
        self::assertSame(1, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'pending']));

        $replaced = $this->resultOf($this->call('CreateTaskPushNotificationConfig', ['taskId' => $task, 'id' => 'c-1', 'url' => 'https://93.184.215.15/other']));

        self::assertSame('https://93.184.215.15/other', $replaced['url'] ?? null);
        self::assertArrayNotHasKey('authentication', $replaced);
        self::assertSame(1, $this->rows(A2aPushConfigStore::TABLE));
        self::assertSame(0, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'pending']), 'the old config took its pending job with it');

        foreach (['c-2', 'c-3', 'c-4'] as $id) {
            $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, $id)));
        }

        self::assertSame(-32602, $this->errorOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-5')))['code'], 'four per task');
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-4')));
    }

    /**
     * A2A-PUSH4, A2A-SEC5: a webhook must be https and pass the address
     * guard when it is created; nothing is stored otherwise.
     */
    #[Test]
    public function aWebhookMustBeHttpsAndPublic(): void
    {
        $task = $this->newTask();
        foreach (['http://93.184.215.14/hook', 'https://10.0.0.5/hook', 'https://127.0.0.1/hook', 'https://[::1]/hook', 'https://user:pw@93.184.215.14/hook', 'ftp://93.184.215.14/'] as $url) {
            self::assertSame(-32602, $this->errorOf($this->call('CreateTaskPushNotificationConfig', ['taskId' => $task, 'url' => $url]))['code'], $url);
        }

        $refused = $this->call('CreateTaskPushNotificationConfig', ['taskId' => $task, 'url' => self::HOOK, 'authentication' => ['scheme' => 'Basic', 'credentials' => 'x']]);
        self::assertSame(-32602, $this->errorOf($refused)['code'], 'Bearer is the one scheme');
        self::assertSame(0, $this->rows(A2aPushConfigStore::TABLE));
    }

    /**
     * A2A-PUSH1, A2A-PUSH3: a config embedded in SendMessage is stored with
     * the task, so the task's first event already has its delivery job; no
     * request makes a webhook call.
     */
    #[Test]
    public function anEmbeddedConfigIsStoredWithTheTaskAndItsFirstEventIsQueued(): void
    {
        $task = $this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true, 'taskPushNotificationConfig' => ['id' => 'e-1', 'url' => self::HOOK, 'authentication' => ['scheme' => 'Bearer', 'credentials' => self::SECRET]]]]));

        self::assertSame('e-1', self::j($this->resultOf($this->call('GetTaskPushNotificationConfig', ['taskId' => $task['id'], 'id' => 'e-1'])), 'id'));
        // The worker projects the task's first revision; its job is written
        // with the event.
        self::assertSame(1, $this->getService(A2aTaskProjector::class)->projectPending());
        self::assertSame(1, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'pending']));
        self::assertSame([], $this->transport->requests, 'nothing was sent from the request');
    }

    /**
     * A2A-PUSH3, A2A-PUSH4: the worker sends a bare StreamResponse with the
     * task as it is now, the credential in Authorization only, never the
     * token; older pending jobs of the config are superseded by it.
     */
    #[Test]
    public function aDeliveryCarriesTheTaskAndTheCredentialOnly(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->cancel($task);
        $this->db(A2aPushConfigStore::OUTBOX_TABLE)->insert(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => $this->configUid(), 'task_uid' => $this->taskUid($task), 'revision' => 1, 'state' => 'pending', 'deadline' => time() + 100]);

        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::response(204, '', '', '', false));
        self::assertSame(1, $this->deliverer()->deliverDue(time()));

        self::assertCount(1, $this->transport->requests);
        $request = $this->transport->requests[0];
        self::assertSame('POST', $request->method);
        self::assertSame(self::HOOK, $request->url);
        self::assertSame(A2aUrlGuardFactory::GROUP_EXTERNAL, $request->egressGroup);
        self::assertSame(['Content-Type' => 'application/a2a+json', 'Authorization' => 'Bearer ' . self::SECRET], $request->headers);
        self::assertStringNotContainsString(self::TOKEN, $request->body);
        $body = ScriptedA2aTransport::envelopeOf($request);
        self::assertSame(['task'], array_keys($body));
        self::assertSame('TASK_STATE_CANCELED', self::j($body, 'task.status.state'));
        self::assertArrayNotHasKey('history', self::objectOf($body['task']));

        self::assertSame(1, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'delivered']));
        self::assertSame(1, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'superseded']));
        self::assertSame(0, $this->deliverer()->deliverDue(time() + 10), 'nothing is left to send');
    }

    /**
     * A2A-PUSH5: 401 and 403 pause the config (auth_failed), 404 and 410 and
     * a refused address stop it; its other pending jobs end with it.
     */
    #[Test]
    public function aRefusedCredentialPausesAndAGoneTargetStopsTheConfig(): void
    {
        $cases = [
            'auth_failed' => A2aTransportResult::response(401, '', '', '', false),
            'stopped'     => A2aTransportResult::response(410, '', '', '', false),
        ];
        foreach ($cases as $expected => $result) {
            $task = $this->newTask();
            $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-' . $expected)));
            $this->cancel($task);
            $this->transport->then(static fn (): A2aTransportResult => $result);
            $this->deliverer()->deliverDue(time());

            self::assertSame($expected, $this->db(A2aPushConfigStore::TABLE)->select(['state'], A2aPushConfigStore::TABLE, ['config_id' => 'c-' . $expected])->fetchOne(), $expected);
        }

        self::assertSame(2, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'failed']));

        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-refused')));
        $this->cancel($task);
        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::notSent('ADDRESS_REFUSED', 'the address is private'));
        $this->deliverer()->deliverDue(time());
        self::assertSame('stopped', $this->db(A2aPushConfigStore::TABLE)->select(['state'], A2aPushConfigStore::TABLE, ['config_id' => 'c-refused'])->fetchOne());

        // A paused or stopped config sends nothing, even for a job that
        // was written before it stopped.
        $paused = (int) $this->db(A2aPushConfigStore::TABLE)->select(['uid'], A2aPushConfigStore::TABLE, ['config_id' => 'c-auth_failed'])->fetchOne();
        $this->db(A2aPushConfigStore::OUTBOX_TABLE)->insert(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => $paused, 'task_uid' => 0, 'revision' => 99, 'state' => 'pending', 'deadline' => time() + 100]);
        $this->deliverer()->deliverDue(time());
        self::assertCount(3, $this->transport->requests);
        self::assertSame(1, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['revision' => 99, 'state' => 'dropped']));
    }

    /**
     * A2A-PUSH5: a timeout, 429, 502 to 504 and a redirect are retried with
     * exponential backoff, at most eight attempts, then abandoned; any other
     * answer fails the job and leaves the config active.
     */
    #[Test]
    public function temporaryFailuresAreRetriedWithBackoffUpToEightAttempts(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->cancel($task);

        $answers = [
            A2aTransportResult::maybeSent('TIMEOUT_AFTER_SEND', 'timed out'),
            A2aTransportResult::response(429, '', '', '', false),
            A2aTransportResult::response(302, '', '', '', false),
            A2aTransportResult::response(502, '', '', '', false),
            A2aTransportResult::response(503, '', '', '', false),
            A2aTransportResult::response(504, '', '', '', false),
            A2aTransportResult::notSent('CONNECT_FAILED', 'refused'),
            A2aTransportResult::response(503, '', '', '', false),
        ];
        $now    = time();
        $delays = [];
        foreach ($answers as $answer) {
            $this->transport->then(static fn (): A2aTransportResult => $answer);
            $this->deliverer()->deliverDue($now);
            $next     = (int) $this->db(A2aPushConfigStore::OUTBOX_TABLE)->select(['next_attempt'], A2aPushConfigStore::OUTBOX_TABLE)->fetchOne();
            $delays[] = $next - $now;
            $now      = max($now, $next);
        }

        self::assertSame([5, 10, 20, 40, 80, 160, 320], array_slice($delays, 0, 7));
        self::assertSame(['abandoned', 8], [$this->job('state'), (int) $this->job('attempts')]);
        self::assertSame('active', $this->db(A2aPushConfigStore::TABLE)->select(['state'], A2aPushConfigStore::TABLE)->fetchOne());

        $other = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($other, 'c-2')));
        $this->cancel($other);
        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::response(400, '', '', '', false));
        $this->deliverer()->deliverDue(time());
        self::assertSame(1, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'failed']));
        self::assertSame(2, $this->rows(A2aPushConfigStore::TABLE, ['state' => 'active']));
    }

    /**
     * A2A-PUSH5: after a delete no attempt starts; a request already in
     * flight still arrives, and its outcome brings nothing back.
     */
    #[Test]
    public function noAttemptStartsAfterADeleteButOneInFlightArrives(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->cancel($task);
        $this->resultOf($this->call('DeleteTaskPushNotificationConfig', ['taskId' => $task, 'id' => 'c-1']));
        self::assertSame(0, $this->deliverer()->deliverDue(time()));
        self::assertSame([], $this->transport->requests);

        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-2')));
        $this->db(A2aPushConfigStore::OUTBOX_TABLE)->insert(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => $this->configUid(), 'task_uid' => $this->taskUid($task), 'revision' => 9, 'state' => 'pending', 'deadline' => time() + 100]);
        $this->transport->then(function (A2aHttpRequest $request) use ($task): A2aTransportResult {
            // The client deletes the config while the request is on its way.
            $this->resultOf($this->call('DeleteTaskPushNotificationConfig', ['taskId' => $task, 'id' => 'c-2']));

            return A2aTransportResult::response(200, '', '', '', false);
        });

        self::assertSame(1, $this->deliverer()->deliverDue(time()), 'the request in flight arrived');
        self::assertSame(0, $this->rows(A2aPushConfigStore::TABLE));
    }

    /**
     * A2A-PUSH3: a client revoked after its job was written gets nothing: the
     * revocation stops its configs. A client disabled some other way (the
     * record edited, its technical user disabled) is checked before every
     * attempt.
     */
    #[Test]
    public function aRevokedOrDisabledClientGetsNoMoreDeliveries(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->cancel($task);
        self::assertTrue($this->getService(A2aInboundClientRepository::class)->revoke(1));

        self::assertSame(0, $this->deliverer()->deliverDue(time()));
        self::assertSame([], $this->transport->requests);
        self::assertSame(['stopped', 'CLIENT_REVOKED'], array_values((array) $this->db(A2aPushConfigStore::TABLE)->select(['state', 'reason'], A2aPushConfigStore::TABLE)->fetchAssociative()));

        $other = $this->newTask(4);
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($other, 'c-2'), 4));
        $this->cancel($other, 4);
        $this->db('be_users')->update('be_users', ['disable' => 1], ['uid' => 7]);

        self::assertSame(0, $this->deliverer()->deliverDue(time()));
        self::assertSame([], $this->transport->requests);
        self::assertSame('CLIENT_INACTIVE', $this->db(A2aPushConfigStore::TABLE)->select(['reason'], A2aPushConfigStore::TABLE, ['config_id' => 'c-2'])->fetchOne());
    }

    /**
     * A config belongs to the token it was created with. Rotating the
     * client's token stops it; a token changed any other way is caught
     * before the attempt.
     */
    #[Test]
    public function aRotatedTokenTakesItsConfigsAlong(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->cancel($task);
        $this->tokens[1] = $this->getService(A2aInboundClientRepository::class)->issueToken(1);

        self::assertSame(0, $this->deliverer()->deliverDue(time()));
        self::assertSame([], $this->transport->requests);
        self::assertSame(['stopped', 'TOKEN_ROTATED'], array_values((array) $this->db(A2aPushConfigStore::TABLE)->select(['state', 'reason'], A2aPushConfigStore::TABLE)->fetchAssociative()));

        $other = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($other, 'c-2')));
        $this->cancel($other);
        $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['token_hash' => A2aInboundClientRepository::hash('nra2a_0000000000000000_' . str_repeat('x', 43))], ['uid' => 1]);

        self::assertSame(0, $this->deliverer()->deliverDue(time()));
        self::assertSame([], $this->transport->requests);
        self::assertSame('CLIENT_INACTIVE', $this->db(A2aPushConfigStore::TABLE)->select(['reason'], A2aPushConfigStore::TABLE, ['config_id' => 'c-2'])->fetchOne());
    }

    /**
     * A2A-OPS2: switching push off is a kill switch. Nothing is sent and no
     * new job is written while it is off; pending jobs wait and go out once
     * it is on again, within their deadline.
     */
    #[Test]
    public function switchingPushOffStopsEveryDelivery(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->cancel($task);
        $this->serverSettings(self::SERVER_SETTINGS);

        self::assertSame(0, $this->deliverer()->deliverDue(time()));
        self::assertSame([], $this->transport->requests);
        self::assertSame(1, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'pending']));

        $later = $this->newTask();
        $this->db(A2aPushConfigStore::TABLE)->insert(A2aPushConfigStore::TABLE, ['config_id' => 'raw', 'task_uid' => $this->taskUid($later), 'owner_client_uid' => 1, 'client_token_hash' => A2aInboundClientRepository::hash($this->tokens[1]), 'url' => self::HOOK, 'state' => 'active']);
        $this->getService(A2aTaskProjector::class)->projectPending();
        self::assertSame(1, $this->rows(A2aPushConfigStore::OUTBOX_TABLE), 'no job while off');

        $this->serverSettings(['push' => '1'] + self::SERVER_SETTINGS);
        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::response(200, '', '', '', false));
        self::assertSame(1, $this->deliverer()->deliverDue(time()));
    }

    /**
     * A2A-PUSH5: the attempts are bounded per config. When the newest job
     * fails for good, the older pending jobs of the config, which would send
     * the same task, end with it.
     */
    #[Test]
    public function aFinalFailureEndsTheOlderJobsOfTheConfig(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        foreach ([1, 2, 3] as $revision) {
            $this->db(A2aPushConfigStore::OUTBOX_TABLE)->insert(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => $this->configUid(), 'task_uid' => $this->taskUid($task), 'revision' => $revision, 'state' => 'pending', 'deadline' => time() + 100]);
        }

        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::response(400, '', '', '', false));
        $this->deliverer()->deliverDue(time());
        $this->deliverer()->deliverDue(time() + 1);

        self::assertCount(1, $this->transport->requests);
        self::assertSame(2, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'superseded']));
    }

    /**
     * The lease is taken from the clock at the attempt, not from the start
     * of the pass: a second worker running while a slow send is under way
     * does not claim the same job.
     */
    #[Test]
    public function aSecondWorkerDoesNotClaimAJobInFlight(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->db(A2aPushConfigStore::OUTBOX_TABLE)->insert(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => $this->configUid(), 'task_uid' => $this->taskUid($task), 'revision' => 5, 'state' => 'pending', 'next_attempt' => 0, 'deadline' => time() + 100]);

        $this->transport->then(function (): A2aTransportResult {
            // Another worker's tick while this send is still on its way.
            $this->deliverer()->deliverDue(time());

            return A2aTransportResult::response(200, '', '', '', false);
        });

        // A pass that started an hour ago, its slow sends still running.
        self::assertSame(1, $this->deliverer()->deliverDue(time() - 3600));
        self::assertCount(1, $this->transport->requests);
    }

    /**
     * A credential that cannot be opened stops its config instead of being
     * retried on every tick.
     */
    #[Test]
    public function anUnreadableCredentialStopsTheConfig(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->cancel($task);
        $this->db(A2aPushConfigStore::TABLE)->update(A2aPushConfigStore::TABLE, ['credentials' => substr_replace(self::text($this->db(A2aPushConfigStore::TABLE)->select(['credentials'], A2aPushConfigStore::TABLE)->fetchOne()), 'AAAA', -8, 4)], ['config_id' => 'c-1']);

        $this->deliverer()->deliverDue(time());

        self::assertSame([], $this->transport->requests);
        self::assertSame(['stopped', 'CREDENTIAL_UNREADABLE'], array_values((array) $this->db(A2aPushConfigStore::TABLE)->select(['state', 'reason'], A2aPushConfigStore::TABLE)->fetchAssociative()));
    }

    /**
     * A2A-PUSH3: a job past its deadline is abandoned unsent.
     */
    #[Test]
    public function aJobPastItsDeadlineIsAbandonedUnsent(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->cancel($task);

        $this->deliverer()->deliverDue(time() + A2aPushDeliverer::DEADLINE_SECONDS + 1);

        self::assertSame([], $this->transport->requests);
        self::assertSame('abandoned', $this->job('state'));
    }

    /**
     * A delivery pass has a time budget: once it is spent, no further job is
     * claimed, so slow receivers cannot hold the worker's tick for long. The
     * jobs left over stay pending and unclaimed for the next tick.
     */
    #[Test]
    public function aDeliveryPassStopsClaimingOnceItsTimeIsSpent(): void
    {
        $task = $this->newTask();
        foreach (['c-1', 'c-2', 'c-3'] as $id) {
            $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, $id)));
        }

        $this->cancel($task);
        self::assertSame(3, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'pending']));

        $clock = time();
        for ($i = 0; $i < 3; ++$i) {
            $this->transport->then(static function () use (&$clock): A2aTransportResult {
                // A receiver that answers only after more than half the budget.
                $clock += intdiv(A2aPushDeliverer::PASS_BUDGET_SECONDS, 2) + 1;

                return A2aTransportResult::response(200, '', '', '', false);
            });
        }

        self::assertSame(2, $this->deliverer(null, static function () use (&$clock): int {
            return $clock;
        })->deliverDue(time()));

        self::assertCount(2, $this->transport->requests);
        self::assertSame(1, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'pending', 'lease_until' => 0]), 'the third job was not claimed');
    }

    /**
     * One client's jobs cannot fill a pass: each client gets a bounded number
     * of jobs per pass, and the clients take turns, so another client's
     * delivery is not held behind a client with many due jobs.
     */
    #[Test]
    public function oneClientCannotTakeEveryTurnOfAPass(): void
    {
        $mine  = $this->newTask();
        $count = A2aPushDeliverer::MAX_JOBS_PER_CLIENT_PER_PASS + 5;
        for ($i = 0; $i < $count; ++$i) {
            $this->db(A2aPushConfigStore::TABLE)->insert(A2aPushConfigStore::TABLE, ['config_id' => 'raw-' . $i, 'task_uid' => $this->taskUid($mine), 'owner_client_uid' => 1, 'client_token_hash' => A2aInboundClientRepository::hash($this->tokens[1]), 'url' => self::HOOK, 'state' => 'active']);
            $configUid = (int) $this->db(A2aPushConfigStore::TABLE)->lastInsertId();
            // Due long before the other client's job, so they sort first.
            $this->db(A2aPushConfigStore::OUTBOX_TABLE)->insert(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => $configUid, 'task_uid' => $this->taskUid($mine), 'revision' => 1, 'state' => 'pending', 'next_attempt' => 0, 'deadline' => time() + 100]);
        }

        $theirs = $this->newTask(4);
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', ['taskId' => $theirs, 'id' => 'c-4', 'url' => 'https://93.184.215.15/four'], 4));
        $this->cancel($theirs, 4);

        for ($i = 0; $i < 2 * $count; ++$i) {
            $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::response(200, '', '', '', false));
        }

        $this->deliverer()->deliverDue(time(), 3);
        $urls = array_map(static fn (A2aHttpRequest $request): string => $request->url, $this->transport->requests);
        self::assertCount(3, $urls);
        self::assertContains('https://93.184.215.15/four', $urls, 'the other client had a turn in the same pass');

        $this->deliverer()->deliverDue(time(), 100);
        self::assertCount(3 + A2aPushDeliverer::MAX_JOBS_PER_CLIENT_PER_PASS, $this->transport->requests, 'one client gets a bounded number of jobs per pass');
    }

    /**
     * A2A-PUSH5: the backoff holds per config, not per event. A new event of
     * a config whose delivery is failing waits for the backoff and carries the
     * attempts on, also when it was written while an attempt was on its way.
     */
    #[Test]
    public function aNewEventWaitsForTheBackoffOfItsConfig(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->cancel($task);
        $taskUid = $this->taskUid($task);
        $now     = time();

        $this->transport->then(function () use ($taskUid, $now): A2aTransportResult {
            // A new event of the task while the attempt is on its way.
            A2aPushDeliverer::enqueue($this->db(A2aPushConfigStore::OUTBOX_TABLE), $taskUid, 50, $now);

            return A2aTransportResult::response(503, '', '', '', false);
        });
        $this->deliverer()->deliverDue($now);
        self::assertCount(1, $this->transport->requests);

        $this->deliverer()->deliverDue($now + 1);
        self::assertCount(1, $this->transport->requests, 'the event written in flight waits for the backoff');

        A2aPushDeliverer::enqueue($this->db(A2aPushConfigStore::OUTBOX_TABLE), $taskUid, 51, $now + 2);
        $this->deliverer()->deliverDue($now + 3);
        self::assertCount(1, $this->transport->requests, 'an event written later waits for the backoff too');

        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::response(503, '', '', '', false));
        $this->deliverer()->deliverDue($now + 5);
        self::assertCount(2, $this->transport->requests, 'after the backoff the newest event goes out');
        self::assertSame(2, (int) $this->job('attempts'), 'the attempts count on per config');
    }

    /**
     * Switching push off stops sending, not the housekeeping: finished jobs
     * past the retention are removed and jobs past their deadline are
     * abandoned while push stays off.
     */
    #[Test]
    public function switchingPushOffKeepsTheOutboxTidy(): void
    {
        $this->serverSettings(self::SERVER_SETTINGS);
        $now = time();
        $this->db(A2aPushConfigStore::OUTBOX_TABLE)->insert(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => 1, 'task_uid' => 1, 'revision' => 1, 'state' => 'delivered', 'deadline' => $now, 'tstamp' => $now - (8 * 86400)]);
        $this->db(A2aPushConfigStore::OUTBOX_TABLE)->insert(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => 2, 'task_uid' => 1, 'revision' => 1, 'state' => 'pending', 'deadline' => $now - 1]);
        $this->db(A2aPushConfigStore::OUTBOX_TABLE)->insert(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => 3, 'task_uid' => 1, 'revision' => 1, 'state' => 'pending', 'deadline' => $now + 100]);

        self::assertSame(0, $this->deliverer()->deliverDue($now));

        self::assertSame([], $this->transport->requests);
        self::assertSame(0, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['state' => 'delivered']), 'a finished job past the retention is removed');
        self::assertSame(1, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => 2, 'state' => 'abandoned', 'last_reason' => 'DEADLINE_PASSED']));
        self::assertSame(1, $this->rows(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => 3, 'state' => 'pending']), 'a job within its deadline waits');
    }

    /**
     * An attempt counts once it is claimed: a failure after the request left
     * (here in the backoff computation) cannot make the job retry beyond the
     * eight attempts every time its lease runs out.
     */
    #[Test]
    public function anAttemptThatFailsAfterTheSendStillCounts(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $this->cancel($task);
        for ($i = 0; $i < 12; ++$i) {
            $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::response(503, '', '', '', false));
        }

        $deliverer = $this->deliverer(static function (): int {
            throw new RuntimeException('the outcome could not be recorded', 1794757001);
        });
        $now = time();
        for ($i = 0; $i < 12; ++$i) {
            $deliverer->deliverDue($now);
            // Past the lease of the attempt that failed.
            $now += 1000;
        }

        self::assertCount(A2aPushDeliverer::MAX_ATTEMPTS, $this->transport->requests);
        self::assertSame(['abandoned', A2aPushDeliverer::MAX_ATTEMPTS], [$this->job('state'), (int) $this->job('attempts')]);
    }

    /**
     * A2A-PUSH5: an event written while the last of the eight attempts is on
     * its way takes the used-up count over; it is abandoned, not sent.
     */
    #[Test]
    public function anEventWrittenDuringTheLastAttemptIsNotSent(): void
    {
        $task = $this->newTask();
        $this->resultOf($this->call('CreateTaskPushNotificationConfig', $this->config($task, 'c-1')));
        $taskUid = $this->taskUid($task);
        $this->db(A2aPushConfigStore::OUTBOX_TABLE)->insert(A2aPushConfigStore::OUTBOX_TABLE, ['config_uid' => $this->configUid(), 'task_uid' => $taskUid, 'revision' => 5, 'state' => 'pending', 'attempts' => A2aPushDeliverer::MAX_ATTEMPTS - 1, 'next_attempt' => 0, 'deadline' => time() + 10000]);
        $now = time();

        $this->transport->then(function () use ($taskUid, $now): A2aTransportResult {
            A2aPushDeliverer::enqueue($this->db(A2aPushConfigStore::OUTBOX_TABLE), $taskUid, 6, $now);

            return A2aTransportResult::response(503, '', '', '', false);
        });
        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::response(200, '', '', '', false));

        $this->deliverer()->deliverDue($now);
        $this->deliverer()->deliverDue($now + 1000);

        self::assertCount(1, $this->transport->requests);
        self::assertSame(['abandoned', 'MAX_ATTEMPTS'], [$this->job('state'), $this->job('last_reason')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function agentCard(): array
    {
        $card = json_decode((string) $this->middleware()->process($this->request('GET', self::BASE . '/.well-known/agent-card.json', []), $this->passThrough())->getBody(), true);

        return self::objectOf($card);
    }

    /**
     * @param (Closure(int): int)|null $jitter
     * @param (Closure(): int)|null    $clock
     */
    private function deliverer(?Closure $jitter = null, ?Closure $clock = null): A2aPushDeliverer
    {
        return new A2aPushDeliverer(
            $this->getService(ConnectionPool::class),
            $this->transport,
            $this->getService(A2aTaskView::class),
            $this->getService(AgentStateCodec::class),
            $this->getService(A2aUrlGuardFactory::class),
            $this->getService(A2aServerSettings::class),
            $this->getService(A2aAuthenticator::class),
            null,
            $jitter ?? static fn (int $delay): int => 0,
            $clock,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $taskId, string $id): array
    {
        return ['taskId' => $taskId, 'id' => $id, 'url' => self::HOOK, 'token' => self::TOKEN, 'authentication' => ['scheme' => 'Bearer', 'credentials' => self::SECRET]];
    }

    private function newTask(int $client = 1): string
    {
        return self::text(self::j($this->taskOf($this->call('SendMessage', ['message' => $this->message(), 'configuration' => ['returnImmediately' => true]], $client)), 'id'));
    }

    /**
     * A new revision of the task, so the projector writes an event and its jobs.
     */
    private function cancel(string $taskId, int $client = 1): void
    {
        self::assertSame('TASK_STATE_CANCELED', self::j($this->taskOf($this->call('CancelTask', ['id' => $taskId], $client)), 'status.state'));
    }

    /**
     * @param array<string, mixed> $reply
     *
     * @return array<string, mixed>
     */
    private function resultOf(array $reply): array
    {
        self::assertArrayHasKey('result', $reply, 'expected a result: ' . json_encode($reply));

        return self::objectOf($reply['result']);
    }

    private function configUid(): int
    {
        return (int) $this->db(A2aPushConfigStore::TABLE)->select(['uid'], A2aPushConfigStore::TABLE)->fetchOne();
    }

    private function taskUid(string $taskId): int
    {
        return (int) $this->db('tx_nrllm_a2a_task')->select(['uid'], 'tx_nrllm_a2a_task', ['task_id' => $taskId])->fetchOne();
    }

    private function job(string $column): mixed
    {
        return $this->db(A2aPushConfigStore::OUTBOX_TABLE)->select([$column], A2aPushConfigStore::OUTBOX_TABLE, [], [], ['uid' => 'DESC'])->fetchOne();
    }
}

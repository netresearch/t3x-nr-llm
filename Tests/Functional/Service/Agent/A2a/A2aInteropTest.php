<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aCardImporter;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Client\A2aClient;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aCallFailedException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aTaskHandleNotFoundException;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendJournal;
use Netresearch\NrLlm\Service\Agent\A2a\Journal\A2aSendState;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aJsonRpcCodec;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aSendMessageConfiguration;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTask;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aVaultTransport;
use Netresearch\NrLlm\Service\Tool\EgressPolicyService;
use Netresearch\NrLlm\Service\Tool\Web\DnsHostResolver;
use Netresearch\NrLlm\Service\Tool\Web\IpAddressClassifier;
use Netresearch\NrLlm\Service\Tool\Web\ProxyDetector;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * The A2A client against a2a-python 1.3.0 (spec 051 A2A-S0-5, interop job).
 *
 * Runs only where `.Build/a2a-interop.json` names a running partner; the
 * `a2a-interop` CI job starts one (`Build/a2a-interop`) and runs this class
 * with `--fail-on-skipped`, so a skip there is a failure. Everything goes
 * through the production classes: client, importer, transport, the guard
 * with real DNS, and nr-vault with the address pin. The partner sits on
 * a private Docker address, which only the Testing context may treat as
 * public (A2A-SEC10), plus nr-vault's literal `allowed_hosts` opt-in.
 */
#[CoversNothing]
final class A2aInteropTest extends AbstractFunctionalTestCase
{
    private const OWNER = 21;

    private const CONFIG = __DIR__ . '/../../../../../.Build/a2a-interop.json';

    /** @var array{cardUrl: string, host: string, port: int, networks: list<string>} */
    private array $partner;

    protected function setUp(): void
    {
        if (!is_file(self::CONFIG)) {
            self::markTestSkipped('No A2A interop partner configured (.Build/a2a-interop.json); the a2a-interop CI job provides one.');
        }

        parent::setUp();
        $decoded = json_decode((string) file_get_contents(self::CONFIG), true);
        if (!is_array($decoded) || !is_string($decoded['cardUrl'] ?? null) || !is_string($decoded['host'] ?? null) || !is_int($decoded['port'] ?? null) || !is_array($decoded['networks'] ?? null)) {
            throw new RuntimeException('Unreadable ' . self::CONFIG, 1479331545);
        }

        $this->partner = [
            'cardUrl'  => $decoded['cardUrl'],
            'host'     => $decoded['host'],
            'port'     => $decoded['port'],
            'networks' => array_values(array_filter($decoded['networks'], is_string(...))),
        ];

        A2aConfVars::set(['EXTCONF', 'nr_llm', 'a2aTestPublicNetworks'], $this->partner['networks']);
        A2aConfVars::set(['HTTP', 'allowed_hosts'], [$this->partner['host']]);
        A2aConfVars::set(['EXTENSIONS', 'nr_llm', 'a2a', 'client', 'allowedHosts'], $this->partner['host'] . ':' . $this->partner['port']);
    }

    #[Test]
    public function theWholeClientWorksAgainstA2aPython(): void
    {
        $agent = $this->importedAgent();
        self::assertSame('http://' . $this->partner['host'] . ':' . $this->partner['port'] . '/a2a/jsonrpc', $agent->getInterfaceUrl(), 'the JSONRPC 1.0 entry, not the HTTP+JSON or 0.3 one before it');

        $client = new A2aClient($this->journal(), $this->transport(), new A2aResponseParser());

        // Blocking (the proto's default, A2A-D1): the answer is the finished task.
        $blocking = $client->sendMessage($agent, self::OWNER, A2aMessage::userText(A2aClient::newMessageId(), 'hello'));
        self::assertSame(A2aSendState::ACCEPTED, $blocking->state());
        self::assertSame(A2aTaskState::COMPLETED, $blocking->result?->task?->status->state);
        self::assertSame('echo: hello', $blocking->result->task->artifactText());

        // Send, return, poll later (A2A-C9's pattern).
        $quick = $client->sendMessage($agent, self::OWNER, A2aMessage::userText(A2aClient::newMessageId(), 'hello again'), A2aSendMessageConfiguration::returningImmediately());
        self::assertSame(A2aSendState::ACCEPTED, $quick->state());
        self::assertFalse($quick->result?->task?->status->isTerminal());
        $taskId = $quick->intent->remoteTaskId;

        $task = $client->getTask($agent, self::OWNER, $taskId);
        for ($i = 0; $i < 40 && !$task->status->isTerminal(); ++$i) {
            usleep(100000);
            $task = $client->getTask($agent, self::OWNER, $taskId);
        }

        self::assertSame(A2aTaskState::COMPLETED, $task->status->state);
        self::assertSame('echo: hello again', $task->artifactText());

        // Only this owner's tasks come back from ListTasks.
        $listed = array_map(static fn (A2aTask $task): string => $task->id, $client->listTasks($agent, self::OWNER)->tasks);
        self::assertContains($taskId, $listed);
        self::assertContains($blocking->intent->remoteTaskId, $listed);
        self::assertSame([], $client->listTasks($agent, self::OWNER + 1)->tasks);

        // Cancel a running task; a finished one is -32002.
        $toCancel = $client->sendMessage($agent, self::OWNER, A2aMessage::userText(A2aClient::newMessageId(), 'cancel me'), A2aSendMessageConfiguration::returningImmediately());
        self::assertSame(A2aTaskState::CANCELED, $client->cancelTask($agent, self::OWNER, $toCancel->intent->remoteTaskId)->status->state);

        try {
            $client->cancelTask($agent, self::OWNER, $taskId);
            self::fail('A completed task was cancelled');
        } catch (A2aCallFailedException $e) {
            self::assertSame(-32002, $e->remoteError?->code);
            self::assertSame('TASK_NOT_CANCELABLE', $e->reason);
        }

        // Another local user holds no handle and the remote is not asked.
        $this->expectException(A2aTaskHandleNotFoundException::class);
        $client->getTask($agent, self::OWNER + 1, $taskId);
    }

    #[Test]
    public function aRequestWithoutTheVersionHeaderIsRefusedWith32009(): void
    {
        $agent  = $this->importedAgent();
        $params = A2aJsonRpcCodec::encodeParams(['message' => A2aMessage::userText(A2aClient::newMessageId(), 'hello')->toArray()]);

        $result = $this->transport()->send(new A2aHttpRequest(
            'POST',
            $agent->getInterfaceUrl(),
            ['Content-Type' => 'application/json'],
            A2aJsonRpcCodec::encodeRequest('SendMessage', $params, 'no-version'),
            '',
            5,
            10,
            65536,
            'A2aInteropTest without A2A-Version',
        ));

        $response = A2aJsonRpcCodec::decodeResponse($result->body, 'no-version');
        self::assertSame(-32009, $response->error?->code);
        self::assertSame('VERSION_NOT_SUPPORTED', $response->error->effectiveReason());
    }

    private function importedAgent(): A2aRemoteAgent
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_a2a_agent');
        $connection->insert('tx_nrllm_a2a_agent', [
            'identifier' => 'partner',
            'title'      => 'a2a-python 1.3.0',
            'card_url'   => $this->partner['cardUrl'],
            'data_class' => ToolDataClass::PUBLIC_CONTENT->value,
            'enabled'    => 1,
        ]);
        $uid        = (int) $connection->lastInsertId();
        $repository = $this->getService(A2aRemoteAgentRepository::class);
        $agent      = $repository->findByUid($uid);
        self::assertInstanceOf(A2aRemoteAgent::class, $agent);

        (new A2aCardImporter($this->transport(), new A2aResponseParser(), $this->guards(), $this->getService(ConnectionPool::class)))->import($agent);

        $reloaded = $repository->findByReference('partner');
        self::assertInstanceOf(A2aRemoteAgent::class, $reloaded);
        // The repository may hand back the instance it already holds; read
        // the imported columns into it the way a fresh request would.
        $row = $connection->select(['interface_url', 'interface_tenant', 'card_capabilities'], 'tx_nrllm_a2a_agent', ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);
        self::assertNotSame('', $row['interface_url']);

        return $this->freshAgent($uid);
    }

    /**
     * The production transport, built from the same classes the container
     * wires: the A2A services are private (ADR-028 counts public ones), so
     * a functional test cannot fetch them. The resolver is the real DNS one.
     */
    private function transport(): A2aVaultTransport
    {
        return new A2aVaultTransport(
            $this->guards(),
            $this->getService(VaultServiceInterface::class),
            $this->getService(RequestFactoryInterface::class),
            $this->getService(StreamFactoryInterface::class),
        );
    }

    private function guards(): A2aUrlGuardFactory
    {
        return new A2aUrlGuardFactory(
            new EgressPolicyService($this->getService(SiteFinder::class)),
            new DnsHostResolver(),
            new IpAddressClassifier(),
            $this->getService(ExtensionConfiguration::class),
            new ProxyDetector(),
        );
    }

    private function journal(): A2aSendJournal
    {
        return new A2aSendJournal($this->getService(ConnectionPool::class));
    }

    private function freshAgent(int $uid): A2aRemoteAgent
    {
        $this->getService(PersistenceManager::class)->clearState();
        $agent = $this->getService(A2aRemoteAgentRepository::class)->findByUid($uid);
        self::assertInstanceOf(A2aRemoteAgent::class, $agent);

        return $agent;
    }
}

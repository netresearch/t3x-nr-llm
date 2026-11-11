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
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aAgentUnavailableException;
use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aCallFailedException;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aResponseParser;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportResult;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use Netresearch\NrLlm\Service\Tool\EgressPolicyService;
use Netresearch\NrLlm\Service\Tool\Web\HostResolverInterface;
use Netresearch\NrLlm\Service\Tool\Web\IpAddressClassifier;
use Netresearch\NrLlm\Service\Tool\Web\ProxyDetector;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;

/**
 * Card import (spec 051 A2A-C1, A2A-C2): validation, interface selection,
 * tenant, the guard on the interface URL, and the operator's confirmation of
 * a changed card.
 */
#[CoversClass(A2aCardImporter::class)]
final class A2aCardImporterTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_a2a_agent';

    private ScriptedA2aTransport $transport;

    private A2aCardImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new ScriptedA2aTransport();
        $resolver        = new class implements HostResolverInterface {
            public function resolve(string $host): array
            {
                return match ($host) {
                    'agent.example', 'other.example' => ['93.184.215.14'],
                    'intranet.example'               => ['10.1.2.3'],
                    default                          => [],
                };
            }
        };
        $guards = new A2aUrlGuardFactory(
            new EgressPolicyService($this->getService(SiteFinder::class)),
            $resolver,
            new IpAddressClassifier(),
            $this->getService(ExtensionConfiguration::class),
            new ProxyDetector([], 'cli', []),
        );
        $this->importer = new A2aCardImporter($this->transport, new A2aResponseParser(), $guards, $this->getService(ConnectionPool::class));
    }

    #[Test]
    public function aCardIsFetchedWithoutCredentialValidatedAndItsFirstJsonRpc10InterfaceSelected(): void
    {
        $agent = $this->agent();
        $this->transport->then(fn (A2aHttpRequest $request): A2aTransportResult => $this->card([
            ['url' => 'https://agent.example/grpc', 'protocolBinding' => 'GRPC', 'protocolVersion' => '1.0'],
            ['url' => 'https://agent.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0', 'tenant' => 'acme'],
            ['url' => 'https://agent.example/rpc2', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0'],
        ], ['streaming' => true], '"v1"'));

        $result = $this->importer->import($agent);

        self::assertSame('GET', $this->transport->requests[0]->method);
        self::assertSame('https://agent.example/.well-known/agent-card.json', $this->transport->requests[0]->url);
        self::assertSame('', $this->transport->requests[0]->credential, 'a card is fetched without the agent credential');
        self::assertSame('https://agent.example/rpc', $result->interface->url);
        self::assertFalse($result->confirmationRequired);

        $row = $this->row($agent);
        self::assertSame('https://agent.example/rpc', $row['interface_url']);
        self::assertSame('acme', $row['interface_tenant']);
        self::assertSame('"v1"', $row['card_etag']);
        self::assertGreaterThan(0, (int) $row['card_fetched']);
        self::assertIsString($row['card_json']);
        self::assertStringContainsString('"supportedInterfaces"', $row['card_json']);
        self::assertSame(0, (int) $row['card_changed']);
        self::assertSame('', $row['import_error']);
    }

    #[Test]
    public function theCardNeverDecidesTheRecordsTrust(): void
    {
        $agent = $this->agent(['enabled' => 0, 'requires_approval' => 1]);
        $this->transport->then(fn (): A2aTransportResult => $this->card(
            [['url' => 'https://agent.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']],
            [],
            '',
            ['enabled' => true, 'requiresApproval' => false, 'dataClass' => 'secretAdjacent'],
        ));

        $this->importer->import($agent);

        $row = $this->row($agent);
        self::assertSame([0, 1, 'publicContent'], [(int) $row['enabled'], (int) $row['requires_approval'], $row['data_class']]);
    }

    #[Test]
    public function aCardWithoutAJsonRpc10InterfaceIsRefusedNamingWhatItOffers(): void
    {
        $agent = $this->agent(['interface_url' => 'https://agent.example/old', 'card_json' => '{"old":true}']);
        $this->transport->then(fn (): A2aTransportResult => $this->card([
            ['url' => 'https://agent.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '0.3'],
            ['url' => 'https://agent.example/rest', 'protocolBinding' => 'HTTP+JSON', 'protocolVersion' => '1.0'],
        ]));

        try {
            $this->importer->import($agent);
            self::fail('A card without a JSONRPC 1.0 interface was imported');
        } catch (A2aCallFailedException $e) {
            self::assertSame('NO_JSONRPC_1_0_INTERFACE', $e->reason);
            self::assertStringContainsString('JSONRPC 0.3, HTTP+JSON 1.0', $e->getMessage());
        }

        $row = $this->row($agent);
        self::assertSame('https://agent.example/old', $row['interface_url'], 'a failed import keeps the card that was there');
        self::assertSame('{"old":true}', $row['card_json']);
        self::assertIsString($row['import_error']);
        self::assertStringContainsString('JSONRPC 0.3', $row['import_error']);
    }

    #[Test]
    public function anInterfaceUrlOnAPrivateAddressIsRefused(): void
    {
        $agent = $this->agent();
        $this->transport->then(fn (): A2aTransportResult => $this->card([
            ['url' => 'https://intranet.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0'],
        ]));

        try {
            $this->importer->import($agent);
            self::fail('An interface URL on a private address was accepted');
        } catch (A2aCallFailedException $e) {
            self::assertSame('INTERFACE_ADDRESS_REFUSED', $e->reason);
        }

        self::assertSame('', $this->row($agent)['interface_url']);
    }

    #[Test]
    public function aCardMissingARequiredFieldIsRefused(): void
    {
        $agent = $this->agent();
        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::response(200, 'application/json', '', '{"name":"x","supportedInterfaces":[]}', false));

        $this->expectException(A2aCallFailedException::class);
        $this->importer->import($agent);
    }

    #[Test]
    public function aChangedInterfaceOnReImportWaitsForTheOperator(): void
    {
        $agent = $this->agent();
        $this->transport
            ->then(fn (): A2aTransportResult => $this->card([['url' => 'https://agent.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']]))
            ->then(fn (): A2aTransportResult => $this->card([['url' => 'https://other.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']]));

        self::assertFalse($this->importer->import($agent)->confirmationRequired);
        $second = $this->importer->import($this->reload($agent));

        self::assertTrue($second->confirmationRequired);
        self::assertSame(1, (int) $this->row($agent)['card_changed']);
        self::assertStringContainsString('save the record again', (string) $this->reload($agent)->useRefusal());
    }

    #[Test]
    public function changedCapabilitiesOnReImportWaitForTheOperatorToo(): void
    {
        $agent      = $this->agent();
        $interfaces = [['url' => 'https://agent.example/rpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0']];
        $this->transport
            ->then(fn (): A2aTransportResult => $this->card($interfaces, ['streaming' => false]))
            ->then(fn (): A2aTransportResult => $this->card($interfaces, ['streaming' => false]))
            ->then(fn (): A2aTransportResult => $this->card($interfaces, ['streaming' => true]));

        $this->importer->import($agent);
        self::assertFalse($this->importer->import($this->reload($agent))->confirmationRequired, 'an unchanged card needs no confirmation');
        self::assertTrue($this->importer->import($this->reload($agent))->confirmationRequired);
    }

    #[Test]
    public function aRecordWithoutDataClassImportsNothing(): void
    {
        $agent = $this->agent(['data_class' => '']);

        try {
            $this->importer->import($agent);
            self::fail('A record without data class imported a card');
        } catch (A2aAgentUnavailableException) {
        }

        self::assertSame([], $this->transport->requests);
    }

    #[Test]
    public function aCardUrlAnswerOtherThan200IsRefused(): void
    {
        $agent = $this->agent();
        $this->transport->then(static fn (): A2aTransportResult => A2aTransportResult::response(404, 'text/html', '', 'Not Found', false));

        try {
            $this->importer->import($agent);
            self::fail('A 404 was imported');
        } catch (A2aCallFailedException $e) {
            self::assertSame('HTTP_STATUS', $e->reason);
        }
    }

    /**
     * @param list<array<string, string>> $interfaces
     * @param array<string, bool>         $capabilities
     * @param array<string, mixed>        $extra
     */
    private function card(array $interfaces, array $capabilities = [], string $etag = '', array $extra = []): A2aTransportResult
    {
        $card = [
            'name'                => 'Remote',
            'description'         => 'A remote agent',
            'version'             => '2.1.0',
            'supportedInterfaces' => $interfaces,
            'capabilities'        => (object) $capabilities,
            'defaultInputModes'   => ['text/plain'],
            'defaultOutputModes'  => ['text/plain'],
            'skills'              => [['id' => 's', 'name' => 'S', 'description' => 'd', 'tags' => ['t']]],
        ] + $extra;

        return A2aTransportResult::response(200, 'application/json', $etag, json_encode($card, JSON_THROW_ON_ERROR), false);
    }

    /**
     * @param array<string, int|string> $values
     */
    private function agent(array $values = []): A2aRemoteAgent
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, $values + [
            'identifier' => 'remote',
            'title'      => 'Remote',
            'card_url'   => 'https://agent.example/.well-known/agent-card.json',
            'data_class' => ToolDataClass::PUBLIC_CONTENT->value,
            'enabled'    => 1,
        ]);

        return $this->reloadUid((int) $connection->lastInsertId());
    }

    private function reload(A2aRemoteAgent $agent): A2aRemoteAgent
    {
        return $this->reloadUid((int) $agent->getUid());
    }

    private function reloadUid(int $uid): A2aRemoteAgent
    {
        $repository = $this->getService(A2aRemoteAgentRepository::class);
        $query      = $repository->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);
        $query->matching($query->equals('uid', $uid));
        $agent = $query->execute()->getFirst();
        self::assertInstanceOf(A2aRemoteAgent::class, $agent);
        $this->getPersistence()->clearState();

        return $agent;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(A2aRemoteAgent $agent): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['uid' => (int) $agent->getUid()])->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function getPersistence(): PersistenceManager
    {
        return $this->getService(PersistenceManager::class);
    }
}

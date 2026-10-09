<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpAuthenticationSessionFactoryInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpCredentialSessionInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\RecordedContacts;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Http\CancellationSignalInterface;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

#[CoversClass(McpClient::class)]
#[CoversClass(McpHttpTransport::class)]
final class McpDelegatedLifecycleTest extends AbstractUnitTestCase
{
    private function server(
        string $mode = 'delegated',
        string $discovery = '',
    ): McpServerRecord {
        $base = McpTestServer::server();
        return new McpServerRecord(
            uid: $base->uid,
            pid: $base->pid,
            identifier: $base->identifier,
            name: $base->name,
            description: $base->description,
            url: $base->url,
            authCredential: 'legacy-must-not-be-used',
            authPlacement: 'header',
            authHeaderName: 'X-Legacy-Key',
            dataClass: $base->dataClass,
            requiresApproval: $base->requiresApproval,
            enabled: $base->enabled,
            importStatus: $base->importStatus,
            importError: $base->importError,
            lastImported: $base->lastImported,
            toolCount: $base->toolCount,
            lastContact: $base->lastContact,
            lastLatencyMs: $base->lastLatencyMs,
            tstamp: $base->tstamp,
            crdate: $base->crdate,
            authMode: $mode,
            delegationProfile: 'test',
            delegationAudience: 'mcp-api',
            delegationScopes: 'read write',
            discoveryCredential: $discovery,
        );
    }

    private function seam(McpTestServer $wire): McpHttpTransport
    {
        $transport = new McpHttpTransport(
            self::createStub(VaultServiceInterface::class),
            $this->createSecureHttpClientFactoryMock(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $transport->setHttpClient($wire);
        return $transport;
    }

    private function client(
        McpHttpTransport $transport,
        ?McpAuthenticationSessionFactoryInterface $factory = null,
    ): McpClient {
        return new McpClient(
            $transport,
            new RecordedContacts(),
            new McpDeadlineFactory(
                new FakeMcpClock(),
                self::createStub(ExtensionConfiguration::class),
            ),
            $factory,
        );
    }

    private function session(
        string $identifier,
        int $legs,
    ): McpCredentialSessionInterface {
        $session = self::createMock(McpCredentialSessionInterface::class);
        $session
            ->expects(self::exactly($legs))
            ->method('credentialIdentifier')
            ->willReturn($identifier);
        $session->expects(self::once())->method('close');
        return $session;
    }

    #[Test]
    public function missingActorDeniesBeforeOpeningOrSendingAnything(): void
    {
        $wire = new McpTestServer();
        $factory = self::createMock(McpAuthenticationSessionFactoryInterface::class);
        $factory->expects(self::never())->method('openForExecution');
        try {
            $this
                ->client($this->seam($wire), $factory)
                ->callTool($this->server(), 'run', []);
            self::fail('Missing identity must be refused.');
        } catch (McpTransportException $e) {
            self::assertStringContainsString(
                'execution_identity_missing',
                $e->getMessage(),
            );
        }

        self::assertSame([], $wire->received);
    }

    #[Test]
    public function missingFactoryDoesNotUseTheConfiguredMachineCredential(): void
    {
        $wire = new McpTestServer();
        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990219);
        try {
            $this
                ->client($this->seam($wire))
                ->callTool(
                    $this->server(),
                    'run',
                    [],
                    actor: AiActorContext::backendUser(7, false, []),
                );
        } finally {
            self::assertSame([], $wire->received);
        }
    }

    #[Test]
    public function aNullFactorySessionCannotBecomeAnAnonymousCall(): void
    {
        $wire = new McpTestServer();
        $factory = self::createStub(McpAuthenticationSessionFactoryInterface::class);
        $factory->method('openForExecution')->willReturn(null);
        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('credential_session_missing');
        try {
            $this
                ->client($this->seam($wire), $factory)
                ->callTool(
                    $this->server(),
                    'run',
                    [],
                    actor: AiActorContext::backendUser(7, false, []),
                );
        } finally {
            self::assertSame([], $wire->received);
        }
    }

    #[Test]
    public function oneLongLivedClientKeepsActorCredentialsSeparateOnAllWireLegs(): void
    {
        $wire = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['content' => []])
            ->willHandshake()
            ->willReturn(['content' => []]);
        $client = self::createMock(VaultHttpClientInterface::class);
        $client->method('withReason')->willReturnSelf();
        $client->method('withTimeout')->willReturnSelf();
        $sent = [];
        $client
            ->expects(self::exactly(6))
            ->method('withAuthentication')
            ->willReturnCallback(
                static function (
                    string $id,
                    SecretPlacement $placement,
                    array $options = [],
                ) use (&$sent, $client): VaultHttpClientInterface {
                    self::assertSame(SecretPlacement::Bearer, $placement);
                    self::assertSame([], $options);
                    $sent[] = $id;
                    return $client;
                },
            );
        $client
            ->method('sendRequest')
            ->willReturnCallback($wire->sendRequest(...));
        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $vault->method('http')->willReturn($client);
        $transport = new McpHttpTransport(
            $vault,
            $this->createPublicDnsHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $actors = [
            AiActorContext::backendUser(7, false, []),
            AiActorContext::serviceAccount('worker'),
        ];
        $sessions = [$this->session('ephemeral-a', 3), $this->session('ephemeral-b', 3)];
        $factory = self::createMock(McpAuthenticationSessionFactoryInterface::class);
        $index = 0;
        $factory
            ->expects(self::exactly(2))
            ->method('openForExecution')
            ->willReturnCallback(
                static function (
                    McpServerRecord $server,
                    ?AiActorContext $actor,
                    McpOperationDeadline $deadline,
                    ?CancellationSignalInterface $cancellation = null,
                ) use (&$index, $actors, $sessions): McpCredentialSessionInterface {
                    self::assertSame($actors[$index], $actor);
                    self::assertSame('mcp-api', $server->delegationAudience);
                    self::assertFalse($deadline->isExhausted());
                    return $sessions[$index++];
                },
            );
        $mcp = $this->client($transport, $factory);
        foreach ($actors as $actor) {
            $mcp->callTool($this->server(), 'run', [], actor: $actor);
        }

        self::assertSame(
            [
                'ephemeral-a',
                'ephemeral-a',
                'ephemeral-a',
                'ephemeral-b',
                'ephemeral-b',
                'ephemeral-b',
            ],
            $sent,
        );
    }

    #[Test]
    public function aFailedToolResponseStillClosesTheOperationCredential(): void
    {
        $wire = (new McpTestServer())->willHandshake()->willReturnRaw('{}', 500);
        $factory = self::createStub(McpAuthenticationSessionFactoryInterface::class);
        $factory
            ->method('openForExecution')
            ->willReturn($this->session('ephemeral', 3));
        $this->expectException(McpTransportException::class);
        $this->expectExceptionCode(1799990212);
        $this
            ->client($this->seam($wire), $factory)
            ->callTool(
                $this->server(),
                'run',
                [],
                actor: AiActorContext::backendUser(7, false, []),
            );
    }

    #[Test]
    public function discoveryUsesItsOwnSessionAndNeverAnExecutionActor(): void
    {
        $wire = (new McpTestServer())
            ->willHandshake()
            ->willReturn(['tools' => []])
            ->willHandshake();
        $factory = self::createMock(McpAuthenticationSessionFactoryInterface::class);
        $factory->expects(self::never())->method('openForExecution');
        $factory
            ->expects(self::exactly(2))
            ->method('openForDiscovery')
            ->willReturnOnConsecutiveCalls($this->session('discovery', 3), $this->session('discovery', 2));
        $mcp = $this->client($this->seam($wire), $factory);
        $server = $this->server(discovery: 'configured-discovery');
        self::assertSame([], $mcp->listTools($server));
        self::assertTrue($mcp->ping($server)->reachable);
    }

    #[Test]
    public function missingDiscoveryIdentityDoesNotFallBackToTheLegacyCredential(): void
    {
        $wire = new McpTestServer();
        $factory = self::createMock(McpAuthenticationSessionFactoryInterface::class);
        $factory->expects(self::never())->method('openForDiscovery');
        $mcp = $this->client($this->seam($wire), $factory);
        self::assertFalse($mcp->ping($this->server())->reachable);
        self::assertSame([], $wire->received);
    }

    #[Test]
    public function directTransportCannotBypassTheModeOrSessionGate(): void
    {
        $wire = new McpTestServer();
        $transport = $this->seam($wire);
        foreach (['delegated', 'unknown', ''] as $mode) {
            try {
                $transport->call(
                    $this->server($mode),
                    'run',
                    [],
                    McpOperationDeadline::start(new FakeMcpClock(), 20),
                );
                self::fail('A session is mandatory.');
            } catch (McpTransportException $e) {
                self::assertSame(1799990219, $e->getCode());
            }
        }

        self::assertSame([], $wire->received);
    }

    #[Test]
    public function delegatedCredentialCannotBeForwardedToALegacyServer(): void
    {
        $wire = new McpTestServer();
        $session = self::createMock(McpCredentialSessionInterface::class);
        $session->expects(self::never())->method('credentialIdentifier');
        $this->expectException(McpTransportException::class);
        $this->expectExceptionMessage('unexpected_credential_session');
        $this
            ->seam($wire)
            ->call(
                $this->server('legacy'),
                'run',
                [],
                McpOperationDeadline::start(new FakeMcpClock(), 20),
                credentials: $session,
            );
    }

    #[Test]
    public function delegatedTransportExceptionsDoNotExposeCredentialText(): void
    {
        $wire = new McpTestServer();
        $session = self::createStub(McpCredentialSessionInterface::class);
        $session
            ->method('credentialIdentifier')
            ->willThrowException(new RuntimeException('secret-token-value'));
        try {
            $this
                ->seam($wire)
                ->call(
                    $this->server(),
                    'run',
                    [],
                    McpOperationDeadline::start(new FakeMcpClock(), 20),
                    credentials: $session,
                );
            self::fail('Transport must refuse the request.');
        } catch (McpTransportException $e) {
            self::assertStringNotContainsString(
                'secret-token-value',
                $e->getMessage(),
            );
            self::assertStringContainsString(
                'credential_transport_failed',
                $e->getMessage(),
            );
        }

        self::assertSame([], $wire->received);
    }

    #[Test]
    public function credentialRenewalSpendsTimeBeforeTheMcpLegTimeoutIsChosen(): void
    {
        $clock = new FakeMcpClock();
        $timeouts = [];
        $wire = (new McpTestServer())->willReturn(['ok' => true]);
        [$transport, $session] = $this->delayedCredentialTransport($clock, 7.0, $timeouts, $wire);
        $transport->call(
            $this->server(),
            'tools/call',
            [],
            McpOperationDeadline::start($clock, 10),
            credentials: $session,
        );
        self::assertSame([3], $timeouts);
        self::assertCount(1, $wire->received);
    }

    #[Test]
    public function credentialStorageThatExhaustsTheBudgetPreventsMcpContact(): void
    {
        $clock = new FakeMcpClock();
        $timeouts = [];
        $wire = new McpTestServer();
        [$transport, $session] = $this->delayedCredentialTransport($clock, 10.0, $timeouts, $wire);
        try {
            $transport->call(
                $this->server(),
                'tools/call',
                [],
                McpOperationDeadline::start($clock, 10),
                credentials: $session,
            );
            self::fail(
                'Credential exchange/storage consumed the MCP operation budget.',
            );
        } catch (McpTransportException $exception) {
            self::assertSame(
                1799990217,
                $exception->getCode(),
                $exception->getMessage(),
            );
            self::assertSame([], $timeouts);
            self::assertSame([], $wire->received);
        }
    }

    #[Test]
    public function cancellationAfterCredentialResolutionPreventsMcpContact(): void
    {
        $clock = new FakeMcpClock();
        $timeouts = [];
        $wire = new McpTestServer();
        [$transport, $session] = $this->delayedCredentialTransport($clock, 1.0, $timeouts, $wire);
        $signal = self::createStub(CancellationSignalInterface::class);
        $signal
            ->method('isCancelled')
            ->willReturnCallback(static fn(): bool => $clock->monotonicNanoseconds() >= 4243000000000);
        try {
            $transport->call(
                $this->server(),
                'tools/call',
                [],
                McpOperationDeadline::start($clock, 10),
                cancellation: $signal,
                credentials: $session,
            );
            self::fail(
                'A cancelled operation must not dispatch its MCP request.',
            );
        } catch (McpTransportException $exception) {
            self::assertTrue(
                $exception->isCancellation(),
                $exception->getMessage(),
            );
            self::assertSame([], $timeouts);
            self::assertSame([], $wire->received);
        }
    }

    /**
     * @param list<int> $timeouts
     *
     * @return array{McpHttpTransport, McpCredentialSessionInterface}
     */
    private function delayedCredentialTransport(
        FakeMcpClock $clock,
        float $delay,
        array &$timeouts,
        McpTestServer $wire,
    ): array {
        $client = self::createStub(VaultHttpClientInterface::class);
        $client->method('withReason')->willReturnSelf();
        $client->method('withAuthentication')->willReturnSelf();
        $client
            ->method('withTimeout')
            ->willReturnCallback(
                static function (
                    int $seconds,
                ) use (&$timeouts, $client): VaultHttpClientInterface {
                    $timeouts[] = $seconds;
                    return $client;
                },
            );
        $client
            ->method('sendRequest')
            ->willReturnCallback($wire->sendRequest(...));
        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $vault->method('http')->willReturn($client);
        $transport = new McpHttpTransport(
            $vault,
            $this->createPublicDnsHttpClientFactory(),
            new RequestFactory(new GuzzleClientFactory()),
            new StreamFactory(),
        );
        $session = self::createStub(McpCredentialSessionInterface::class);
        $session
            ->method('credentialIdentifier')
            ->willReturnCallback(
                static function () use ($clock, $delay): string {
                    // Models the complete exchange and subsequent Vault store/cleanup work.
                    $clock->advanceSeconds($delay);
                    return 'ephemeral-delayed';
                },
            );
        return [$transport, $session];
    }
}

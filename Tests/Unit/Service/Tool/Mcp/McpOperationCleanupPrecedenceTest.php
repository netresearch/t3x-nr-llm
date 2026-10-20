<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp;

use Closure;
use Error;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use JsonSerializable;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\McpCallOutcome;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\ConfiguredMcpSubjectResolver;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpAuthenticationSessionFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpAuthenticationSessionFactoryInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpCredentialSessionInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpDelegatedCredentialSession;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpTokenExchange;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpClient;
use Netresearch\NrLlm\Service\Tool\Mcp\McpDeadlineFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\McpHttpTransport;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\RecordedContacts;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Domain\Dto\SecretDetails;
use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Http\CancellationSignalInterface;
use Netresearch\NrVault\Http\DnsResolverInterface;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Http\VaultHttpClient;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Operation failure precedence through the real resolver, factory, exchange,
 * delegated session, client, transport and Vault credential injection.
 * Persistence, fluent HTTP configuration and final PSR-18 responses are seams.
 *
 * Supplementary custom-session cases wrap the real stock session and emit a raw
 * close Error only after its healthy normal deletion. Stock deletion Error is
 * sanitized by the delegated session; it never supplies that raw-Error seam.
 * These finite cases claim no live HTTP, SQL/ACL/audit durability or erasure.
 *
 * @phpstan-type Primary 'cancelled'|'deadline'|'rpc'|'malformed'|'healthy'|'remote_error'|'serialization'
 * @phpstan-type Cleanup 'healthy'|'runtime'|'error'
 * @phpstan-type AuthenticationOptions array{headerName?:string,prefix?:string,queryParam?:string,bodyField?:string,usernameSecret?:string,reason?:string}
 * @phpstan-type Kernel array{client:McpClient,factory:McpAuthenticationSessionFactory,server:McpServerRecord,clock:FakeMcpClock,signal:McpOperationCleanupSignal,contacts:RecordedContacts,before:int}
 */
#[CoversClass(McpAuthenticationSessionFactory::class)]
#[CoversClass(ConfiguredMcpSubjectResolver::class)]
#[CoversClass(McpTokenExchange::class)]
#[CoversClass(McpDelegatedCredentialSession::class)]
#[CoversClass(McpClient::class)]
#[CoversClass(McpHttpTransport::class)]
final class McpOperationCleanupPrecedenceTest extends TestCase
{
    private const SUBJECT = '11111111-1111-7111-8111-111111111111';

    private const SUBJECT_VALUE = 'subject-close-secret';

    private const ISSUED_VALUE = 'issued-close-secret';

    private const CLEANUP_MESSAGE = 'Delegated authentication for MCP server "close_probe" was refused (cleanup_failed).';

    private const CLOSED_MESSAGE = 'Delegated authentication for MCP server "close_probe" was refused (session_closed).';

    /** @var list<array{identifier:string,value:string,options:array<array-key,mixed>}> */
    private array $stores = [];

    /** @var list<array{identifier:string,reason:string}> */
    private array $deletes = [];

    /** @var list<string> */
    private array $metadata = [];

    /** @var list<string> */
    private array $reads = [];

    /** @var list<string> */
    private array $exists = [];

    /** @var list<string> */
    private array $events = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<array{identifier:string,placement:SecretPlacement,options:array<array-key,mixed>}> */
    private array $authentication = [];

    /** @var list<int> */
    private array $timeouts = [];

    /** @var list<string> */
    private array $reasons = [];

    /** @var array<string,string> */
    private array $values = [];

    private string $cleanup = 'healthy';

    /** @var list<McpOperationCleanupRawErrorSession> */
    private array $customSessions = [];

    /** @var list<array{server:McpServerRecord,actor:?AiActorContext,deadline:McpOperationDeadline,cancellation:?CancellationSignalInterface}> */
    private array $customFactoryCalls = [];

    /**
     * @return iterable<string,array{string,string,int,string,bool}>
     */
    public static function primaryCases(): iterable
    {
        $cases = [
            'cancelled' => [1799990218, 'The call to MCP server "close_probe" was cancelled.', true],
            'deadline'  => [
                1799990217,
                'The 10-second operation budget for MCP server "close_probe" ran out before this request was sent, so the server was not asked. This is the budget this installation sets, not a server that did not answer.',
                false,
            ],
            'rpc' => [
                1799990214,
                'MCP server "close_probe" refused the call with JSON-RPC error -32602: Remote request refused.',
                false,
            ],
            'malformed' => [
                1799990213,
                'MCP server "close_probe" sent a response that is not a JSON-RPC result: the MCP result is not a JSON object',
                false,
            ],
        ];
        foreach ($cases as $primary => [$code, $message, $cancelled]) {
            foreach (['healthy', 'runtime', 'error'] as $cleanup) {
                yield $primary . ' / ' . $cleanup => [$primary, $cleanup, $code, $message, $cancelled];
            }
        }
    }

    #[Test]
    #[DataProvider('primaryCases')]
    public function aCompletedPrimaryFailureSurvivesTheStockSessionClose(
        string $primary,
        string $cleanup,
        int $expectedCode,
        string $expectedMessage,
        bool $expectedCancellation,
    ): void {
        $kernel  = $this->kernel($primary, $cleanup);
        $outcome = null;
        $failure = null;
        try {
            $outcome = $kernel['client']->callTool(
                $kernel['server'],
                'read_document',
                ['document' => 'fixture-document'],
                $kernel['signal'],
                AiActorContext::backendUser(7, false, [91]),
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertActualOperationCapture($kernel, $primary, $cleanup);
        self::assertNull($outcome);
        self::assertSame([], $kernel['contacts']->contacts);
        self::assertInstanceOf(McpTransportException::class, $failure);
        self::assertSame($expectedCancellation, $failure->isCancellation());
        self::assertStringNotContainsString('private-cleanup', $failure->getMessage());
        self::assertStringNotContainsString(self::SUBJECT_VALUE, $failure->getMessage());
        self::assertStringNotContainsString(self::ISSUED_VALUE, $failure->getMessage());
        self::assertSame($expectedCode, $failure->getCode());
        self::assertSame($expectedMessage, $failure->getMessage());
        self::assertNull($failure->getPrevious());
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function cleanupCases(): iterable
    {
        yield 'healthy cleanup' => ['healthy'];
        yield 'cleanup RuntimeException' => ['runtime'];
        yield 'cleanup Error' => ['error'];
    }

    #[Test]
    #[DataProvider('cleanupCases')]
    public function aSuccessfulOperationStillReportsAStockCleanupFailure(string $cleanup): void
    {
        $kernel  = $this->kernel('healthy', $cleanup);
        $outcome = null;
        $failure = null;
        try {
            $outcome = $kernel['client']->callTool(
                $kernel['server'],
                'read_document',
                ['document' => 'fixture-document'],
                $kernel['signal'],
                AiActorContext::backendUser(7, false, [91]),
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertActualOperationCapture($kernel, 'healthy', $cleanup);
        $this->assertCompletedStockOutcome($kernel, $cleanup, $outcome, $failure, 'operation-ok', false);
    }

    #[Test]
    #[DataProvider('cleanupCases')]
    public function aReturnedRemoteToolErrorStillReportsAStockCleanupFailure(string $cleanup): void
    {
        $kernel  = $this->kernel('remote_error', $cleanup);
        $outcome = null;
        $failure = null;
        try {
            $outcome = $kernel['client']->callTool(
                $kernel['server'],
                'read_document',
                ['document' => 'fixture-document'],
                $kernel['signal'],
                AiActorContext::backendUser(7, false, [91]),
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertActualOperationCapture($kernel, 'remote_error', $cleanup);
        $this->assertCompletedStockOutcome(
            $kernel,
            $cleanup,
            $outcome,
            $failure,
            'The remote tool reported an error: remote-refused',
            true,
        );
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function callerFailureCases(): iterable
    {
        foreach (['error', 'runtime'] as $primary) {
            foreach (['healthy', 'runtime', 'error'] as $cleanup) {
                yield $primary . ' / ' . $cleanup => [$primary, $cleanup];
            }
        }
    }

    #[Test]
    #[DataProvider('callerFailureCases')]
    public function theSameCallerSerializationThrowableSurvivesTheStockSessionClose(
        string $primaryKind,
        string $cleanup,
    ): void {
        $primary             = $primaryKind === 'error' ? new Error('Caller argument could not be serialized.', 1800100008) : new RuntimeException('Caller argument could not be serialized.', 1800100009);
        $kernel              = $this->kernel('serialization', $cleanup);
        $serializationEvents = [];
        $argument            = $this->callerArgument($primary, $serializationEvents);
        $outcome             = null;
        $failure             = null;
        try {
            $outcome = $kernel['client']->callTool(
                $kernel['server'],
                'read_document',
                ['caller' => $argument],
                $kernel['signal'],
                AiActorContext::backendUser(7, false, [91]),
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertActualOperationCapture($kernel, 'serialization', $cleanup);
        $this->assertSerializationAfterStockHandshake($argument, $serializationEvents);
        self::assertNull($outcome);
        self::assertSame([], $kernel['contacts']->contacts);
        self::assertInstanceOf($primary::class, $failure);
        self::assertSame($primary, $failure);
        self::assertNull($primary->getPrevious());
    }

    #[Test]
    #[DataProvider('cleanupCases')]
    public function aHealthyCallerSerializationReachesTheWireAndRetainsStockCleanupOutcome(string $cleanup): void
    {
        $kernel              = $this->kernel('healthy', $cleanup);
        $serializationEvents = [];
        $argument            = $this->callerArgument(null, $serializationEvents);
        $outcome             = null;
        $failure             = null;
        try {
            $outcome = $kernel['client']->callTool(
                $kernel['server'],
                'read_document',
                ['caller' => $argument],
                $kernel['signal'],
                AiActorContext::backendUser(7, false, [91]),
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertActualOperationCapture($kernel, 'healthy', $cleanup);
        $this->assertSerializationAfterStockHandshake($argument, $serializationEvents);
        $decoded = json_decode((string) $this->requests[3]->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame(
            ['name' => 'read_document', 'arguments' => ['caller' => ['document' => 'fixture-document']]],
            $decoded['params'],
        );
        $this->assertCompletedStockOutcome($kernel, $cleanup, $outcome, $failure, 'operation-ok', false);
    }

    /**
     * The raw Error belongs to a supplementary custom credential-session
     * contract, after a healthy real stock close; it is not a stock delete Error.
     */
    #[Test]
    public function aCustomSessionRawCloseErrorIsSubordinateToTheSamePrimaryError(): void
    {
        $closeFailure        = new Error('Custom credential-session close failed.', 1800100010);
        $primary             = new Error('Caller argument could not be serialized.', 1800100011);
        $kernel              = $this->kernel('serialization', 'healthy', $closeFailure);
        $actor               = AiActorContext::backendUser(7, false, [91]);
        $serializationEvents = [];
        $argument            = $this->callerArgument($primary, $serializationEvents);
        $outcome             = null;
        $failure             = null;
        try {
            $outcome = $kernel['client']->callTool(
                $kernel['server'],
                'read_document',
                ['caller' => $argument],
                $kernel['signal'],
                $actor,
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertActualOperationCapture($kernel, 'serialization', 'healthy');
        $this->assertSerializationAfterStockHandshake($argument, $serializationEvents);
        $this->assertCustomSessionCloseCapture($kernel, $actor, $closeFailure, 2);
        self::assertNull($outcome);
        self::assertSame([], $kernel['contacts']->contacts);
        self::assertInstanceOf(Error::class, $failure);
        self::assertSame($primary, $failure);
        self::assertNull($primary->getPrevious());
    }

    /**
     * No primary Throwable exists when the remote operation returns normally.
     */
    #[Test]
    public function aCustomSessionRawCloseErrorPropagatesUnchangedWithoutAPrimary(): void
    {
        $closeFailure = new Error('Custom credential-session close failed.', 1800100012);
        $kernel       = $this->kernel('healthy', 'healthy', $closeFailure);
        $actor        = AiActorContext::backendUser(7, false, [91]);
        $outcome      = null;
        $failure      = null;
        try {
            $outcome = $kernel['client']->callTool(
                $kernel['server'],
                'read_document',
                ['document' => 'fixture-document'],
                $kernel['signal'],
                $actor,
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertActualOperationCapture($kernel, 'healthy', 'healthy');
        $this->assertCustomSessionCloseCapture($kernel, $actor, $closeFailure, 3);
        self::assertNull($outcome);
        self::assertCount(1, $kernel['contacts']->contacts);
        self::assertSame('close_probe', $kernel['contacts']->contacts[0]['identifier']);
        self::assertGreaterThanOrEqual(0, $kernel['contacts']->contacts[0]['latencyMs']);
        self::assertInstanceOf(Error::class, $failure);
        self::assertSame($closeFailure, $failure);
        self::assertNull($closeFailure->getPrevious());
    }

    #[Test]
    #[DataProvider('cleanupCases')]
    public function anExplicitStockSessionCloseRetainsItsOwnPublicCleanupContract(string $cleanup): void
    {
        $kernel  = $this->kernel('healthy', $cleanup);
        $session = $kernel['factory']->openForExecution(
            $kernel['server'],
            AiActorContext::backendUser(7, false, [91]),
            McpOperationDeadline::start($kernel['clock'], 10),
            $kernel['signal'],
        );
        self::assertInstanceOf(McpDelegatedCredentialSession::class, $session);
        $reference = $session->credentialIdentifier();
        $failure   = null;
        try {
            $session->close();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        self::assertCount(1, $this->stores);
        self::assertSame($this->stores[0]['identifier'], $reference);
        self::assertSame([['identifier' => $reference, 'reason' => 'MCP delegated credential cleanup']], $this->deletes);
        self::assertSame(['retrieve_subject', 'idp', 'store', 'metadata', 'delete'], $this->events);
        if ($cleanup === 'healthy') {
            self::assertNull($failure);
        } else {
            self::assertInstanceOf(McpTransportException::class, $failure);
            self::assertSame(1799990219, $failure->getCode());
            self::assertSame(self::CLEANUP_MESSAGE, $failure->getMessage());
            self::assertFalse($failure->isCancellation());
            self::assertNull($failure->getPrevious());
        }

        $closedFailure = null;
        try {
            $session->credentialIdentifier();
        } catch (Throwable $exception) {
            $closedFailure = $exception;
        }

        self::assertInstanceOf(McpTransportException::class, $closedFailure);
        self::assertSame(1799990219, $closedFailure->getCode());
        self::assertSame(self::CLOSED_MESSAGE, $closedFailure->getMessage());
        self::assertFalse($closedFailure->isCancellation());
        self::assertCount(1, $this->requests);

        // A failed explicit close may retry its normal deletion; it never reopens.
        $this->cleanup = 'healthy';
        $retryFailure  = null;
        try {
            $session->close();
        } catch (Throwable $exception) {
            $retryFailure = $exception;
        }

        self::assertNull($retryFailure);
        self::assertCount($cleanup === 'healthy' ? 1 : 2, $this->deletes);
        self::assertSame($reference, $this->deletes[array_key_last($this->deletes)]['identifier']);
        self::assertCount(1, $this->requests);
        self::assertArrayNotHasKey($reference, $this->values);
    }

    /**
     * @return Kernel
     */
    private function kernel(string $primary, string $cleanup, ?Error $customCloseFailure = null): array
    {
        $this->cleanup = $cleanup;
        $this->values  = [self::SUBJECT => self::SUBJECT_VALUE];
        $clock         = new FakeMcpClock();
        $signal        = new McpOperationCleanupSignal();
        $profiles      = [
            'office' => [
                'tokenEndpoint'    => 'https://idp.example.com/token',
                'clientId'         => 'public-client',
                'allowedAudiences' => ['urn:one'],
                'allowedScopes'    => ['read'],
                'backendUsers'     => [
                    7 => [
                        'enabled'              => true,
                        'credentialIdentifier' => self::SUBJECT,
                        'allowedAudiences'     => ['urn:one'],
                        'allowedScopes'        => ['read'],
                    ],
                ],
                'serviceAccounts' => [],
            ],
        ];
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration
            ->method('get')
            ->willReturnCallback(
                static fn (string $extension, string $path = ''): mixed => match ($path) {
                    'mcpDelegationProfiles' => $profiles,
                    'mcpOperationTimeout'   => '10',
                    default                 => null,
                },
            );
        $dns = self::createStub(DnsResolverInterface::class);
        $dns->method('resolve')->willReturn([['ip' => '93.184.215.14']]);
        $secureFactory = new SecureHttpClientFactory($dns);
        $vault         = self::createStub(VaultServiceInterface::class);
        $vault
            ->method('store')
            ->willReturnCallback(
                function (string $identifier, string $value, array $options): void {
                    $this->events[]            = 'store';
                    $this->stores[]            = ['identifier' => $identifier, 'value' => $value, 'options' => $options];
                    $this->values[$identifier] = $value;
                },
            );
        $vault
            ->method('getMetadata')
            ->willReturnCallback(
                function (string $identifier): SecretDetails {
                    $this->events[]   = 'metadata';
                    $this->metadata[] = $identifier;
                    foreach ($this->stores as $store) {
                        if ($store['identifier'] === $identifier) {
                            return $this->storedSecretDetails($identifier, $store['options']);
                        }
                    }

                    throw new RuntimeException('Fixture metadata is unavailable.', 1800100001);
                },
            );
        $vault
            ->method('exists')
            ->willReturnCallback(
                function (string $identifier): bool {
                    $this->events[] = 'exists';
                    $this->exists[] = $identifier;

                    return array_key_exists($identifier, $this->values);
                },
            );
        $vault
            ->method('retrieve')
            ->willReturnCallback(
                function (string $identifier): string {
                    $this->events[] = $identifier === self::SUBJECT ? 'retrieve_subject' : 'retrieve_issued';
                    $this->reads[]  = $identifier;

                    return $this->values[$identifier] ?? throw new RuntimeException('Fixture credential unavailable.', 1800100002);
                },
            );
        $vault
            ->method('delete')
            ->willReturnCallback(
                function (string $identifier, string $reason): void {
                    $this->events[]  = 'delete';
                    $this->deletes[] = ['identifier' => $identifier, 'reason' => $reason];
                    if ($this->cleanup === 'runtime') {
                        throw new RuntimeException('private-cleanup issued-close-secret', 1800100003);
                    }

                    if ($this->cleanup === 'error') {
                        throw new Error('private-cleanup issued-close-secret', 1800100004);
                    }

                    unset($this->values[$identifier]);
                },
            );

        $wire = self::createStub(ClientInterface::class);
        $wire
            ->method('sendRequest')
            ->willReturnCallback(
                function (RequestInterface $request) use ($primary, $clock, $signal): ResponseInterface {
                    $this->requests[] = $request;
                    if ($request->getUri()->getHost() === 'idp.example.com') {
                        $this->events[] = 'idp';

                        return $this->jsonResponse(
                            [
                                'access_token'      => self::ISSUED_VALUE,
                                'token_type'        => 'Bearer',
                                'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                                'expires_in'        => 60,
                            ],
                        );
                    }

                    $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($decoded)) {
                        throw new RuntimeException('Fixture received an invalid JSON-RPC request.', 1800100014);
                    }

                    $method = $decoded['method'] ?? '';
                    if (!is_string($method)) {
                        throw new RuntimeException('Fixture received an invalid method.', 1800100005);
                    }

                    $this->events[] = $method;
                    if ($method === 'initialize') {
                        return $this->jsonResponse(
                            [
                                'jsonrpc' => '2.0',
                                'id'      => $decoded['id'],
                                'result'  => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass()],
                            ],
                        )->withHeader('Mcp-Session-Id', 'probe-session');
                    }

                    if ($method === 'notifications/initialized') {
                        if ($primary === 'cancelled') {
                            $signal->cancelled = true;
                        }

                        if ($primary === 'deadline') {
                            $clock->advanceSeconds(10);
                        }

                        return new Response(202);
                    }

                    if ($method !== 'tools/call') {
                        throw new RuntimeException('Fixture received an unexpected request.', 1800100006);
                    }

                    return $this->jsonResponse(
                        match ($primary) {
                            'rpc' => [
                                'jsonrpc' => '2.0',
                                'id'      => $decoded['id'],
                                'error'   => ['code' => -32602, 'message' => "Remote\nrequest refused."],
                            ],
                            'malformed' => ['jsonrpc' => '2.0', 'id' => $decoded['id'], 'result' => 'wrong-type'],
                            default     => [
                                'jsonrpc' => '2.0',
                                'id'      => $decoded['id'],
                                'result'  => [
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => $primary === 'remote_error' ? 'remote-refused' : 'operation-ok',
                                        ],
                                    ],
                                    'isError' => $primary === 'remote_error',
                                ],
                            ],
                        },
                    );
                },
            );
        $audit   = self::createStub(AuditLogServiceInterface::class);
        $http    = self::createStub(VaultHttpClientInterface::class);
        $binding = null;
        $http
            ->method('withTimeout')
            ->willReturnCallback(
                function (int $timeout) use ($http): VaultHttpClientInterface {
                    $this->timeouts[] = $timeout;

                    return $http;
                },
            );
        $http
            ->method('withReason')
            ->willReturnCallback(
                function (string $reason) use ($http): VaultHttpClientInterface {
                    $this->reasons[] = $reason;

                    return $http;
                },
            );
        $http
            ->method('withAuthentication')
            ->willReturnCallback(
                function (
                    string $identifier,
                    SecretPlacement $placement,
                    array $options = [],
                ) use ($http, &$binding): VaultHttpClientInterface {
                    $binding = [
                        'identifier' => $identifier,
                        'placement'  => $placement,
                        'options'    => $this->boundAuthenticationOptions($options),
                    ];
                    $this->authentication[] = $binding;

                    return $http;
                },
            );
        $http
            ->method('sendRequest')
            ->willReturnCallback(
                static function (
                    RequestInterface $request,
                ) use (&$binding, $vault, $wire, $audit, $secureFactory): ResponseInterface {
                    if ($binding === null) {
                        throw new RuntimeException('Fixture HTTP client is unbound.', 1800100007);
                    }

                    // The real Vault injector gates the host and injects the actual
                    // selected subject/body-field or issued/Bearer value.
                    return (new VaultHttpClient($vault, $audit, $wire, secureHttpClientFactory: $secureFactory))->withAuthentication($binding['identifier'], $binding['placement'], $binding['options'])->sendRequest(
                        $request,
                    );
                },
            );
        $vault->method('http')->willReturn($http);
        $factory = new McpAuthenticationSessionFactory(
            $configuration,
            new ConfiguredMcpSubjectResolver($configuration),
            $vault,
            $secureFactory,
            $clock,
        );
        $executionFactory = $factory;
        if ($customCloseFailure instanceof Error) {
            // The optional factory seam returns a custom wrapper. Its raw close
            // Error is separate from stock Session.close's sanitized contract.
            $executionFactory = self::createStub(McpAuthenticationSessionFactoryInterface::class);
            $executionFactory
                ->method('openForExecution')
                ->willReturnCallback(
                    function (
                        McpServerRecord $server,
                        ?AiActorContext $actor,
                        McpOperationDeadline $deadline,
                        ?CancellationSignalInterface $cancellation = null,
                    ) use ($factory, $customCloseFailure): McpCredentialSessionInterface {
                        $this->customFactoryCalls[] = [
                            'server'       => $server,
                            'actor'        => $actor,
                            'deadline'     => $deadline,
                            'cancellation' => $cancellation,
                        ];
                        $stockSession = $factory->openForExecution($server, $actor, $deadline, $cancellation);
                        if (!$stockSession instanceof McpDelegatedCredentialSession) {
                            throw new RuntimeException('Fixture custom factory has no stock delegated session.', 1800100013);
                        }

                        $session                = new McpOperationCleanupRawErrorSession($stockSession, $customCloseFailure);
                        $this->customSessions[] = $session;

                        return $session;
                    },
                );
        }

        $psrFactory = new HttpFactory();
        $transport  = new McpHttpTransport($vault, $secureFactory, $psrFactory, $psrFactory);
        $contacts   = new RecordedContacts();
        $server     = new McpServerRecord(
            uid: 1,
            pid: 0,
            identifier: 'close_probe',
            name: 'Close probe',
            description: '',
            url: 'https://mcp.example.com/rpc',
            authCredential: 'LegacyMustNotBeUsed',
            authPlacement: 'bearer',
            authHeaderName: '',
            dataClass: 'publicContent',
            requiresApproval: '1',
            enabled: true,
            importStatus: 'never_imported',
            importError: '',
            lastImported: 0,
            toolCount: 0,
            lastContact: 0,
            lastLatencyMs: 0,
            tstamp: 0,
            crdate: 0,
            authMode: 'delegated',
            delegationProfile: 'office',
            delegationAudience: 'urn:one',
            delegationScopes: 'read',
            discoveryCredential: '',
        );

        return [
            'client'   => new McpClient($transport, $contacts, new McpDeadlineFactory($clock, $configuration), $executionFactory),
            'factory'  => $factory,
            'server'   => $server,
            'clock'    => $clock,
            'signal'   => $signal,
            'contacts' => $contacts,
            'before'   => time(),
        ];
    }

    /**
     * @param Kernel $kernel
     */
    private function assertActualOperationCapture(array $kernel, string $primary, string $cleanup): void
    {
        self::assertCount(1, $this->stores);
        $store     = $this->stores[0];
        $reference = $store['identifier'];
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($reference));
        self::assertSame(self::ISSUED_VALUE, $store['value']);
        self::assertSame(7, $store['options']['owner']);
        self::assertSame([], $store['options']['groups']);
        self::assertSame('mcp_delegation', $store['options']['context']);
        self::assertSame(
            [
                'server'         => 'close_probe',
                'profile'        => 'office',
                'audience'       => 'urn:one',
                'scopes'         => ['read'],
                'actorUid'       => 7,
                'serviceAccount' => null,
            ],
            $store['options']['metadata'],
        );
        self::assertGreaterThanOrEqual($kernel['before'] + 60, $store['options']['expiresAt']);
        self::assertLessThanOrEqual(time() + 60, $store['options']['expiresAt']);
        self::assertSame([$reference], $this->metadata);
        self::assertSame([['identifier' => $reference, 'reason' => 'MCP delegated credential cleanup']], $this->deletes);
        self::assertSame($cleanup !== 'healthy', array_key_exists($reference, $this->values));

        $sentCall = !in_array($primary, ['cancelled', 'deadline', 'serialization'], true);
        $legs     = $sentCall ? 3 : 2;
        self::assertCount($legs + 1, $this->requests);
        self::assertSame(array_fill(0, $legs, $reference), $this->exists);
        self::assertSame([self::SUBJECT, ...array_fill(0, $legs, $reference)], $this->reads);
        self::assertSame(array_fill(0, $legs + 1, 10), $this->timeouts);
        self::assertSame(
            ['MCP delegated token exchange', ...array_fill(0, $legs, 'nr-llm MCP call to "close_probe"')],
            $this->reasons,
        );
        self::assertSame(
            [
                [
                    'identifier' => self::SUBJECT,
                    'placement'  => SecretPlacement::BodyField,
                    'options'    => ['bodyField' => 'subject_token'],
                ],
                ...array_fill(
                    0,
                    $legs,
                    ['identifier' => $reference, 'placement' => SecretPlacement::Bearer, 'options' => []],
                ),
            ],
            $this->authentication,
        );
        $events = ['retrieve_subject', 'idp', 'store', 'metadata'];
        foreach (['initialize', 'notifications/initialized'] as $method) {
            $events[] = 'exists';
            $events[] = 'retrieve_issued';
            $events[] = $method;
        }

        if ($sentCall) {
            $events[] = 'exists';
            $events[] = 'retrieve_issued';
            $events[] = 'tools/call';
        }

        $events[] = 'delete';
        self::assertSame($events, $this->events);

        $form = [];
        parse_str((string) $this->requests[0]->getBody(), $form);
        self::assertSame('https://idp.example.com/token', (string) $this->requests[0]->getUri());
        self::assertSame('', $this->requests[0]->getHeaderLine('Authorization'));
        self::assertSame(
            [
                'grant_type'           => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token_type'   => 'urn:ietf:params:oauth:token-type:access_token',
                'requested_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'client_id'            => 'public-client',
                'audience'             => 'urn:one',
                'scope'                => 'read',
                'subject_token'        => self::SUBJECT_VALUE,
            ],
            $form,
        );
        foreach (array_slice($this->requests, 1) as $position => $request) {
            self::assertSame('https://mcp.example.com/rpc', (string) $request->getUri());
            self::assertSame('Bearer ' . self::ISSUED_VALUE, $request->getHeaderLine('Authorization'));
            self::assertSame($position === 0 ? '' : 'probe-session', $request->getHeaderLine('Mcp-Session-Id'));
            self::assertStringNotContainsString(self::SUBJECT_VALUE, (string) $request->getBody());
            self::assertStringNotContainsString('LegacyMustNotBeUsed', (string) $request->getBody());
            $decoded = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertSame('2.0', $decoded['jsonrpc']);
            self::assertSame(['initialize', 'notifications/initialized', 'tools/call'][$position], $decoded['method']);
            if ($position === 1) {
                self::assertArrayNotHasKey('id', $decoded);
            } else {
                self::assertArrayHasKey('id', $decoded);
                self::assertTrue(is_int($decoded['id']) || is_string($decoded['id']));
            }
        }
    }

    /**
     * @param Kernel $kernel
     */
    private function assertCompletedStockOutcome(
        array $kernel,
        string $cleanup,
        ?McpCallOutcome $outcome,
        ?Throwable $failure,
        string $expectedText,
        bool $expectedIsError,
    ): void {
        self::assertCount(1, $kernel['contacts']->contacts);
        self::assertSame('close_probe', $kernel['contacts']->contacts[0]['identifier']);
        self::assertGreaterThanOrEqual(0, $kernel['contacts']->contacts[0]['latencyMs']);
        if ($cleanup === 'healthy') {
            self::assertNull($failure);
            self::assertInstanceOf(McpCallOutcome::class, $outcome);
            self::assertSame($expectedText, $outcome->text);
            self::assertSame($expectedIsError, $outcome->isError);

            return;
        }

        self::assertNull($outcome);
        self::assertInstanceOf(McpTransportException::class, $failure);
        self::assertSame(1799990219, $failure->getCode());
        self::assertSame(self::CLEANUP_MESSAGE, $failure->getMessage());
        self::assertFalse($failure->isCancellation());
        self::assertNull($failure->getPrevious());
    }

    /**
     * @param list<list<string>> $serializationEvents
     */
    private function callerArgument(
        ?Throwable $primary,
        array &$serializationEvents,
    ): McpOperationCleanupSerializableArgument {
        return new McpOperationCleanupSerializableArgument(
            function () use ($primary, &$serializationEvents): void {
                $serializationEvents[] = $this->events;
                if ($primary instanceof Throwable) {
                    throw $primary;
                }
            },
        );
    }

    /**
     * @param list<list<string>> $serializationEvents
     */
    private function assertSerializationAfterStockHandshake(
        McpOperationCleanupSerializableArgument $argument,
        array $serializationEvents,
    ): void {
        self::assertSame(1, $argument->calls);
        self::assertSame(
            [
                [
                    'retrieve_subject',
                    'idp',
                    'store',
                    'metadata',
                    'exists',
                    'retrieve_issued',
                    'initialize',
                    'exists',
                    'retrieve_issued',
                    'notifications/initialized',
                ],
            ],
            $serializationEvents,
        );
    }

    /**
     * @param Kernel $kernel
     */
    private function assertCustomSessionCloseCapture(
        array $kernel,
        AiActorContext $actor,
        Error $closeFailure,
        int $expectedCredentialChecks,
    ): void {
        self::assertCount(1, $this->customFactoryCalls);
        $opening = $this->customFactoryCalls[0];
        self::assertSame($kernel['server'], $opening['server']);
        self::assertSame($actor, $opening['actor']);
        self::assertSame($kernel['signal'], $opening['cancellation']);
        self::assertSame(10, $opening['deadline']->totalSeconds());
        self::assertCount(1, $this->customSessions);
        $session = $this->customSessions[0];
        self::assertInstanceOf(McpDelegatedCredentialSession::class, $session->delegate);
        self::assertSame($closeFailure, $session->closeFailure);
        self::assertSame($expectedCredentialChecks, $session->credentialChecks);
        self::assertSame(1, $session->closeAttempts);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function jsonResponse(array $payload): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<array-key,mixed> $options
     */
    private function storedSecretDetails(string $identifier, array $options): SecretDetails
    {
        $owner     = $options['owner'] ?? null;
        $context   = $options['context'] ?? null;
        $expiresAt = $options['expiresAt'] ?? null;
        $groups    = $options['groups'] ?? null;
        $metadata  = $options['metadata'] ?? null;
        if (!is_int($owner) || !is_string($context) || !is_int($expiresAt) || !is_array($groups) || !array_is_list($groups) || !is_array($metadata)) {
            throw new RuntimeException('Fixture stored credential options have an unexpected shape.', 1800100015);
        }

        $allowedGroups = [];
        foreach ($groups as $group) {
            if (!is_int($group)) {
                throw new RuntimeException('Fixture stored credential group is not an integer.', 1800100016);
            }

            $allowedGroups[] = $group;
        }

        $storedMetadata = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key)) {
                throw new RuntimeException('Fixture stored credential metadata key is not a string.', 1800100017);
            }

            $storedMetadata[$key] = $value;
        }

        return SecretDetails::fromSecret(
            new Secret(
                identifier: $identifier,
                ownerUid: $owner,
                allowedGroups: $allowedGroups,
                context: $context,
                expiresAt: $expiresAt,
                metadata: $storedMetadata,
            ),
        );
    }

    /**
     * @param array<array-key,mixed> $options
     *
     * @return AuthenticationOptions
     */
    private function boundAuthenticationOptions(array $options): array
    {
        if ($options === []) {
            return [];
        }

        if (array_keys($options) !== ['bodyField'] || !is_string($options['bodyField'])) {
            throw new RuntimeException('Fixture authentication options have an unexpected shape.', 1800100018);
        }

        return ['bodyField' => $options['bodyField']];
    }
}

final class McpOperationCleanupSignal implements CancellationSignalInterface
{
    public bool $cancelled = false;

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}

/**
 * A legitimate caller-owned array<string,mixed> value for tools/call encoding.
 */
final class McpOperationCleanupSerializableArgument implements JsonSerializable
{
    public int $calls = 0;

    /**
     * @param Closure():void $beforeSerialize
     */
    public function __construct(private readonly Closure $beforeSerialize) {}

    /**
     * @return array{document:string}
     */
    public function jsonSerialize(): array
    {
        ++$this->calls;
        ($this->beforeSerialize)();

        return ['document' => 'fixture-document'];
    }
}

/**
 * Finite custom-session @api contract seam, not stock raw-Error behavior.
 * It forwards credential checks and performs one healthy stock normal close
 * before emitting the retained raw Error supplied to this wrapper.
 */
final class McpOperationCleanupRawErrorSession implements McpCredentialSessionInterface
{
    public int $credentialChecks = 0;

    public int $closeAttempts = 0;

    public function __construct(
        public readonly McpCredentialSessionInterface $delegate,
        public readonly Error $closeFailure,
    ) {}

    public function credentialIdentifier(): string
    {
        ++$this->credentialChecks;

        return $this->delegate->credentialIdentifier();
    }

    public function close(): void
    {
        ++$this->closeAttempts;
        $this->delegate->close();

        throw $this->closeFailure;
    }
}

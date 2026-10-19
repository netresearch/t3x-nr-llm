<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\Auth;

use Error;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpDelegationProfile;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpIssuedCredential;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpSubjectCredential;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpTokenExchange;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Domain\Dto\SecretDetails;
use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Exception\RequestCancelledException;
use Netresearch\NrVault\Http\CancellationSignalInterface;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Throwable;

/**
 * @phpstan-type Primary 'healthy'|'cancelled'|'deadline'|'metadata_runtime'|'metadata_error'|'metadata_cancelled'
 * @phpstan-type Cleanup 'healthy'|'runtime'|'error'|'cancelled'
 * @phpstan-type Binding array{server:string,profile:string,audience:string,scopes:list<string>,actorUid:int|null,serviceAccount:string|null}
 * @phpstan-type StoreOptions array{owner:int|null,groups:list<int>,context:string,expiresAt:int,metadata:Binding}
 * @phpstan-type ExerciseResult array{credential:McpIssuedCredential|null,exception:Throwable|null,before:int,after:int,startedNanoseconds:int}
 */
#[CoversClass(McpTokenExchange::class)]
final class McpPrimaryCleanupOutcomeTest extends AbstractUnitTestCase
{
    private const SUBJECT = '11111111-1111-7111-8111-111111111111';

    private const ISSUED_VALUE = 'issued-test-value';

    /** @var list<array{identifier:string,value:string,options:array<array-key,mixed>}> */
    private array $stores = [];

    /** @var list<array{identifier:string,reason:string}> */
    private array $deletes = [];

    /** @var list<string> */
    private array $metadata = [];

    /** @var list<array{0:string,1:SecretPlacement,2:array<array-key,mixed>}> */
    private array $authentication = [];

    /** @var list<int> */
    private array $timeouts = [];

    /** @var list<string> */
    private array $reasons = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<string> */
    private array $events = [];

    /**
     * @return array<string,array{0:Primary,1:Cleanup,2:int,3:string,4:bool}>
     */
    public static function failureCases(): array
    {
        $cancellation = 'The call to MCP server "srv" was cancelled.';
        $deadline = 'The 20-second operation budget for MCP server "srv" ran out before this request was sent, ' . 'so the server was not asked. This is the budget this installation sets, not a server that did not answer.';
        $metadata = 'Delegated authentication for MCP server "srv" was refused (exchange_failed).';
        return [
            'cancelled / healthy cleanup' => ['cancelled', 'healthy', 1799990218, $cancellation, true],
            'cancelled / cleanup RuntimeException' => ['cancelled', 'runtime', 1799990218, $cancellation, true],
            'cancelled / cleanup Error' => ['cancelled', 'error', 1799990218, $cancellation, true],
            'cancelled / cleanup RequestCancelledException' => ['cancelled', 'cancelled', 1799990218, $cancellation, true],
            'deadline / healthy cleanup' => ['deadline', 'healthy', 1799990217, $deadline, false],
            'deadline / cleanup RuntimeException' => ['deadline', 'runtime', 1799990217, $deadline, false],
            'deadline / cleanup Error' => ['deadline', 'error', 1799990217, $deadline, false],
            'deadline / cleanup RequestCancelledException' => ['deadline', 'cancelled', 1799990217, $deadline, false],
            'metadata RuntimeException / healthy cleanup' => ['metadata_runtime', 'healthy', 1799990219, $metadata, false],
            'metadata RuntimeException / cleanup RuntimeException' => ['metadata_runtime', 'runtime', 1799990219, $metadata, false],
            'metadata RuntimeException / cleanup Error' => ['metadata_runtime', 'error', 1799990219, $metadata, false],
            'metadata RuntimeException / cleanup RequestCancelledException' => ['metadata_runtime', 'cancelled', 1799990219, $metadata, false],
            'metadata Error / healthy cleanup' => ['metadata_error', 'healthy', 1799990219, $metadata, false],
            'metadata Error / cleanup RuntimeException' => ['metadata_error', 'runtime', 1799990219, $metadata, false],
            'metadata Error / cleanup Error' => ['metadata_error', 'error', 1799990219, $metadata, false],
            'metadata Error / cleanup RequestCancelledException' => ['metadata_error', 'cancelled', 1799990219, $metadata, false],
            'metadata RequestCancelledException / healthy cleanup' => ['metadata_cancelled', 'healthy', 1799990218, $cancellation, true],
            'metadata RequestCancelledException / cleanup RuntimeException' => ['metadata_cancelled', 'runtime', 1799990218, $cancellation, true],
            'metadata RequestCancelledException / cleanup Error' => ['metadata_cancelled', 'error', 1799990218, $cancellation, true],
            'metadata RequestCancelledException / cleanup RequestCancelledException' => ['metadata_cancelled', 'cancelled', 1799990218, $cancellation, true],
        ];
    }

    /**
     * @param Primary $primary
     * @param Cleanup $cleanup
     */
    #[Test]
    #[DataProvider('failureCases')]
    public function rejectionCleanupPreservesTheEstablishedPublicOutcome(
        string $primary,
        string $cleanup,
        int $expectedCode,
        string $expectedMessage,
        bool $expectedCancellation,
    ): void {
        $result = $this->exerciseExchange($primary, $cleanup);
        $allocation = $this->assertRecordedExchange($result['before'], $result['after']);

        self::assertNull($result['credential']);
        self::assertSame(['request', 'store', 'metadata', 'delete'], $this->events);
        self::assertSame(
            [
                [
                    'identifier' => $allocation,
                    'reason' => str_starts_with($primary, 'metadata_') ? 'Discard invalid MCP delegated credential binding' : 'Discard unusable MCP delegated credential',
                ],
            ],
            $this->deletes,
        );
        $exception = $result['exception'];
        self::assertInstanceOf(McpTransportException::class, $exception);
        self::assertNull($exception->getPrevious());
        self::assertStringNotContainsString('private-', $exception->getMessage());
        self::assertStringNotContainsString(self::ISSUED_VALUE, $exception->getMessage());
        self::assertSame($expectedCode, $exception->getCode());
        self::assertSame($expectedMessage, $exception->getMessage());
        self::assertSame($expectedCancellation, $exception->isCancellation());
    }

    #[Test]
    public function aSuccessfulExchangeReturnsItsFreshCredentialWithoutDeletingIt(): void
    {
        // A delete would throw, so success cannot conceal premature cleanup.
        $result = $this->exerciseExchange('healthy', 'error');
        $allocation = $this->assertRecordedExchange($result['before'], $result['after']);

        self::assertNull($result['exception']);
        self::assertSame(['request', 'store', 'metadata'], $this->events);
        self::assertSame([], $this->deletes);
        $credential = $result['credential'];
        self::assertInstanceOf(McpIssuedCredential::class, $credential);
        self::assertSame($allocation, $credential->identifier);
        self::assertSame(
            $result['startedNanoseconds'] + 60000000000,
            $credential->expiresAtNanoseconds,
        );
    }

    /**
     * @param Primary $primary
     * @param Cleanup $cleanup
     *
     * @return ExerciseResult
     */
    private function exerciseExchange(string $primary, string $cleanup): array
    {
        $clock = new FakeMcpClock();
        $signal = new class implements CancellationSignalInterface {
            public bool $cancelled = false;

            public function isCancelled(): bool
            {
                return $this->cancelled;
            }
        };
        $captured = $this;
        $http = self::createStub(VaultHttpClientInterface::class);
        $http
            ->method('withTimeout')
            ->willReturnCallback(
                static function (int $seconds) use ($http, $captured): VaultHttpClientInterface {
                    $captured->timeouts[] = $seconds;
                    return $http;
                },
            );
        $http
            ->method('withReason')
            ->willReturnCallback(
                static function (string $reason) use ($http, $captured): VaultHttpClientInterface {
                    $captured->reasons[] = $reason;
                    return $http;
                },
            );
        $http
            ->method('withAuthentication')
            ->willReturnCallback(
                /** @param array{bodyField:string} $options */
                static function (
                    string $identifier,
                    SecretPlacement $placement,
                    array $options,
                ) use ($http, $captured): VaultHttpClientInterface {
                    $captured->authentication[] = [$identifier, $placement, $options];
                    return $http;
                },
            );
        $http
            ->method('sendRequest')
            ->willReturnCallback(
                static function (RequestInterface $request) use ($captured): ResponseInterface {
                    $captured->requests[] = $request;
                    $captured->events[] = 'request';
                    return new Response(
                        200,
                        ['Content-Type' => 'application/json'],
                        json_encode(
                            [
                                'access_token' => self::ISSUED_VALUE,
                                'token_type' => 'Bearer',
                                'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                                'expires_in' => 60,
                            ],
                            JSON_THROW_ON_ERROR,
                        ),
                    );
                },
            );
        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('http')->willReturn($http);
        $vault
            ->method('store')
            ->willReturnCallback(
                /** @param StoreOptions $options */
                static function (
                    string $identifier,
                    string $value,
                    array $options,
                ) use ($captured, $primary, $signal, $clock): void {
                    $captured->stores[] = ['identifier' => $identifier, 'value' => $value, 'options' => $options];
                    $captured->events[] = 'store';
                    if ($primary === 'cancelled') {
                        $signal->cancelled = true;
                    }

                    if ($primary === 'deadline') {
                        $clock->advanceSeconds(20);
                    }
                },
            );
        $vault
            ->method('getMetadata')
            ->willReturnCallback(
                static function (string $identifier) use ($captured, $primary): SecretDetails {
                    $captured->metadata[] = $identifier;
                    $captured->events[] = 'metadata';
                    if ($primary === 'metadata_runtime') {
                        throw new RuntimeException('private-metadata-runtime', 10700011);
                    }

                    if ($primary === 'metadata_error') {
                        throw new Error('private-metadata-error', 10700012);
                    }

                    if ($primary === 'metadata_cancelled') {
                        throw new RequestCancelledException('private-metadata-cancellation', 10700016);
                    }

                    return SecretDetails::fromSecret(new Secret(identifier: $identifier, ownerUid: 7));
                },
            );
        $vault
            ->method('delete')
            ->willReturnCallback(
                static function (
                    string $identifier,
                    string $reason,
                ) use ($captured, $cleanup): void {
                    $captured->deletes[] = ['identifier' => $identifier, 'reason' => $reason];
                    $captured->events[] = 'delete';
                    if ($cleanup === 'runtime') {
                        throw new RuntimeException('private-cleanup-runtime', 10700013);
                    }

                    if ($cleanup === 'error') {
                        throw new Error('private-cleanup-error', 10700014);
                    }

                    if ($cleanup === 'cancelled') {
                        throw new RequestCancelledException('private-cleanup-cancellation', 10700015);
                    }
                },
            );
        $server = new McpServerRecord(
            uid: 1,
            pid: 0,
            identifier: 'srv',
            name: 'Test server',
            description: '',
            url: 'https://mcp.example.com/rpc',
            authCredential: '',
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
        );
        $profile = new McpDelegationProfile(
            'office',
            'https://idp.example.com/token',
            'public-client',
            null,
            ['urn:one'],
            ['read'],
        );
        $subject = new McpSubjectCredential(self::SUBJECT, ['urn:one'], ['read']);
        $deadline = McpOperationDeadline::start($clock, 20);
        $exchange = new McpTokenExchange($vault, $this->createPublicDnsHttpClientFactory(), $clock);
        $startedNanoseconds = $clock->monotonicNanoseconds();
        $credential = null;
        $exception = null;
        $before = time();
        try {
            $credential = $exchange->exchange(
                $server,
                AiActorContext::backendUser(7),
                $profile,
                $subject,
                ['read'],
                $deadline,
                $signal,
            );
        } catch (Throwable $caught) {
            $exception = $caught;
        }

        return [
            'credential' => $credential,
            'exception' => $exception,
            'before' => $before,
            'after' => time(),
            'startedNanoseconds' => $startedNanoseconds,
        ];
    }

    private function assertRecordedExchange(int $before, int $after): string
    {
        self::assertCount(1, $this->stores);
        $store = $this->stores[0];
        $allocation = $store['identifier'];
        self::assertTrue(Uuid::isValid($allocation));
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($allocation));
        self::assertNotSame(self::SUBJECT, $allocation);
        self::assertSame(self::ISSUED_VALUE, $store['value']);
        $options = $store['options'];
        self::assertSame(
            ['owner', 'groups', 'context', 'expiresAt', 'metadata'],
            array_keys($options),
        );
        self::assertSame(7, $options['owner']);
        self::assertSame([], $options['groups']);
        self::assertSame('mcp_delegation', $options['context']);
        self::assertIsInt($options['expiresAt']);
        self::assertGreaterThanOrEqual($before + 60, $options['expiresAt']);
        self::assertLessThanOrEqual($after + 60, $options['expiresAt']);
        self::assertSame(
            [
                'server' => 'srv',
                'profile' => 'office',
                'audience' => 'urn:one',
                'scopes' => ['read'],
                'actorUid' => 7,
                'serviceAccount' => null,
            ],
            $options['metadata'],
        );
        self::assertSame([$allocation], $this->metadata);
        self::assertSame(
            [[self::SUBJECT, SecretPlacement::BodyField, ['bodyField' => 'subject_token']]],
            $this->authentication,
        );
        self::assertSame([20], $this->timeouts);
        self::assertSame(['MCP delegated token exchange'], $this->reasons);
        self::assertCount(1, $this->requests);
        $request = $this->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://idp.example.com/token', (string)$request->getUri());
        self::assertSame(
            'application/x-www-form-urlencoded',
            $request->getHeaderLine('Content-Type'),
        );
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        parse_str((string)$request->getBody(), $form);
        self::assertSame(
            [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'requested_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'client_id' => 'public-client',
                'audience' => 'urn:one',
                'scope' => 'read',
            ],
            $form,
        );
        return $allocation;
    }
}

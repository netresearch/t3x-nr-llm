<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\Auth;

use Error;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpDelegationProfile;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpSubjectCredential;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpTokenExchange;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Domain\Dto\SecretDetails;
use Netresearch\NrVault\Exception\RequestCancelledException;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Http\DnsResolverInterface;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

#[CoversClass(McpTokenExchange::class)]
final class McpCredentialCompensationTest extends AbstractUnitTestCase
{
    #[Test]
    #[DataProvider('failedStorageCases')]
    public function cleanupSelectsOnlyTheLocallyAllocatedActorAndPurposeBinding(
        string $binding,
        string $storeFault,
        string $deleteFault,
        bool $serviceActor,
        bool $expectedDelete,
    ): void {
        $actor  = $serviceActor ? AiActorContext::serviceAccount('worker') : AiActorContext::backendUser(7);
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
            importStatus: '',
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
            'client',
            null,
            ['urn:one'],
            ['read'],
        );
        $expectedMetadata = [
            'server'         => 'srv',
            'profile'        => 'office',
            'audience'       => 'urn:one',
            'scopes'         => ['read'],
            'actorUid'       => $actor->backendUserUid,
            'serviceAccount' => $actor->serviceAccount,
        ];
        $primary = match ($storeFault) {
            'error'     => new Error('private failed store detail', 1799990233),
            'cancelled' => new RequestCancelledException('fixed cancellation', 1799990239),
            default     => new RuntimeException('private failed store detail', 1799990234),
        };
        $secondary = match ($deleteFault) {
            'error'     => new Error('private failed cleanup detail', 1799990235),
            'runtime'   => new RuntimeException('private failed cleanup detail', 1799990236),
            'cancelled' => new RequestCancelledException(
                'fixed cleanup cancellation',
                1799990240,
            ),
            default => null,
        };
        /** @var list<array{identifier:string,value:string,options:array<string,mixed>}> $stored */
        $stored = [];
        /** @var list<string> $metadataReads */
        $metadataReads = [];
        /** @var list<array{identifier:string,reason:string}> $deletions */
        $deletions = [];
        $vault     = $this->createMock(VaultServiceInterface::class);
        $vault->expects(self::never())->method('retrieve');
        $vault
            ->method('store')
            ->willReturnCallback(
                static function (
                    string $identifier,
                    string $value,
                    array $options,
                ) use (&$stored, $primary): never {
                    $stored[] = [
                        'identifier' => $identifier,
                        'value'      => $value,
                        'options'    => $options,
                    ];
                    throw $primary;
                },
            );
        $vault
            ->method('getMetadata')
            ->willReturnCallback(
                static function (
                    string $identifier,
                ) use ($binding, $actor, $expectedMetadata, &$metadataReads): SecretDetails {
                    $metadataReads[] = $identifier;
                    if ($binding === 'metadata-runtime') {
                        throw new RuntimeException(
                            'private metadata failure',
                            1799990237,
                        );
                    }

                    if ($binding === 'metadata-error') {
                        throw new Error('private metadata failure', 1799990238);
                    }

                    if ($binding === 'missing-row') {
                        throw SecretNotFoundException::forIdentifier($identifier);
                    }

                    $metadata = $expectedMetadata;
                    if (str_starts_with($binding, 'missing-')) {
                        unset($metadata[substr($binding, 8)]);
                    } elseif (str_starts_with($binding, 'wrong-')) {
                        $metadata[substr($binding, 6)] = 'unrelated-value';
                    }

                    return new SecretDetails(
                        uid: 42,
                        identifier: $binding === 'identifier' ? 'operator_owned_alias' : $identifier,
                        description: '',
                        ownerUid: $binding === 'owner' ? 88 : $actor->backendUserUid,
                        groups: $binding === 'groups' ? [9] : [],
                        context: $binding === 'context' ? 'operator_owned' : 'mcp_delegation',
                        frontendAccessible: $binding === 'frontend',
                        version: 1,
                        createdAt: 1,
                        updatedAt: 1,
                        expiresAt: time() + 60,
                        lastRotatedAt: null,
                        readCount: 0,
                        lastReadAt: null,
                        metadata: $metadata,
                        scopePid: 0,
                    );
                },
            );
        $vault
            ->method('delete')
            ->willReturnCallback(
                static function (
                    string $identifier,
                    string $reason,
                ) use (&$deletions, $secondary): void {
                    $deletions[] = ['identifier' => $identifier, 'reason' => $reason];
                    if ($secondary instanceof Throwable) {
                        throw $secondary;
                    }
                },
            );
        $http = self::createStub(VaultHttpClientInterface::class);
        $http->method('withTimeout')->willReturnSelf();
        $http->method('withReason')->willReturnSelf();
        $http->method('withAuthentication')->willReturnSelf();
        $http
            ->method('sendRequest')
            ->willReturn(
                new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    '{"access_token":"synthetic-issued-token","token_type":"Bearer","issued_token_type":"urn:ietf:params:oauth:token-type:access_token","expires_in":60}',
                ),
            );
        $vault->method('http')->willReturn($http);
        $dns = self::createStub(DnsResolverInterface::class);
        $dns->method('resolve')->willReturn([['ip' => '93.184.216.34']]);
        $clock    = new FakeMcpClock();
        $exchange = new McpTokenExchange($vault, new SecureHttpClientFactory($dns), $clock);
        $caught   = null;
        $issued   = null;
        try {
            $issued = $exchange->exchange(
                $server,
                $actor,
                $profile,
                new McpSubjectCredential(
                    '11111111-1111-7111-8111-111111111111',
                    ['urn:one'],
                    ['read'],
                ),
                ['read'],
                McpOperationDeadline::start($clock, 20),
                null,
            );
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertNull($issued);
        self::assertInstanceOf(McpTransportException::class, $caught);
        self::assertSame(
            $storeFault === 'cancelled' ? 1799990218 : 1799990219,
            $caught->getCode(),
        );
        self::assertSame(
            $storeFault === 'cancelled' ? 'The call to MCP server "srv" was cancelled.' : 'Delegated authentication for MCP server "srv" was refused (exchange_failed).',
            $caught->getMessage(),
        );
        self::assertStringNotContainsString('private', $caught->getMessage());
        self::assertCount(1, $stored);
        self::assertSame('7', $stored[0]['identifier'][14]);
        self::assertNotSame(
            '11111111-1111-7111-8111-111111111111',
            $stored[0]['identifier'],
        );
        self::assertSame('synthetic-issued-token', $stored[0]['value']);
        self::assertSame($actor->backendUserUid, $stored[0]['options']['owner']);
        self::assertSame([], $stored[0]['options']['groups']);
        self::assertSame('mcp_delegation', $stored[0]['options']['context']);
        self::assertSame($expectedMetadata, $stored[0]['options']['metadata']);
        self::assertSame([$stored[0]['identifier']], $metadataReads);
        self::assertSame(
            $expectedDelete ? [
                [
                    'identifier' => $stored[0]['identifier'],
                    'reason'     => 'Discard failed MCP delegated credential persistence',
                ],
            ] : [],
            $deletions,
        );
        self::assertSame($storeFault === 'cancelled', $caught->isCancellation());
    }

    /**
     * @return iterable<string,array{string,string,string,bool,bool}>
     */
    public static function failedStorageCases(): iterable
    {
        foreach ([false, true] as $serviceActor) {
            foreach (['runtime', 'error'] as $storeFault) {
                foreach (['healthy', 'runtime', 'error'] as $deleteFault) {
                    yield ($serviceActor ? 'service' : 'backend') . '/' . $storeFault . '/' . $deleteFault => ['matching', $storeFault, $deleteFault, $serviceActor, true];
                }
            }
        }

        foreach ([
            'identifier',
            'owner',
            'groups',
            'frontend',
            'context',
            'metadata-runtime',
            'metadata-error',
            'missing-row',
        ] as $binding) {
            yield $binding => [$binding, 'error', 'healthy', false, false];
        }

        foreach ([
            'server',
            'profile',
            'audience',
            'scopes',
            'actorUid',
            'serviceAccount',
        ] as $key) {
            foreach (['wrong-', 'missing-'] as $prefix) {
                yield $prefix . $key => [$prefix . $key, 'runtime', 'healthy', false, false];
            }
        }

        yield 'cancelled store survives cleanup Error' => ['matching', 'cancelled', 'error', false, true];
        yield 'cancelled store survives metadata Error' => ['metadata-error', 'cancelled', 'healthy', false, false];
        yield 'RuntimeException survives cleanup cancellation' => ['matching', 'runtime', 'cancelled', false, true];
        yield 'Error survives cleanup cancellation' => ['matching', 'error', 'cancelled', false, true];
    }
}

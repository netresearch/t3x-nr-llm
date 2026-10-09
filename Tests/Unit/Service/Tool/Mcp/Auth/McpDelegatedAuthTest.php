<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\Auth;

use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\ConfiguredMcpSubjectResolver;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpAuthenticationSessionFactory;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpCredentialSessionInterface;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpDelegatedCredentialSession;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpTokenExchange;
use Netresearch\NrLlm\Service\Tool\Mcp\Exception\McpTransportException;
use Netresearch\NrLlm\Service\Tool\Mcp\McpOperationDeadline;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\FakeMcpClock;
use Netresearch\NrLlm\Tests\Fixtures\Mcp\McpTestServer;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Domain\Dto\SecretDetails;
use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Exception\RequestCancelledException;
use Netresearch\NrVault\Http\CancellationSignalInterface;
use Netresearch\NrVault\Http\DnsResolverInterface;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Http\VaultHttpClient;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * @phpstan-type IdentityGrant array{enabled:bool,credentialIdentifier:string,allowedAudiences:list<string>,allowedScopes:list<string>}
 * @phpstan-type ProfileDefinition array{tokenEndpoint:string,clientId:string,clientSecretIdentifier?:string,allowedAudiences:list<string>,allowedScopes:list<string>,backendUsers:array<int,IdentityGrant>,serviceAccounts:array<string,IdentityGrant>}
 */
#[CoversClass(McpAuthenticationSessionFactory::class)]
#[CoversClass(ConfiguredMcpSubjectResolver::class)]
#[CoversClass(McpTokenExchange::class)]
#[CoversClass(McpDelegatedCredentialSession::class)]
final class McpDelegatedAuthTest extends AbstractUnitTestCase
{
    private const SUBJECT_ONE = '11111111-1111-4111-8111-111111111111';

    private const SUBJECT_TWO = '22222222-2222-4222-8222-222222222222';

    private const CLIENT_SECRET = '33333333-3333-4333-8333-333333333333';

    /** @var array<string,ProfileDefinition> */
    private array $profiles = [];

    /** @var list<array{identifier:string,value:string,options:array<array-key,mixed>}> */
    private array $stored = [];

    /** @var list<string> */
    private array $deleted = [];

    /** @var list<string> */
    private array $reads = [];

    /** @var list<RequestInterface> */
    private array $posted = [];

    private FakeMcpClock $clock;

    /**
     * @param array<string,string> $overrides
     */
    private function server(array $overrides = []): McpServerRecord
    {
        $s = McpTestServer::server();
        return new McpServerRecord(
            uid: $s->uid,
            pid: $s->pid,
            identifier: $overrides['identifier'] ?? $s->identifier,
            name: $s->name,
            description: $s->description,
            url: $overrides['url'] ?? $s->url,
            authCredential: self::CLIENT_SECRET,
            authPlacement: 'bearer',
            authHeaderName: '',
            dataClass: $s->dataClass,
            requiresApproval: $s->requiresApproval,
            enabled: $s->enabled,
            importStatus: $s->importStatus,
            importError: $s->importError,
            lastImported: $s->lastImported,
            toolCount: $s->toolCount,
            lastContact: $s->lastContact,
            lastLatencyMs: $s->lastLatencyMs,
            tstamp: $s->tstamp,
            crdate: $s->crdate,
            authMode: $overrides['authMode'] ?? 'delegated',
            delegationProfile: $overrides['delegationProfile'] ?? 'office',
            delegationAudience: $overrides['delegationAudience'] ?? 'urn:one',
            delegationScopes: $overrides['delegationScopes'] ?? 'read',
            discoveryCredential: $overrides['discoveryCredential'] ?? '',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function response(string $token = 'issued-token', int $lifetime = 60): array
    {
        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'expires_in' => $lifetime,
        ];
    }

    /**
     * @return ProfileDefinition
     */
    private function profile(): array
    {
        $grant = [
            'enabled' => true,
            'credentialIdentifier' => self::SUBJECT_ONE,
            'allowedAudiences' => ['urn:one', 'urn:two'],
            'allowedScopes' => ['read', 'write'],
        ];
        return [
            'tokenEndpoint' => 'https://idp.example.com/token',
            'clientId' => 'public-client',
            'allowedAudiences' => ['urn:one', 'urn:two'],
            'allowedScopes' => ['read', 'write'],
            'backendUsers' => [7 => $grant, 8 => array_replace($grant, ['credentialIdentifier' => self::SUBJECT_TWO])],
            'serviceAccounts' => ['worker' => array_replace($grant, ['credentialIdentifier' => self::SUBJECT_TWO])],
        ];
    }

    /**
     * @param list<ResponseInterface> $responses
     * @param (callable():void)|null  $onSend
     * @param (callable():void)|null  $onStore
     * @param (callable():void)|null  $onDelete
     */
    private function kernel(
        array $responses,
        ?VaultHttpClientInterface $http = null,
        ?callable $onSend = null,
        bool $hostAllowed = true,
        ?callable $onStore = null,
        ?callable $onDelete = null,
    ): McpAuthenticationSessionFactory {
        $this->clock = new FakeMcpClock();
        $this->profiles = ['office' => $this->profile()];
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(fn(): array => $this->profiles);
        $dns = self::createStub(DnsResolverInterface::class);
        $dns->method('resolve')->willReturn([['ip' => $hostAllowed ? '93.184.215.14' : '127.0.0.1']]);
        $factory = new SecureHttpClientFactory($dns);
        $vault = $this->vaultFor($onStore, $onDelete);
        $http ??= $this->recordingClient($vault, $factory, $responses, $onSend);
        $vault->method('http')->willReturn($http);
        return new McpAuthenticationSessionFactory(
            $configuration,
            new ConfiguredMcpSubjectResolver($configuration),
            $vault,
            $factory,
            $this->clock,
        );
    }

    private function deadline(int $seconds = 20): McpOperationDeadline
    {
        return McpOperationDeadline::start($this->clock, $seconds);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function jsonResponse(array $payload, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function requireSession(?McpCredentialSessionInterface $session): McpCredentialSessionInterface
    {
        self::assertNotNull($session);
        return $session;
    }

    #[Test]
    public function oldRecordsReturnNoSessionAndUnknownModesRefuse(): void
    {
        $factory = $this->kernel([]);
        self::assertNull($factory->openForExecution($this->server(['authMode' => 'legacy']), null, $this->deadline()));
        self::assertNull($factory->openForDiscovery($this->server(['authMode' => 'legacy']), $this->deadline()));
        $this->expectException(McpTransportException::class);
        $factory->openForExecution(
            $this->server(['authMode' => 'typo']),
            AiActorContext::backendUser(7),
            $this->deadline(),
        );
    }

    #[Test]
    public function onlyTheInitiatingMappedActorCanAcquireSubjectAuthority(): void
    {
        foreach ([
            null,
            AiActorContext::anonymous(),
            AiActorContext::backendUser(1, true),
            AiActorContext::serviceAccount('unknown'),
        ] as $actor) {
            $factory = $this->kernel([]);
            try {
                $session = $factory->openForExecution($this->server(), $actor, $this->deadline());
                self::fail('Accepted ' . get_debug_type($session));
            } catch (McpTransportException $exception) {
                self::assertStringNotContainsString('subject-one', $exception->getMessage());
            }
        }

        self::assertSame([], $this->reads);
        self::assertSame([], $this->posted);
    }

    #[Test]
    public function publicExchangeInjectsOnlyTheMappedSubjectAndStoresAnExpiringUuid(): void
    {
        $factory = $this->kernel([$this->jsonResponse($this->response('new-access', 600))]);
        $before = time();
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
        );
        $reference = $session->credentialIdentifier();
        self::assertSame('7', Uuid::fromString($reference)->toRfc4122()[14]);
        parse_str((string)$this->posted[0]->getBody(), $fields);
        self::assertSame('subject-one', $fields['subject_token']);
        self::assertSame('urn:one', $fields['audience']);
        self::assertSame('read', $fields['scope']);
        self::assertSame('urn:ietf:params:oauth:grant-type:token-exchange', $fields['grant_type']);
        self::assertSame('urn:ietf:params:oauth:token-type:access_token', $fields['subject_token_type']);
        self::assertSame($fields['subject_token_type'], $fields['requested_token_type']);
        self::assertArrayNotHasKey('client_secret', $fields);
        self::assertSame('public-client', $fields['client_id']);
        self::assertSame('new-access', $this->stored[0]['value']);
        $expiry = $this->stored[0]['options']['expiresAt'];
        self::assertIsInt($expiry);
        self::assertGreaterThanOrEqual($before + 299, $expiry);
        self::assertLessThanOrEqual(time() + 300, $expiry);
        self::assertSame([self::SUBJECT_ONE], $this->reads);
        self::assertSame($reference, $session->credentialIdentifier());
        self::assertCount(1, $this->posted);
        $session->close();
        $session->close();
        self::assertSame([$reference], $this->deleted);
    }

    #[Test]
    public function explicitServiceAccountMappingsWorkWithoutAmbientBackendIdentity(): void
    {
        $factory = $this->kernel([$this->jsonResponse($this->response())]);
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::serviceAccount('worker'), $this->deadline()),
        );
        $session->credentialIdentifier();
        self::assertSame([self::SUBJECT_TWO], $this->reads);
        $metadata = $this->stored[0]['options']['metadata'];
        self::assertIsArray($metadata);
        self::assertSame('worker', $metadata['serviceAccount']);
        $session->close();
    }

    #[Test]
    public function twoActorsAndTwoAudiencesNeverShareAnExchangedReference(): void
    {
        $factory = $this->kernel(
            array_map(fn(int $index): ResponseInterface => $this->jsonResponse($this->response()), range(1, 4)),
        );
        $references = [];
        foreach ([7, 8] as $uid) {
            foreach (['urn:one', 'urn:two'] as $audience) {
                $session = $this->requireSession(
                    $factory->openForExecution(
                        $this->server(['identifier' => $audience, 'delegationAudience' => $audience]),
                        AiActorContext::backendUser($uid),
                        $this->deadline(),
                    ),
                );
                $references[] = $session->credentialIdentifier();
                $session->close();
            }
        }

        self::assertCount(4, array_unique($references));
        self::assertSame([self::SUBJECT_ONE, self::SUBJECT_ONE, self::SUBJECT_TWO, self::SUBJECT_TWO], $this->reads);
        self::assertSame($references, $this->deleted);
    }

    #[Test]
    public function expiryRenewsAgainstTheSameActorAndDeletesBothReferences(): void
    {
        $factory = $this->kernel(
            [$this->jsonResponse($this->response('first', 2)), $this->jsonResponse($this->response('second', 30))],
        );
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
        );
        $first = $session->credentialIdentifier();
        $this->clock->advanceSeconds(3);
        $second = $session->credentialIdentifier();
        self::assertNotSame($first, $second);
        self::assertSame([$first], $this->deleted);
        self::assertSame([self::SUBJECT_ONE, self::SUBJECT_ONE], $this->reads);
        $session->close();
        self::assertSame([$first, $second], $this->deleted);
    }

    #[Test]
    public function revokedMappingDeniesRenewalAndLeavesCleanupToFinally(): void
    {
        $factory = $this->kernel([$this->jsonResponse($this->response('first', 2))]);
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
        );
        $reference = $session->credentialIdentifier();
        $this->profiles['office']['backendUsers'][7]['enabled'] = false;
        $this->clock->advanceSeconds(3);
        try {
            $session->credentialIdentifier();
            self::fail('Renewed a revoked identity.');
        } catch (McpTransportException $exception) {
            self::assertStringNotContainsString('subject-one', $exception->getMessage());
        } finally {
            $session->close();
        }

        self::assertCount(1, $this->posted);
        self::assertSame([$reference], $this->deleted);
    }

    #[Test]
    public function profileAndActorGrantsBothConstrainRequestedScopesAndAudience(): void
    {
        foreach ([
            ['delegationScopes' => 'admin'],
            ['delegationAudience' => 'urn:unknown'],
            ['delegationScopes' => ''],
            ['delegationScopes' => 'read  write'],
        ] as $overrides) {
            $factory = $this->kernel([]);
            try {
                $session = $factory->openForExecution($this->server($overrides), AiActorContext::backendUser(7), $this->deadline());
                self::fail('Accepted ' . get_debug_type($session));
            } catch (McpTransportException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }

        $factory = $this->kernel([]);
        $this->profiles['office']['backendUsers'][7]['allowedScopes'] = ['read'];
        $this->expectException(McpTransportException::class);
        $factory->openForExecution(
            $this->server(['delegationScopes' => 'write']),
            AiActorContext::backendUser(7),
            $this->deadline(),
        );
    }

    #[Test]
    public function discoveryBorrowsOnlyItsExplicitCredentialAndNeverDeletesIt(): void
    {
        $factory = $this->kernel([]);
        $session = $this->requireSession(
            $factory->openForDiscovery($this->server(['discoveryCredential' => self::SUBJECT_TWO]), $this->deadline()),
        );
        self::assertSame(self::SUBJECT_TWO, $session->credentialIdentifier());
        $session->close();
        self::assertSame([], $this->deleted);
        self::assertSame([], $this->reads);
        $this->expectException(McpTransportException::class);
        $factory->openForDiscovery($this->server(), $this->deadline());
    }

    #[Test]
    public function hostDenialCancellationAndExhaustedDeadlinePrecedeCredentialReads(): void
    {
        $factory = $this->kernel([], hostAllowed: false);
        try {
            $session = $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline());
            self::fail('Accepted ' . get_debug_type($session));
        } catch (McpTransportException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        self::assertSame([], $this->reads);
        self::assertSame([], $this->posted);
        $factory = $this->kernel([]);
        $signal = self::createStub(CancellationSignalInterface::class);
        $signal->method('isCancelled')->willReturn(true);
        try {
            $session = $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline(), $signal);
            self::fail('Accepted ' . get_debug_type($session));
        } catch (McpTransportException $exception) {
            self::assertTrue($exception->isCancellation());
        }

        $deadline = $this->deadline();
        $this->clock->advanceSeconds(21);
        $this->expectException(McpTransportException::class);
        $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $deadline);
    }

    #[Test]
    public function anExchangeThatSpendsItsDeadlineStoresNoCredential(): void
    {
        $factory = $this->kernel(
            [$this->jsonResponse($this->response())],
            onSend: function (): void {
                $this->clock->advanceSeconds(21);
            },
        );
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
        );
        try {
            $session->credentialIdentifier();
            self::fail('Stored an out-of-budget token.');
        } catch (McpTransportException $exception) {
            self::assertStringContainsString('budget', $exception->getMessage());
        } finally {
            $session->close();
        }

        self::assertSame([], $this->stored);
        self::assertSame([], $this->deleted);
    }

    #[Test]
    public function invalidResponsesCannotBroadenAuthorityOrLeakRemoteText(): void
    {
        $base = $this->response();
        $bad = [
            array_replace($base, ['token_type' => 'MAC']),
            array_replace($base, ['issued_token_type' => 'urn:unknown']),
            array_replace($base, ['access_token' => "remote-secret\nvalue"]),
            array_replace($base, ['expires_in' => 0]),
            array_replace($base, ['expires_in' => '60']),
            array_replace($base, ['scope' => 'read admin']),
            array_replace($base, ['audience' => 'urn:two']),
        ];
        foreach ($bad as $payload) {
            $factory = $this->kernel([$this->jsonResponse($payload)]);
            $session = $this->requireSession(
                $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
            );
            try {
                $session->credentialIdentifier();
                self::fail('Accepted invalid authority.');
            } catch (McpTransportException $exception) {
                self::assertStringNotContainsString('remote-secret', $exception->getMessage());
                self::assertStringNotContainsString('subject-one', $exception->getMessage());
            } finally {
                $session->close();
            }
        }

        self::assertSame([], $this->stored);
    }

    #[Test]
    public function statusMalformedAndOversizedBodiesAreRefusedWithoutTokenStorage(): void
    {
        foreach ([
            new Response(401, ['Content-Type' => 'application/json'], '{"error":"remote-secret"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{bad'),
            new Response(200, ['Content-Type' => 'application/json'], str_repeat('x', 65537)),
            new Response(302, ['Location' => 'https://other.example.com']),
        ] as $response) {
            $factory = $this->kernel([$response]);
            $session = $this->requireSession(
                $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
            );
            try {
                $session->credentialIdentifier();
                self::fail('Accepted invalid response.');
            } catch (McpTransportException $exception) {
                self::assertStringNotContainsString('remote-secret', $exception->getMessage());
            } finally {
                $session->close();
            }
        }

        self::assertSame([], $this->stored);
    }

    #[Test]
    public function absentAdditionalCapabilityDeniesAConfidentialExchange(): void
    {
        $http = self::createStub(VaultHttpClientInterface::class);
        $http->method('withTimeout')->willReturnSelf();
        $http->method('withReason')->willReturnSelf();
        $factory = $this->kernel([], http: $http);
        $this->profiles['office']['clientSecretIdentifier'] = self::CLIENT_SECRET;
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
        );
        try {
            $session->credentialIdentifier();
            self::fail('An unsupported Vault client was used.');
        } catch (McpTransportException $exception) {
            self::assertStringNotContainsString('confidential-secret', $exception->getMessage());
        } finally {
            $session->close();
        }

        self::assertSame([], $this->stored);
        self::assertSame([], $this->reads);
        self::assertSame([], $this->posted);
    }

    #[Test]
    public function confidentialExchangeUsesTheAdditionalVaultCapabilityForBothFields(): void
    {
        if (!interface_exists($this->capabilityName())) {
            self::markTestSkipped('Requires optional nr-vault additional body credential capability.');
        }

        $factory = $this->kernel([$this->jsonResponse($this->response())]);
        $this->profiles['office']['clientSecretIdentifier'] = self::CLIENT_SECRET;
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
        );
        $session->credentialIdentifier();
        parse_str((string)$this->posted[0]->getBody(), $fields);
        self::assertSame('confidential-secret', $fields['client_secret']);
        self::assertSame('subject-one', $fields['subject_token']);
        self::assertSame([self::CLIENT_SECRET, self::SUBJECT_ONE], $this->reads);
        $session->close();
    }

    #[Test]
    public function vaultStoreRefusalCannotExposeTheExchangedTokenOrSwapActors(): void
    {
        $factory = $this->kernel(
            [$this->jsonResponse($this->response('issued-private'))],
            onStore: static function (): never {
                throw new RuntimeException('issued-private is denied for owner', 1910581994);
            },
        );
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
        );
        try {
            $session->credentialIdentifier();
            self::fail('Accepted an unstoreable token.');
        } catch (McpTransportException $exception) {
            self::assertStringNotContainsString('issued-private', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        } finally {
            $session->close();
        }

        self::assertSame([self::SUBJECT_ONE], $this->reads);
        self::assertSame([], $this->stored);
        self::assertSame([], $this->deleted);
    }

    #[Test]
    public function cleanupRefusalIsSanitizedAndMayBeRetriedWithoutReopening(): void
    {
        $attempts = 0;
        $factory = $this->kernel(
            [$this->jsonResponse($this->response())],
            onDelete: static function () use (&$attempts): void {
                if (++$attempts === 1) {
                    throw new RuntimeException('issued-token cannot be deleted', 3458175003);
                }
            },
        );
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
        );
        $reference = $session->credentialIdentifier();
        try {
            $session->close();
            self::fail('Ignored failed cleanup.');
        } catch (McpTransportException $exception) {
            self::assertStringNotContainsString('issued-token', $exception->getMessage());
            self::assertStringContainsString('cleanup_failed', $exception->getMessage());
        }

        $session->close();
        self::assertSame([$reference], $this->deleted);
        self::assertSame(2, $attempts);
        $this->expectException(McpTransportException::class);
        $session->credentialIdentifier();
    }

    #[Test]
    public function cancellationWhileTheExchangeReturnsPreventsTemporaryStorage(): void
    {
        $signal = new class implements CancellationSignalInterface {
            public bool $cancelled = false;

            public function isCancelled(): bool
            {
                return $this->cancelled;
            }
        };
        $factory = $this->kernel(
            [$this->jsonResponse($this->response())],
            onSend: static function () use ($signal): void {
                $signal->cancelled = true;
            },
        );
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline(), $signal),
        );
        try {
            $session->credentialIdentifier();
            self::fail('Stored a cancelled exchange.');
        } catch (McpTransportException $exception) {
            self::assertTrue($exception->isCancellation());
        } finally {
            $session->close();
        }

        self::assertSame([], $this->stored);
        self::assertSame([], $this->deleted);
    }

    #[Test]
    public function tokenLifetimeAlreadySpentByTheExchangeIsRefused(): void
    {
        $factory = $this->kernel(
            [$this->jsonResponse($this->response('short-lived', 2))],
            onSend: function (): void {
                $this->clock->advanceSeconds(3);
            },
        );
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
        );
        try {
            $session->credentialIdentifier();
            self::fail('Stored an expired token.');
        } catch (McpTransportException $exception) {
            self::assertStringNotContainsString('short-lived', $exception->getMessage());
        } finally {
            $session->close();
        }

        self::assertSame([], $this->stored);
    }

    #[Test]
    public function aReportedNarrowerScopeIsAcceptedAndTheAudienceStaysPinned(): void
    {
        $payload = array_replace($this->response(), ['scope' => 'read']);
        $factory = $this->kernel([$this->jsonResponse($payload)]);
        $session = $this->requireSession(
            $factory->openForExecution(
                $this->server(['delegationScopes' => 'write read']),
                AiActorContext::backendUser(7),
                $this->deadline(),
            ),
        );
        $session->credentialIdentifier();
        parse_str((string)$this->posted[0]->getBody(), $fields);
        self::assertSame('read write', $fields['scope']);
        self::assertSame('urn:one', $fields['audience']);
        $session->close();
    }

    /**
     * @param (callable():void)|null $onStore
     * @param (callable():void)|null $onDelete
     *
     * @return VaultServiceInterface&MockObject
     */
    private function vaultFor(?callable $onStore, ?callable $onDelete): VaultServiceInterface
    {
        $vault = $this->createMock(VaultServiceInterface::class);
        $vault
            ->method('retrieve')
            ->willReturnCallback(
                function (string $id): string {
                    $this->reads[] = $id;
                    return match ($id) {
                        self::SUBJECT_ONE => 'subject-one',
                        self::SUBJECT_TWO => 'subject-two',
                        default => 'confidential-secret',
                    };
                },
            );
        $vault
            ->method('store')
            ->willReturnCallback(
                function (string $identifier, string $value, array $options) use ($onStore): void {
                    if ($onStore !== null) {
                        $onStore();
                    }

                    $this->stored[] = ['identifier' => $identifier, 'value' => $value, 'options' => $options];
                },
            );
        $vault
            ->method('delete')
            ->willReturnCallback(
                function (string $identifier) use ($onDelete): void {
                    if ($onDelete !== null) {
                        $onDelete();
                    }

                    $this->deleted[] = $identifier;
                },
            );
        $vault
            ->method('getMetadata')
            ->willReturnCallback(
                function (string $identifier): SecretDetails {
                    foreach ($this->stored as $entry) {
                        if ($entry['identifier'] === $identifier) {
                            $owner = $entry['options']['owner'];
                            self::assertIsInt($owner);
                            return SecretDetails::fromSecret(
                                new Secret(
                                    identifier: $identifier,
                                    ownerUid: $owner,
                                ),
                            );
                        }
                    }

                    throw new RuntimeException(
                        'Fixture metadata is unavailable.',
                        1074813472,
                    );
                },
            );
        return $vault;
    }

    /**
     * @param list<ResponseInterface> $responses
     * @param (callable():void)|null  $onSend
     */
    private function recordingClient(
        VaultServiceInterface $vault,
        SecureHttpClientFactory $factory,
        array $responses,
        ?callable $onSend,
    ): VaultHttpClientInterface {
        $transport = $this->createMock(ClientInterface::class);
        $transport
            ->method('sendRequest')
            ->willReturnCallback(
                function (RequestInterface $request) use (&$responses, $onSend): ResponseInterface {
                    $this->posted[] = $request;
                    if ($onSend !== null) {
                        $onSend();
                    }

                    return array_shift($responses) ?? new Response(500);
                },
            );
        $capability = $this->capabilityName();
        $http = interface_exists($capability) ? $this->createMock($capability) : $this->createMock(VaultHttpClientInterface::class);
        self::assertInstanceOf(VaultHttpClientInterface::class, $http);
        $primary = null;
        $additional = [];
        $http->method('withTimeout')->willReturnSelf();
        $http->method('withReason')->willReturnSelf();
        $http
            ->method('withAuthentication')
            ->willReturnCallback(
                static function (
                    string $id,
                    SecretPlacement $placement,
                    array $options = [],
                ) use (&$primary, &$additional, $http): VaultHttpClientInterface {
                    $field = $options['bodyField'] ?? '';
                    if (!is_string($field)) {
                        throw new RuntimeException('Invalid fixture field.', 8889547255);
                    }

                    $primary = ['identifier' => $id, 'placement' => $placement, 'bodyField' => $field];
                    $additional = [];
                    return $http;
                },
            );
        if (method_exists($http, 'withAdditionalBodyField')) {
            $http
                ->method('withAdditionalBodyField')
                ->willReturnCallback(
                    static function (string $id, string $field) use (&$additional, $http): VaultHttpClientInterface {
                        $additional[$field] = $id;
                        return $http;
                    },
                );
        }

        $http
            ->method('sendRequest')
            ->willReturnCallback(
                static function (
                    RequestInterface $request,
                ) use (&$primary, &$additional, $vault, $transport, $factory): ResponseInterface {
                    if ($primary === null) {
                        throw new RuntimeException('Fixture client lacks a binding.', 2852160710);
                    }

                    $client = (new VaultHttpClient(
                        $vault,
                        self::createStub(AuditLogServiceInterface::class),
                        $transport,
                        secureHttpClientFactory: $factory,
                    ))->withAuthentication($primary['identifier'], $primary['placement'], ['bodyField' => $primary['bodyField']]);
                    $client = self::withAdditionalBindings($client, $additional);
                    return $client->sendRequest($request);
                },
            );
        return $http;
    }

    /**
     * @param array<string,string> $additional
     */
    private static function withAdditionalBindings(
        VaultHttpClientInterface $client,
        array $additional,
    ): VaultHttpClientInterface {
        foreach ($additional as $field => $id) {
            if (!method_exists($client, 'withAdditionalBodyField')) {
                throw new RuntimeException('Fixture dependency lacks the capability.', 7243750128);
            }

            $configured = $client->withAdditionalBodyField($id, $field);
            if (!$configured instanceof VaultHttpClientInterface) {
                throw new RuntimeException('Invalid fixture credential client.', 6578579641);
            }

            $client = $configured;
        }

        return $client;
    }

    /**
     * Keep optional SDK capability detection independent from installed SDK types.
     */
    private function capabilityName(): string
    {
        return implode('\\', ['Netresearch', 'NrVault', 'Http', 'AdditionalSecretHttpClientInterface']);
    }

    #[Test]
    public function narrowedProfileGrantDeniesRenewalBeforeReadingSubject(): void
    {
        foreach (['allowedScopes' => ['write'], 'allowedAudiences' => ['urn:two']] as $field => $allowed) {
            $this->reads = [];
            $this->posted = [];
            $this->deleted = [];
            $this->stored = [];
            $factory = $this->kernel([$this->jsonResponse($this->response('first', 2))]);
            $session = $this->requireSession(
                $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline()),
            );
            $reference = $session->credentialIdentifier();
            $this->profiles['office'][$field] = $allowed;
            $this->clock->advanceSeconds(3);
            try {
                $session->credentialIdentifier();
                self::fail('Renewed a revoked profile grant.');
            } catch (McpTransportException $exception) {
                self::assertStringNotContainsString('subject-one', $exception->getMessage());
            } finally {
                $session->close();
            }

            self::assertCount(1, $this->posted);
            self::assertSame([self::SUBJECT_ONE], $this->reads);
            self::assertSame([$reference], $this->deleted);
        }
    }

    #[Test]
    public function cancellableIdpAbortUsesTheSharedSignalAndNeverFallsBack(): void
    {
        $signal = self::createStub(CancellationSignalInterface::class);
        $signal->method('isCancelled')->willReturn(false);
        $calls = new class {
            public ?RequestInterface $request = null;

            public ?CancellationSignalInterface $signal = null;

            public int $timeout = 0;
        };
        $http = $this->createMock(McpCancellableVaultFixtureInterface::class);
        $http
            ->method('withTimeout')
            ->willReturnCallback(
                static function (int $seconds) use ($calls, $http): VaultHttpClientInterface {
                    $calls->timeout = $seconds;
                    return $http;
                },
            );
        $http->method('withReason')->willReturnSelf();
        $http->method('withAuthentication')->willReturnSelf();
        $http->method('supportsCancellation')->willReturn(true);
        $http->expects(self::never())->method('sendRequest');
        $http
            ->method('sendCancellable')
            ->willReturnCallback(
                static function (
                    RequestInterface $request,
                    CancellationSignalInterface $signal,
                ) use ($calls): ResponseInterface {
                    $calls->request = $request;
                    $calls->signal = $signal;
                    throw new RequestCancelledException('private-token-value', 8246346433);
                },
            );
        $factory = $this->kernel([], http: $http);
        $session = $this->requireSession(
            $factory->openForExecution($this->server(), AiActorContext::backendUser(7), $this->deadline(), $signal),
        );
        $caught = null;
        try {
            $session->credentialIdentifier();
            self::fail('Ignored an in-flight IdP cancellation.');
        } catch (McpTransportException $exception) {
            $caught = $exception;
        } finally {
            $session->close();
        }

        self::assertInstanceOf(RequestInterface::class, $calls->request);
        self::assertSame('POST', $calls->request->getMethod());
        self::assertSame('https://idp.example.com/token', (string)$calls->request->getUri());
        self::assertSame($signal, $calls->signal);
        self::assertSame(20, $calls->timeout);
        self::assertInstanceOf(McpTransportException::class, $caught);
        self::assertTrue($caught->isCancellation(), $caught->getMessage());
        self::assertStringNotContainsString('private-token-value', $caught->getMessage());
        self::assertSame([], $this->stored);
        self::assertSame([], $this->posted);
        self::assertSame([], $this->deleted);
    }

    #[Test]
    public function tokenLifetimeSpentDuringVaultStoreCannotReturnAReference(): void
    {
        $factory = $this->kernel(
            [$this->jsonResponse($this->response('short-lived', 2))],
            onStore: function (): void {
                $this->clock->advanceSeconds(3);
            },
        );
        $session = $this->requireSession(
            $factory->openForExecution(
                $this->server(),
                AiActorContext::backendUser(7),
                $this->deadline(),
            ),
        );
        try {
            $session->credentialIdentifier();
            self::fail(
                'Returned a token whose lifetime was spent during storage.',
            );
        } catch (McpTransportException $exception) {
            self::assertStringNotContainsString(
                'short-lived',
                $exception->getMessage(),
            );
        } finally {
            $session->close();
        }

        self::assertCount(1, $this->stored);
        self::assertSame([$this->stored[0]['identifier']], $this->deleted);
    }

    #[Test]
    public function cancellationDuringVaultStoreDiscardsTheReference(): void
    {
        $signal = new class implements CancellationSignalInterface {
            public bool $cancelled = false;

            public function isCancelled(): bool
            {
                return $this->cancelled;
            }
        };
        $factory = $this->kernel(
            [$this->jsonResponse($this->response())],
            onStore: static function () use ($signal): void {
                $signal->cancelled = true;
            },
        );
        $session = $this->requireSession(
            $factory->openForExecution(
                $this->server(),
                AiActorContext::backendUser(7),
                $this->deadline(),
                $signal,
            ),
        );
        try {
            $session->credentialIdentifier();
            self::fail(
                'Returned a reference after cancellation during storage.',
            );
        } catch (McpTransportException $exception) {
            self::assertTrue($exception->isCancellation());
        } finally {
            $session->close();
        }

        self::assertCount(1, $this->stored);
        self::assertSame([$this->stored[0]['identifier']], $this->deleted);
    }

    #[Test]
    public function operationDeadlineSpentDuringVaultStoreDiscardsTheReference(): void
    {
        $factory = $this->kernel(
            [$this->jsonResponse($this->response())],
            onStore: function (): void {
                $this->clock->advanceSeconds(20);
            },
        );
        $session = $this->requireSession(
            $factory->openForExecution(
                $this->server(),
                AiActorContext::backendUser(7),
                $this->deadline(),
            ),
        );
        try {
            $session->credentialIdentifier();
            self::fail(
                'Returned a reference after its operation deadline was spent in storage.',
            );
        } catch (McpTransportException $exception) {
            self::assertStringNotContainsString(
                'issued-token',
                $exception->getMessage(),
            );
        } finally {
            $session->close();
        }

        self::assertCount(1, $this->stored);
        self::assertSame([$this->stored[0]['identifier']], $this->deleted);
    }
}

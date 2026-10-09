<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp\Auth;

use GuzzleHttp\Psr7\Request;
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
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Adapter\LocalEncryptionAdapter;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Configuration\SecurityProfile;
use Netresearch\NrVault\Crypto\EncryptedData;
use Netresearch\NrVault\Crypto\EncryptionServiceInterface;
use Netresearch\NrVault\Domain\Dto\SecretMetadata;
use Netresearch\NrVault\Domain\Repository\SecretRepository;
use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Http\DnsResolverInterface;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Http\VaultHttpClient;
use Netresearch\NrVault\Http\VaultHttpClientFactoryInterface;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Security\AccessControlService;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use Netresearch\NrVault\Service\VaultService;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Exercises the real SDK service, persisted MM ACLs and HTTP injection.
 * Only cryptography, audit sink and outbound wire are test doubles.
 */
#[CoversClass(McpTokenExchange::class)]
final class McpCredentialAclTest extends AbstractFunctionalTestCase
{
    private const SUBJECT = '11111111-1111-7111-8111-111111111111';

    private const SHARED_GROUP = 20;

    private const AUDIENCE = 'urn:acl';

    private const RESOURCE_URL = 'https://resource.example/mcp';

    private const ISSUED_TOKEN = 'owner-private-delegated-token';

    private VaultService $vault;

    private SecureHttpClientFactory $secureFactory;

    private AuditLogServiceInterface $audit;

    private TechnicalActorContextInterface $technicalActor;

    private bool $allowCli = false;

    private int $resourceContacts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $pool = $this->getService(ConnectionPool::class);
        $pool
            ->getConnectionForTable('be_groups')
            ->insert(
                'be_groups',
                [
                    'uid' => self::SHARED_GROUP,
                    'pid' => 0,
                    'title' => 'Shared MCP users',
                    'custom_options' => 'tx_nrvault:secret.create,tx_nrvault:secret.use,tx_nrvault:secret.delete',
                ],
            );
        foreach ([1, 2] as $uid) {
            $pool
                ->getConnectionForTable('be_users')
                ->update(
                    'be_users',
                    ['admin' => 0, 'usergroup' => (string)self::SHARED_GROUP],
                    ['uid' => $uid],
                );
        }

        $this->setUpBackendUser(1);
        $this->technicalActor = $this->getService(TechnicalActorContextInterface::class);
        $configuration = self::createStub(ExtensionConfigurationInterface::class);
        $configuration
            ->method('getSecurityProfile')
            ->willReturn(SecurityProfile::Standard);
        $configuration
            ->method('isCliAccessAllowed')
            ->willReturnCallback(fn(): bool => $this->allowCli);
        $configuration
            ->method('getCliAllowedOperations')
            ->willReturn(['secret.create', 'secret.use', 'secret.delete']);
        $dns = self::createStub(DnsResolverInterface::class);
        $dns->method('resolve')->willReturn([['ip' => '93.184.216.34']]);
        $this->secureFactory = new SecureHttpClientFactory($dns);
        $this->audit = self::createStub(AuditLogServiceInterface::class);
        $encryption = self::createStub(EncryptionServiceInterface::class);
        $encryption
            ->method('encrypt')
            ->willReturn(
                new EncryptedData(
                    'cipher',
                    'dek',
                    'dek-nonce',
                    'value-nonce',
                    'checksum',
                ),
            );
        $encryption
            ->method('decrypt')
            ->willReturnCallback(
                static fn(
                    string $cipher,
                    string $dek,
                    string $dekNonce,
                    string $valueNonce,
                    string $identifier,
                ): string => $identifier === self::SUBJECT ? 'mapped-subject' : self::ISSUED_TOKEN,
            );
        $httpFactory = self::createStub(VaultHttpClientFactoryInterface::class);
        $httpFactory
            ->method('create')
            ->willReturnCallback($this->exchangeClient(...));
        $this->vault = new VaultService(
            new LocalEncryptionAdapter(new SecretRepository($pool)),
            $encryption,
            new AccessControlService(
                $configuration,
                $pool,
                $this->technicalActor,
            ),
            $this->audit,
            $configuration,
            $httpFactory,
        );
        $this->vault->store(
            self::SUBJECT,
            'mapped-subject',
            ['owner' => 1, 'groups' => [self::SHARED_GROUP]],
        );
    }

    #[Test]
    public function personalTokenDoesNotGrantSharedGroupPeersRetrieveOrHttpUse(): void
    {
        $credential = $this->exchange(
            AiActorContext::backendUser(
                1,
                backendGroupIds: [self::SHARED_GROUP],
            ),
        );
        $details = $this->vault->getMetadata($credential->identifier);
        self::assertSame(1, $details->ownerUid);
        self::assertSame([], $details->groups);
        self::assertFalse($details->frontendAccessible);
        self::assertSame(
            self::ISSUED_TOKEN,
            $this->vault->retrieve($credential->identifier),
        );
        $this->useCredential($credential->identifier);
        self::assertSame(1, $this->resourceContacts);
        $this->setUpBackendUser(2);
        // Both actors really share a persisted, enabled group; the subject is
        // group-readable, while the exchanged personal credential is not.
        self::assertSame(
            'mapped-subject',
            $this->vault->retrieve(self::SUBJECT),
        );
        $this->assertRetrieveAndHttpDenied($credential->identifier);
        self::assertSame(1, $this->resourceContacts);
    }

    #[Test]
    public function originalOwnerRuntimeScopeWorksWithoutAmbientGroupFallback(): void
    {
        $credential = $this->exchange(
            AiActorContext::backendUser(
                1,
                backendGroupIds: [self::SHARED_GROUP],
            ),
        );
        $this->setUpBackendUser(2);
        $this->assertRetrieveAndHttpDenied($credential->identifier);
        $this->technicalActor->runAs(
            1,
            function () use ($credential): void {
                self::assertSame(
                    self::ISSUED_TOKEN,
                    $this->vault->retrieve($credential->identifier),
                );
                $this->useCredential($credential->identifier);
            },
        );
        $this->assertRetrieveAndHttpDenied($credential->identifier);
        self::assertSame(1, $this->resourceContacts);
    }

    #[Test]
    public function aCoercedDifferentOwnerIsRefusedAndImmediatelyDeleted(): void
    {
        $this->setUpBackendUser(2);
        try {
            $this->exchange(
                AiActorContext::backendUser(
                    1,
                    backendGroupIds: [self::SHARED_GROUP],
                ),
            );
            self::fail(
                'Returned an exchanged credential owned by another backend user.',
            );
        } catch (McpTransportException $exception) {
            self::assertStringNotContainsString(
                self::ISSUED_TOKEN,
                $exception->getMessage(),
            );
        }

        self::assertSame([self::SUBJECT], array_map(
            static fn(SecretMetadata $metadata): string => $metadata->identifier,
            $this->vault->list(),
        ));
        self::assertSame(0, $this->resourceContacts);
    }

    #[Test]
    public function ownerZeroDoesNotAuthenticateAnUnprovisionedCliProcess(): void
    {
        $GLOBALS['BE_USER'] = new CommandLineUserAuthentication();
        $this->allowCli = true;
        $credential = $this->exchange(AiActorContext::serviceAccount('provisioned-worker'));
        self::assertSame(
            0,
            $this->vault->getMetadata($credential->identifier)->ownerUid,
        );
        self::assertSame(
            [],
            $this->vault->getMetadata($credential->identifier)->groups,
        );
        $this->useCredential($credential->identifier);
        $this->allowCli = false;
        $this->assertRetrieveAndHttpDenied($credential->identifier);
        self::assertSame(1, $this->resourceContacts);
    }

    private function exchange(AiActorContext $actor): McpIssuedCredential
    {
        $clock = new FakeMcpClock();
        $server = new McpServerRecord(
            uid: 1,
            pid: 0,
            identifier: 'acl-server',
            name: 'ACL server',
            description: '',
            url: self::RESOURCE_URL,
            authCredential: '',
            authPlacement: 'bearer',
            authHeaderName: '',
            dataClass: 'internal',
            requiresApproval: '0',
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
            delegationProfile: 'acl',
            delegationAudience: self::AUDIENCE,
            delegationScopes: 'read',
        );
        return (new McpTokenExchange($this->vault, $this->secureFactory, $clock))->exchange(
            $server,
            $actor,
            new McpDelegationProfile(
                'acl',
                'https://idp.example/token',
                'public-client',
                null,
                [self::AUDIENCE],
                ['read'],
            ),
            new McpSubjectCredential(self::SUBJECT, [self::AUDIENCE], ['read']),
            ['read'],
            McpOperationDeadline::start($clock, 20),
            null,
        );
    }

    private function exchangeClient(
        VaultServiceInterface $vault,
    ): VaultHttpClientInterface {
        // withTimeout rebuilds its transport in the SDK. Keep only that wire
        // construction mocked and execute injection/access via the real SDK.
        $builder = $this->createMock(VaultHttpClientInterface::class);
        $builder->method('withTimeout')->willReturnSelf();
        $builder->method('withReason')->willReturnSelf();
        $builder
            ->method('withAuthentication')
            ->with(
                self::SUBJECT,
                SecretPlacement::BodyField,
                ['bodyField' => 'subject_token'],
            )
            ->willReturnSelf();
        $wire = $this->createMock(ClientInterface::class);
        $wire
            ->method('sendRequest')
            ->willReturnCallback(
                static function (RequestInterface $request): ResponseInterface {
                    parse_str((string)$request->getBody(), $fields);
                    self::assertSame('mapped-subject', $fields['subject_token']);
                    return new Response(
                        200,
                        ['Content-Type' => 'application/json'],
                        json_encode(
                            [
                                'access_token' => self::ISSUED_TOKEN,
                                'token_type' => 'Bearer',
                                'issued_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                                'expires_in' => 60,
                            ],
                            JSON_THROW_ON_ERROR,
                        ),
                    );
                },
            );
        $builder
            ->method('sendRequest')
            ->willReturnCallback(
                fn(RequestInterface $request): ResponseInterface => (new VaultHttpClient(
                    $vault,
                    $this->audit,
                    $wire,
                    secureHttpClientFactory: $this->secureFactory,
                ))->withAuthentication(
                    self::SUBJECT,
                    SecretPlacement::BodyField,
                    ['bodyField' => 'subject_token'],
                )->sendRequest($request),
            );
        return $builder;
    }

    private function useCredential(string $identifier): void
    {
        $wire = $this->createMock(ClientInterface::class);
        $wire
            ->method('sendRequest')
            ->willReturnCallback(
                function (RequestInterface $request): ResponseInterface {
                    ++$this->resourceContacts;
                    self::assertSame(
                        'Bearer ' . self::ISSUED_TOKEN,
                        $request->getHeaderLine('Authorization'),
                    );
                    return new Response(200);
                },
            );
        $response = (new VaultHttpClient(
            $this->vault,
            $this->audit,
            $wire,
            secureHttpClientFactory: $this->secureFactory,
        ))
            ->withAuthentication($identifier, SecretPlacement::Bearer)
            ->sendRequest(new Request('POST', self::RESOURCE_URL));
        self::assertSame(200, $response->getStatusCode());
    }

    private function assertRetrieveAndHttpDenied(string $identifier): void
    {
        try {
            $this->vault->retrieve($identifier);
            self::fail('A non-owner retrieved the delegated credential.');
        } catch (AccessDeniedException $exception) {
            self::assertStringNotContainsString(
                self::ISSUED_TOKEN,
                $exception->getMessage(),
            );
        }

        try {
            $this->useCredential($identifier);
            self::fail(
                'A non-owner used the delegated credential at the transport.',
            );
        } catch (AccessDeniedException $exception) {
            self::assertStringNotContainsString(
                self::ISSUED_TOKEN,
                $exception->getMessage(),
            );
        }
    }
}

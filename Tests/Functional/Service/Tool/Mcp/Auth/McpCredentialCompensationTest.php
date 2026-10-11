<?php

/* Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Mcp\Auth;

use Error;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpDelegatedCredentialSession;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpDelegationBinding;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpDelegationProfile;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpSubjectCredential;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpSubjectResolverInterface;
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
use Netresearch\NrVault\Domain\Repository\SecretRepository;
use Netresearch\NrVault\Event\SecretCreatedEvent;
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
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversNothing]
final class McpCredentialCompensationTest extends AbstractFunctionalTestCase
{
    private const SUBJECT = '11111111-1111-7111-8111-111111111111';

    private const AUDIENCE = 'urn:postpersist';

    private const PRIVATE_DETAIL = 'private listener detail must not escape';

    /**
     * @return array<string, array{string}>
     */
    public static function modes(): array
    {
        return [
            'healthy operation' => ['healthy'],
            'listener RuntimeException' => ['runtime'],
            'listener Error' => ['error'],
        ];
    }

    #[Test]
    #[DataProvider('modes')]
    public function everyPersistedTemporaryCredentialIsRemovedWhenTheOperationEnds(
        string $mode,
    ): void {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);
        $pool = $this->getService(ConnectionPool::class);
        $configuration = self::createStub(ExtensionConfigurationInterface::class);
        $configuration
            ->method('getSecurityProfile')
            ->willReturn(SecurityProfile::Standard);
        $configuration->method('isCliAccessAllowed')->willReturn(false);
        $dns = self::createStub(DnsResolverInterface::class);
        $dns->method('resolve')->willReturn([['ip' => '93.184.216.34']]);
        $secure = new SecureHttpClientFactory($dns);
        $audit = self::createStub(AuditLogServiceInterface::class);
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
                ): string => $identifier === self::SUBJECT ? 'mapped-subject' : 'controlled-issued-token',
            );
        $dispatcher = new class (
            $mode,
            self::SUBJECT,
            self::PRIVATE_DETAIL,
        ) implements EventDispatcherInterface {
            public ?string $issuedIdentifier = null;

            public function __construct(
                private readonly string $mode,
                private readonly string $subject,
                private readonly string $detail,
            ) {}

            public function dispatch(object $event): object
            {
                if ($event instanceof SecretCreatedEvent && $event->getIdentifier() !== $this->subject) {
                    $this->issuedIdentifier = $event->getIdentifier();
                    if ($this->mode === 'runtime') {
                        throw new RuntimeException($this->detail, 1799990231);
                    }

                    if ($this->mode === 'error') {
                        throw new Error($this->detail, 1799990232);
                    }
                }

                return $event;
            }
        };
        /** @var list<RequestInterface> $requests */
        $requests = [];
        $httpFactory = self::createStub(VaultHttpClientFactoryInterface::class);
        $httpFactory
            ->method('create')
            ->willReturnCallback(
                function (
                    VaultServiceInterface $vault,
                ) use ($audit, $secure, &$requests): VaultHttpClientInterface {
                    $builder = self::createStub(VaultHttpClientInterface::class);
                    $builder->method('withTimeout')->willReturnSelf();
                    $builder->method('withReason')->willReturnSelf();
                    $builder->method('withAuthentication')->willReturnSelf();
                    $wire = self::createStub(ClientInterface::class);
                    $wire->method('sendRequest')->willReturnCallback(
                        static function (
                            RequestInterface $request,
                        ) use (&$requests): ResponseInterface {
                            $requests[] = $request;
                            return new Response(
                                200,
                                ['Content-Type' => 'application/json'],
                                '{"access_token":"controlled-issued-token","token_type":"Bearer","issued_token_type":"urn:ietf:params:oauth:token-type:access_token","expires_in":60}',
                            );
                        },
                    );
                    $builder->method('sendRequest')->willReturnCallback(
                        static fn(
                            RequestInterface $request,
                        ): ResponseInterface => (new VaultHttpClient(
                            $vault,
                            $audit,
                            $wire,
                            secureHttpClientFactory: $secure,
                        ))->withAuthentication(
                            self::SUBJECT,
                            SecretPlacement::BodyField,
                            ['bodyField' => 'subject_token'],
                        )->sendRequest($request),
                    );
                    return $builder;
                },
            );
        $vault = new VaultService(
            new LocalEncryptionAdapter(new SecretRepository($pool)),
            $encryption,
            new AccessControlService(
                $configuration,
                $pool,
                $this->getService(TechnicalActorContextInterface::class),
            ),
            $audit,
            $configuration,
            $httpFactory,
            $dispatcher,
        );
        $vault->store(
            self::SUBJECT,
            'mapped-subject',
            ['owner' => 1, 'groups' => []],
        );
        $clock = new FakeMcpClock();
        $server = new McpServerRecord(
            1,
            0,
            'postpersist-server',
            'Post-persist probe',
            '',
            'https://resource.example/mcp',
            '',
            'bearer',
            '',
            'internal',
            '0',
            true,
            '',
            '',
            0,
            0,
            0,
            0,
            0,
            0,
            authMode: 'delegated',
            delegationProfile: 'postpersist',
            delegationAudience: self::AUDIENCE,
            delegationScopes: 'read',
        );
        $profile = new McpDelegationProfile(
            'postpersist',
            'https://idp.example/token',
            'public-client',
            null,
            [self::AUDIENCE],
            ['read'],
        );
        $subject = new McpSubjectCredential(self::SUBJECT, [self::AUDIENCE], ['read']);
        $binding = new McpDelegationBinding(
            $server,
            AiActorContext::backendUser(1, true),
            $profile,
            ['read'],
            McpOperationDeadline::start($clock, 20),
            null,
        );
        $session = new McpDelegatedCredentialSession(
            $binding,
            self::createStub(McpSubjectResolverInterface::class),
            new McpTokenExchange($vault, $secure, $clock),
            $vault,
            $clock,
            $subject,
        );
        $caught = null;
        $closeError = null;
        $returned = null;
        try {
            $returned = $session->credentialIdentifier();
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            try {
                $session->close();
            } catch (Throwable $exception) {
                $closeError = $exception;
            }
        }

        self::assertNull($closeError);
        self::assertCount(1, $requests);
        self::assertSame(
            'https://idp.example/token',
            (string)$requests[0]->getUri(),
        );
        parse_str((string)$requests[0]->getBody(), $fields);
        self::assertSame('mapped-subject', $fields['subject_token']);
        self::assertIsString($dispatcher->issuedIdentifier);
        $rows = $pool
            ->getConnectionForTable('tx_nrvault_secret')
            ->executeQuery(
                'SELECT identifier, deleted, owner_uid, expires_at, context FROM tx_nrvault_secret WHERE context = ?',
                ['mcp_delegation'],
            )
            ->fetchAllAssociative();
        self::assertCount(
            1,
            $rows,
            'The real store event occurs after this SQL row was persisted.',
        );
        self::assertSame($dispatcher->issuedIdentifier, $rows[0]['identifier']);
        self::assertSame(1, (int)$rows[0]['owner_uid']);
        self::assertGreaterThan(time(), (int)$rows[0]['expires_at']);
        if ($mode === 'healthy') {
            self::assertNull($caught);
            self::assertSame($dispatcher->issuedIdentifier, $returned);
        } else {
            self::assertNull($returned);
            self::assertInstanceOf(McpTransportException::class, $caught);
            self::assertSame(1799990219, $caught->getCode());
            self::assertStringContainsString(
                'exchange_failed',
                $caught->getMessage(),
            );
            self::assertStringNotContainsString(
                self::PRIVATE_DETAIL,
                $caught->getMessage(),
            );
        }

        $active = array_values(
            array_filter(
                $rows,
                static fn(array $row): bool => (int)$row['deleted'] === 0,
            ),
        );
        self::assertCount(
            0,
            $active,
            'Operation completion must delete temporary credentials even when a post-persist store listener threw.',
        );
        self::assertSame(
            0,
            (int)$pool
                ->getConnectionForTable('tx_nrvault_secret')
                ->executeQuery(
                    'SELECT deleted FROM tx_nrvault_secret WHERE identifier = ?',
                    [self::SUBJECT],
                )
                ->fetchOne(),
            'The operator-owned subject credential is retained.',
        );
    }
}

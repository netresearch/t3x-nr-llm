<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\Queue;

use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Provider\Contract\ProviderInterface;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\Queue\WorkerVaultScope;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Privacy\RunProviderPrivacyInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixture\AgentRunRepositoryFactory;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\ScriptedToolAdapter;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use Netresearch\NrVault\Adapter\LocalEncryptionAdapter;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Configuration\SecurityProfile;
use Netresearch\NrVault\Crypto\EncryptedData;
use Netresearch\NrVault\Crypto\EncryptionServiceInterface;
use Netresearch\NrVault\Domain\Repository\SecretRepository;
use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Exception\TechnicalActorException;
use Netresearch\NrVault\Http\VaultHttpClientFactoryInterface;
use Netresearch\NrVault\Security\AccessControlService;
use Netresearch\NrVault\Security\TechnicalActorContextInterface;
use Netresearch\NrVault\Service\VaultService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * What the worker reads a provider key as, against the real nr_vault access rules
 * with `allowCliAccess` off (ADR-234). The key is read the way a provider does,
 * while the model call is being prepared, so an assertion here sees what a run on
 * the command line gets.
 */
#[CoversClass(WorkerVaultScope::class)]
final class WorkerVaultScopeTest extends AbstractFunctionalTestCase
{
    use LlmServiceManagerTestFactory;

    private const SECRET = 'worker_provider_key';

    private const GROUP = 20;

    private const WORKER = 2;

    private ConnectionPool $pool;

    private VaultService $vault;

    private TechnicalActorContextInterface $technicalActor;

    private LlmConfiguration $configuration;

    private ?string $keyRead = null;

    private bool $keyRefused = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->pool = $this->getService(ConnectionPool::class);
        $this->pool->getConnectionForTable('be_groups')->insert('be_groups', ['uid' => self::GROUP, 'pid' => 0, 'title' => 'Reads the provider key']);
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['admin' => 0, 'usergroup' => (string) self::GROUP], ['uid' => self::WORKER]);

        $this->setUpBackendUser(1);
        $this->technicalActor = $this->getService(TechnicalActorContextInterface::class);

        $configuration = self::createStub(ExtensionConfigurationInterface::class);
        $configuration->method('getSecurityProfile')->willReturn(SecurityProfile::Standard);
        $configuration->method('isCliAccessAllowed')->willReturn(false);

        $encryption = self::createStub(EncryptionServiceInterface::class);
        $encryption->method('encrypt')->willReturn(new EncryptedData('cipher', 'dek', 'dek-nonce', 'value-nonce', 'checksum'));
        $encryption->method('decrypt')->willReturn('the-provider-key');

        $this->vault = new VaultService(
            new LocalEncryptionAdapter(new SecretRepository($this->pool)),
            $encryption,
            new AccessControlService($configuration, $this->pool, $this->technicalActor),
            self::createStub(AuditLogServiceInterface::class),
            $configuration,
            self::createStub(VaultHttpClientFactoryInterface::class),
        );
        $this->vault->store(self::SECRET, 'the-provider-key', ['owner' => 1, 'groups' => [self::GROUP]]);

        // What `bin/typo3` leaves in the global: a command-line user, not logged in.
        $GLOBALS['BE_USER'] = new CommandLineUserAuthentication();

        $provider = new Provider();
        $provider->setIdentifier('fake-provider');
        $provider->setAdapterType('openai');
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setModelId('scripted-model');
        $model->setProvider($provider);

        $this->configuration = new LlmConfiguration();
        $this->configuration->setIdentifier('cfg-worker');
        $this->configuration->setLlmModel($model);
    }

    #[Test]
    public function withoutAnIdentityTheCommandLineIsRefusedTheKey(): void
    {
        $this->runOneQueuedRun(0);

        self::assertTrue($this->keyRefused);
        self::assertNull($this->keyRead);
    }

    #[Test]
    public function aRunReadsTheKeyAsTheConfiguredUserAndOnlyWhileItRuns(): void
    {
        $this->runOneQueuedRun(self::WORKER);

        self::assertSame('the-provider-key', $this->keyRead);
        self::assertFalse($this->keyRefused);

        $this->expectException(AccessDeniedException::class);
        $this->vault->retrieve(self::SECRET);
    }

    #[Test]
    public function aConfiguredUserWithoutAGrantIsRefusedTheKey(): void
    {
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => ''], ['uid' => self::WORKER]);

        $this->runOneQueuedRun(self::WORKER);

        self::assertTrue($this->keyRefused);
    }

    #[Test]
    public function aUserWhoCannotActFailsTheRunBeforeAnyModelCall(): void
    {
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['disable' => 1], ['uid' => self::WORKER]);

        $uuid   = $this->runtime(self::WORKER)->defer($this->request());
        $result = $this->runtime(self::WORKER)->runQueued($uuid);

        self::assertSame(AgentRunOutcome::FAILED, $result?->outcome);
        self::assertSame(AgentRunStatus::FAILED, $this->repository()->findByUuid($uuid)?->statusEnum());
        self::assertNull($this->keyRead);
        self::assertFalse($this->keyRefused);
    }

    #[Test]
    public function aRunThatChangedOwnerIsLeftToItsOwner(): void
    {
        $runtime = $this->runtime(self::WORKER);
        $uuid    = $runtime->defer($this->request());

        $actor = self::createStub(TechnicalActorContextInterface::class);
        $actor->method('runAs')->willReturnCallback(function () use ($uuid): never {
            $this->pool->getConnectionForTable('tx_nrllm_agentrun')->update('tx_nrllm_agentrun', ['claimed_by' => 'another-worker'], ['uuid' => $uuid]);

            throw TechnicalActorException::userDisabled(self::WORKER);
        });

        $result = $this->runtime(self::WORKER, $actor)->runQueued($uuid);

        self::assertSame(AgentRunOutcome::LEASE_LOST, $result?->outcome);
        self::assertSame(AgentRunStatus::RUNNING, $this->repository()->findByUuid($uuid)?->statusEnum());
    }

    #[Test]
    public function aScopeThatCannotBeOpenedDoesNotLeaveTheRunRunning(): void
    {
        $runtime = $this->runtime(self::WORKER);
        $uuid    = $runtime->defer($this->request());

        $actor = self::createStub(TechnicalActorContextInterface::class);
        $actor->method('runAs')->willThrowException(new RuntimeException('The database went away'));

        $this->runtime(self::WORKER, $actor)->runQueued($uuid);

        self::assertNotSame(AgentRunStatus::RUNNING, $this->repository()->findByUuid($uuid)?->statusEnum());
    }

    private function runOneQueuedRun(int $workerUid): void
    {
        $runtime = $this->runtime($workerUid);
        $runtime->runQueued($runtime->defer($this->request()));
    }

    private function request(): AgentRunRequest
    {
        return new AgentRunRequest(
            configuration: $this->configuration,
            messages: [['role' => 'user', 'content' => 'Say something.']],
            actor: AiActorContext::backendUser(1, isAdmin: true),
        );
    }

    private function readKey(): ProviderInterface
    {
        try {
            $this->keyRead = $this->vault->retrieve(self::SECRET);
        } catch (AccessDeniedException) {
            $this->keyRefused = true;
        }

        return new ScriptedToolAdapter('Done.', 'no_tool', []);
    }

    private function repository(): AgentRunRepository
    {
        return AgentRunRepositoryFactory::create(
            $this->pool,
            $this->getService(AgentStateCodec::class),
        );
    }

    private function persister(): AgentRunPersister
    {
        return new AgentRunPersister($this->repository(), FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL), new NullLogger());
    }

    private function runtime(int $workerUid, ?TechnicalActorContextInterface $technicalActor = null): AgentRuntime
    {
        $adapters = self::createStub(ProviderAdapterRegistryInterface::class);
        $adapters->method('createAdapterFromModel')->willReturnCallback($this->readKey(...));

        $managerConfiguration = self::createStub(ExtensionConfiguration::class);
        $managerConfiguration->method('get')->willReturn([]);

        $scopeConfiguration = self::createStub(ExtensionConfiguration::class);
        $scopeConfiguration->method('get')->willReturn(['worker' => ['technicalBeUserUid' => (string) $workerUid]]);

        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findByUid')->willReturn($this->configuration);

        $registry = new ToolRegistry([]);

        return new AgentRuntime(
            toolLoop: new ToolLoopService(
                $this->createLlmServiceManager($managerConfiguration, new NullLogger(), $adapters, new MiddlewarePipeline([]), self::createStub(CacheManagerInterface::class), runPrivacy: self::createStub(RunProviderPrivacyInterface::class)),
                $registry,
                new ToolCallPolicy(
                    $registry,
                    new ToolAvailabilityService($registry, new ToolStateRepository($this->pool), new ToolGroupStateRepository($this->pool)),
                    new AllowedToolsResolver(new SkillComposer(), $registry),
                    new ToolDataClassResolver($registry),
                    new TrustZoneResolver(),
                    new DataClassEnforcementResolver(),
                ),
                new NullLogger(),
            ),
            persister: $this->persister(),
            configurationRepository: $configurations,
            logger: new NullLogger(),
            messageBus: self::createStub(MessageBusInterface::class),
            actingBackendUserResolver: new ActingBackendUserResolver(),
            workerVaultScope: new WorkerVaultScope($technicalActor ?? $this->technicalActor, $scopeConfiguration),
        );
    }
}

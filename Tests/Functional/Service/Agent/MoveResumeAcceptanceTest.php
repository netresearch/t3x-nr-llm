<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\AgentRunOutcome;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunEvent;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Agent\ResumeCoordinator;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\MoveContentElementTool;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\ScriptedToolAdapter;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Suspend and resume of a content move are two requests with two ambient
 * backend users: the run owner's at suspend, the approver's — or none, in a
 * worker — at resume. The resume recomputes the preview and compares it byte
 * for byte with the one the approver saw (ADR-184), so a preview line that
 * reads the AMBIENT user would differ and send every approval back once.
 *
 * The target column is one a backend layout names, and the approver's user
 * TSconfig renames it: exactly the ambient input core's backend layout lookup
 * reads. The card does not take the column name from there; its lines depend
 * on the acting user and the records only (ADR-213), so the approval executes
 * the move on the first resume.
 */
#[CoversClass(ResumeCoordinator::class)]
#[CoversClass(MoveContentElementTool::class)]
final class MoveResumeAcceptanceTest extends AbstractFunctionalTestCase
{
    use LlmServiceManagerTestFactory;

    private const OWNER = 1;

    private const OTHER_USER = 2;

    private const SOURCE_PAGE = 1;

    private const TARGET_PAGE = 2;

    private const ELEMENT = 10;

    private const COLUMN = 100;

    private ConnectionPool $connectionPool;

    private ToolRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->getService(ConnectionPool::class);
        $this->importFixture('BeUsers.csv');

        $pages = $this->connectionPool->getConnectionForTable('pages');
        foreach ([self::SOURCE_PAGE => 'Source', self::TARGET_PAGE => 'Target'] as $uid => $title) {
            $pages->insert('pages', [
                'uid' => $uid, 'pid' => 0, 'title' => $title, 'doktype' => 1, 'slug' => '/' . $uid, 'sorting' => $uid,
                'perms_userid' => self::OWNER, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
            ]);
        }

        $pages->update('pages', [
            'backend_layout' => 'pagets__sidebar',
            'TSconfig'       => implode("\n", [
                'mod.web_layout.BackendLayouts.sidebar {',
                '  title = Sidebar',
                '  config.backend_layout {',
                '    colCount = 1',
                '    rowCount = 1',
                '    rows.1.columns.1 {',
                '      name = Seitenleiste',
                '      colPos = ' . self::COLUMN,
                '    }',
                '  }',
                '}',
            ]),
        ], ['uid' => self::TARGET_PAGE]);
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::ELEMENT, 'pid' => self::SOURCE_PAGE, 'colPos' => 0, 'sorting' => 1,
            'CType' => 'text', 'header' => 'Movable', 'sys_language_uid' => 0,
        ]);
        // The approver's user TSconfig renames the layout's column.
        $this->connectionPool->getConnectionForTable('be_users')->update('be_users', [
            'TSconfig' => 'page.mod.web_layout.BackendLayouts.sidebar.config.backend_layout.rows.1.columns.1.name = Andere Spalte',
        ], ['uid' => self::OTHER_USER]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $this->registry = new ToolRegistry([
            new MoveContentElementTool($this->connectionPool, $this->getService(ApprovalPreviewTranslator::class)),
        ]);
        (new ToolStateRepository($this->connectionPool))->setEnabled('move_content_element', true);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aResumeUnderAnotherAmbientUserMovesTheElementOnTheFirstApproval(): void
    {
        $runtime = $this->runtime();

        // The suspend request: the owner's own.
        $this->setUpBackendUser(self::OWNER);
        $started = $runtime->run($this->request());
        self::assertSame(AgentRunOutcome::AWAITING_APPROVAL, $started->outcome);

        // The resume request: fresh runtime caches, another ambient user.
        $this->flushRuntimeCache();
        $this->setUpBackendUser(self::OTHER_USER);
        $approved = $this->approve($runtime, $started->runUuid);

        self::assertSame(AgentRunOutcome::COMPLETED, $approved->outcome, (string)$approved->error?->getMessage());
        $row = $this->elementRow();
        self::assertSame(self::TARGET_PAGE, (int)($row['pid'] ?? 0));
        self::assertSame(self::COLUMN, (int)($row['colPos'] ?? -1));
        $tools = $this->toolEvents($started->runUuid);
        self::assertCount(1, $tools, 'the move executes once, on the first approval');
        self::assertNotTrue($tools[0]->payload['toolIsError'] ?? null);
    }

    /**
     * A worker has no ambient backend user. The preview compares equal, so the
     * approval does not bounce; the write itself then refuses, because a
     * DataHandler write needs a backend environment (ADR-135).
     */
    #[Test]
    public function aResumeWithoutAnAmbientUserDoesNotBounce(): void
    {
        $runtime = $this->runtime();

        $this->setUpBackendUser(self::OWNER);
        $started = $runtime->run($this->request());
        self::assertSame(AgentRunOutcome::AWAITING_APPROVAL, $started->outcome);

        $this->flushRuntimeCache();
        unset($GLOBALS['BE_USER']);
        $approved = $this->approve($runtime, $started->runUuid);

        self::assertNotSame(AgentRunOutcome::AWAITING_APPROVAL, $approved->outcome, 'the approval bounced');
        $tools = $this->toolEvents($started->runUuid);
        self::assertCount(1, $tools);
        self::assertTrue($tools[0]->payload['toolIsError'] ?? null);
        self::assertSame(self::SOURCE_PAGE, (int)($this->elementRow()['pid'] ?? 0), 'nothing was moved');
    }

    private function approve(AgentRuntime $runtime, string $uuid): AgentRunResult
    {
        $run   = $this->storedRun($uuid);
        $views = (new WaitingRunViewFactory($this->registry, new SchemaPropertyClassifier(), new PendingTurnDigest(), $this->getService(ApprovalPreviewTranslator::class)))
            ->buildWaiting([$run]);
        self::assertCount(1, $views);
        self::assertIsString($views[0]->turnDigest);

        return $runtime->approve(
            AiActorContext::backendUser(self::OWNER, isAdmin: true),
            $uuid,
            new ApprovalDecision(true, self::OWNER, $views[0]->turnDigest),
        );
    }

    private function runtime(): AgentRuntime
    {
        $adapter         = new ScriptedToolAdapter('Moved.', 'move_content_element', ['uid' => self::ELEMENT, 'target_page' => self::TARGET_PAGE, 'column' => self::COLUMN]);
        $adapterRegistry = self::createStub(ProviderAdapterRegistryInterface::class);
        $adapterRegistry->method('createAdapterFromModel')->willReturn($adapter);

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        $policy = new ToolCallPolicy(
            $this->registry,
            new ToolAvailabilityService(
                $this->registry,
                new ToolStateRepository($this->connectionPool),
                new ToolGroupStateRepository($this->connectionPool),
            ),
            new AllowedToolsResolver(new SkillComposer(), $this->registry),
            new ToolDataClassResolver($this->registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        $loop = new ToolLoopService(
            $this->createLlmServiceManager(
                $extensionConfiguration,
                new NullLogger(),
                $adapterRegistry,
                new MiddlewarePipeline([]),
                self::createStub(CacheManagerInterface::class),
            ),
            $this->registry,
            $policy,
            new NullLogger(),
        );

        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findByUid')->willReturn($this->configuration());

        return new AgentRuntime(
            toolLoop: $loop,
            persister: $this->persister(),
            configurationRepository: $configurationRepository,
            logger: new NullLogger(),
            actingBackendUserResolver: new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($this->registry),
            toolPolicy: $policy,
        );
    }

    private function persister(): AgentRunPersister
    {
        return new AgentRunPersister(
            new AgentRunRepository($this->connectionPool, $this->getService(AgentStateCodec::class)),
            FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL),
            new NullLogger(),
        );
    }

    private function request(): AgentRunRequest
    {
        return new AgentRunRequest(
            $this->configuration(),
            [ChatMessage::user('Move the element into the sidebar of page 2.')],
            AiActorContext::backendUser(self::OWNER, isAdmin: true),
        );
    }

    private function configuration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setIdentifier('fake-provider');
        $provider->setAdapterType('openai');
        $provider->setTrustZoneEnum(TrustZone::LOCAL);
        $provider->setApiKey('nr_move_resume_vault_key');

        $model = new Model();
        $model->setModelId('scripted-model');
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('cfg-move-resume');
        $configuration->setLlmModel($model);

        return $configuration;
    }

    private function storedRun(string $uuid): AgentRun
    {
        $run = $this->persister()->findRun($uuid);
        self::assertInstanceOf(AgentRun::class, $run);

        return $run;
    }

    /**
     * @return list<AgentRunEvent>
     */
    private function toolEvents(string $uuid): array
    {
        return array_values(array_filter(
            $this->persister()->findEvents($this->storedRun($uuid)->uid),
            static fn(AgentRunEvent $event): bool => $event->kindEnum() === AgentEventKind::TOOL,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function elementRow(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('*')->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', self::ELEMENT))
            ->executeQuery()->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    /**
     * What a new request starts with: no runtime cache from an earlier one.
     */
    private function flushRuntimeCache(): void
    {
        $this->getService(CacheManager::class)->getCache('runtime')->flush();
    }
}

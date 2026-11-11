<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Command;

use Netresearch\NrLlm\Command\QueueEditorActionBatchCommand;
use Netresearch\NrLlm\Command\WorkAgentQueueCommand;
use Netresearch\NrLlm\Domain\Enum\AgentRunStatus;
use Netresearch\NrLlm\Domain\Enum\AgentRunTerminationReason;
use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\ProviderAdapterRegistryInterface;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Agent\AgentRunResult;
use Netresearch\NrLlm\Service\Agent\AgentRuntime;
use Netresearch\NrLlm\Service\Agent\ApprovalDecision;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\CacheManagerInterface;
use Netresearch\NrLlm\Service\ConfigurationResolver;
use Netresearch\NrLlm\Service\Context\TranscriptEstimator;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmConfigurationServiceInterface;
use Netresearch\NrLlm\Service\Privacy\RunProviderPrivacyInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolverInterface;
use Netresearch\NrLlm\Service\Tool\AgentRunPersister;
use Netresearch\NrLlm\Service\Tool\AgentRunRepository;
use Netresearch\NrLlm\Service\Tool\AgentStateCodec;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\BatchScopeReader;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchEntry;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchPlanner;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogue;
use Netresearch\NrLlm\Service\Tool\EditorActionCatalogueInterface;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\RecordTextReader;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Service\Tool\Vision\FalImageDownscaler;
use Netresearch\NrLlm\Service\Tool\Vision\VisionActionBuilder;
use Netresearch\NrLlm\Service\Tool\Vision\VisionSettings;
use Netresearch\NrLlm\Tests\Fixture\AgentRunRepositoryFactory;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Fixtures\ScriptedToolAdapter;
use Netresearch\NrLlm\Tests\LlmServiceManagerTestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The acceptance of the ticket, the whole way (ADR-236): a scheduled batch over a
 * folder, a worker tick, the image in the model's input, and a text that lands
 * only when somebody approved it.
 */
#[CoversClass(QueueEditorActionBatchCommand::class)]
#[CoversClass(EditorActionBatchPlanner::class)]
#[CoversClass(BatchScopeReader::class)]
final class BackgroundAltTextTest extends AbstractFunctionalTestCase
{
    use LlmServiceManagerTestFactory;

    private const TOOL = 'set_file_alternative_text';

    private const STORAGE_CONFIGURATION = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="basePath"><value index="vDEF">fileadmin/</value></field><field index="pathType"><value index="vDEF">relative</value></field><field index="caseSensitive"><value index="vDEF">1</value></field></language></sheet></data></T3FlexForms>';

    private const OWNER = 1;

    private const DRAFT = 1;

    /** Live metadata uids are the file uid plus this. */
    private const METADATA_OFFSET = 100;

    private const OTHER_DRAFT = 2;

    /** The demo's batch user, its group and its file mount: uid 994 each. */
    private const WORKER = 994;

    /**
     * The event stream of run 00241df2 on the demo (NEXT-168), as stored:
     * kind and payload of each row, in sequence.
     */
    private const RUN_00241DF2_EVENTS = [
        ['context', '{"kind":"context","round":1,"durationMs":0,"contextBudget":{"measured":true,"contextLength":1050000,"reservedOutput":128000,"safetyMargin":31500,"budget":890500,"transcriptTokens":221,"toolSchemaTokens":379,"systemPromptTokens":65,"systemPromptInTranscript":false,"skillTokens":0,"estimatedTokens":665,"remaining":889835},"contentRedacted":true}'],
        ['request', '{"kind":"request","round":1,"durationMs":0,"toolSpecs":["set_file_alternative_text"],"contentRedacted":true,"messagesSentCount":2}'],
        ['llm', '{"kind":"llm","round":1,"durationMs":3748.03,"finishReason":"tool_calls","promptTokens":1262,"completionTokens":88,"totalTokens":1350,"contentRedacted":true,"contentLength":0,"requestedToolNames":["set_file_alternative_text"]}'],
        ['approval', '{"approved":true,"decidedBy":2}'],
        ['tool', '{"kind":"tool","round":1,"durationMs":29.83,"toolName":"set_file_alternative_text","toolIsError":true,"toolOutcome":"failed","contentRedacted":true,"toolResultLength":94}'],
        ['context', '{"kind":"context","round":2,"durationMs":0,"contextBudget":{"measured":true,"contextLength":1050000,"reservedOutput":128000,"safetyMargin":31500,"budget":890500,"transcriptTokens":393,"toolSchemaTokens":378,"systemPromptTokens":65,"systemPromptInTranscript":false,"skillTokens":0,"estimatedTokens":836,"remaining":889664},"contentRedacted":true}'],
        ['request', '{"kind":"request","round":2,"durationMs":0,"toolSpecs":["set_file_alternative_text"],"contentRedacted":true,"messagesSentCount":4}'],
        ['llm', '{"kind":"llm","round":2,"durationMs":2761.72,"finishReason":"stop","promptTokens":1384,"completionTokens":26,"totalTokens":1410,"cachedPromptTokens":0,"httpMs":2738.73,"providerProcessingMs":2507,"contentRedacted":true,"contentLength":120}'],
    ];

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'workspaces'];

    private ConnectionPool $pool;

    private ToolRegistry $registry;

    private LlmConfiguration $configuration;

    private ScriptedToolAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->pool = $this->getService(ConnectionPool::class);

        $this->setUpBackendUser(self::OWNER);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/products/sub');
        $this->pool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', [
            'uid'           => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online'     => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);

        $this->addImage(1, '/products/empty.png', '');
        $this->addImage(2, '/products/sub/nested.png', '');
        $this->addImage(3, '/products/described.png', 'A text somebody wrote');

        $this->registry = new ToolRegistry([$this->tool()]);
        (new ToolStateRepository($this->pool))->setEnabled(self::TOOL, true);

        $provider = new Provider();
        $provider->setIdentifier('fake-provider');
        $provider->setAdapterType('openai');
        $provider->setTrustZoneEnum(TrustZone::LOCAL);
        $provider->setApiKey('nr_alt_text_key');

        $model = new Model();
        $model->setModelId('scripted-model');
        $model->setProvider($provider);

        $this->configuration = new LlmConfiguration();
        $this->configuration->setIdentifier('cfg-alt-text');
        $this->configuration->setLlmModel($model);

        $this->adapter = new ScriptedToolAdapter('Done.', self::TOOL, ['uid' => 1, 'alternative' => 'A red square']);

        $workspaces = $this->pool->getConnectionForTable('sys_workspace');
        $workspaces->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);
        $workspaces->insert('sys_workspace', ['uid' => self::OTHER_DRAFT, 'pid' => 0, 'title' => 'Other', 'members' => '']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aScheduledBatchProducesATextFromTheImageThatLandsOnlyAfterApproval(): void
    {
        $batch = new CommandTester($this->batchCommand());

        self::assertSame(Command::SUCCESS, $batch->execute([
            'action'   => self::TOOL,
            '--user'   => 'admin',
            '--folder' => '1:/products/',
        ]), $batch->getDisplay());
        self::assertStringContainsString('Queued 2, already filled 1,', $batch->getDisplay());

        // Queued, and nothing left the installation: the batch holds file uids.
        self::assertSame([], $this->adapter->sentMessages);
        $queued = $this->repository()->findQueued(10);
        self::assertCount(2, $queued);
        $stored = $this->pool->getConnectionForTable('tx_nrllm_agentrun')->select(['queued_request'], 'tx_nrllm_agentrun', ['uuid' => $queued[0]->uuid])->fetchOne();
        self::assertIsString($stored);
        self::assertLessThan(20000, strlen($stored));

        $runtime = $this->runtime();
        $worker  = new CommandTester(new WorkAgentQueueCommand(
            $this->persister(),
            $runtime,
            $this->getService(BudgetServiceInterface::class),
            self::createStub(LlmConfigurationRepository::class),
        ));

        self::assertSame(Command::SUCCESS, $worker->execute([]), $worker->getDisplay());

        // The model saw the image, as a data URI of its own message.
        $first = json_encode($this->adapter->sentMessages, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('data:image\/png;base64,', $first);

        // The draft: the run waits, and the field is still empty.
        $run = $this->repository()->findByUuid($queued[0]->uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $run->statusEnum(), $worker->getDisplay());
        self::assertSame('', $this->alternativeOf(1));

        $digest = (new WaitingRunViewFactory(
            $this->registry,
            new SchemaPropertyClassifier(),
            new PendingTurnDigest(),
            new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
        ))->buildWaiting([$run], $GLOBALS['BE_USER'])[0]->turnDigest;
        self::assertIsString($digest);

        $approved = $runtime->approve(AiActorContext::backendUser(self::OWNER, isAdmin: true), $run->uuid, new ApprovalDecision(true, self::OWNER, $digest));

        self::assertNull($approved->error, (string) $approved->error?->getMessage());
        self::assertSame('A red square', $this->alternativeOf(1));
    }

    /**
     * Repeating a scheduled batch queues nothing twice: a record with an open
     * run is skipped, and so is a record that has a text by then.
     */
    #[Test]
    public function aRepeatedBatchQueuesNothingTwice(): void
    {
        (new CommandTester($this->batchCommand()))->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/']);

        $again = new CommandTester($this->batchCommand());
        self::assertSame(Command::SUCCESS, $again->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/']));

        self::assertStringContainsString('Queued 0, already filled 1, skipped 2,', $again->getDisplay());
        self::assertCount(2, $this->repository()->findQueued(10));
    }

    #[Test]
    public function aRunWhoseUserWasDisabledFailsAndNoModelIsAsked(): void
    {
        (new CommandTester($this->batchCommand()))->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/sub/']);
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['disable' => 1], ['uid' => self::OWNER]);
        $queued = $this->repository()->findQueued(10);
        self::assertCount(1, $queued);

        $this->runtime()->runQueued($queued[0]->uuid);

        self::assertSame([], $this->adapter->sentMessages);
        self::assertSame(AgentRunStatus::FAILED, $this->repository()->findByUuid($queued[0]->uuid)?->statusEnum());
    }

    #[Test]
    public function refusesAnUnknownUserAndTheCliUser(): void
    {
        $this->pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 99, 'username' => '_cli_', 'admin' => 1]);

        foreach (['nobody', '_cli_', ''] as $user) {
            $tester = new CommandTester($this->batchCommand());
            self::assertSame(Command::INVALID, $tester->execute(['action' => self::TOOL, '--user' => $user, '--folder' => '1:/products/']), $user);
        }

        self::assertSame([], $this->repository()->findQueued(10));
    }

    #[Test]
    public function refusesAFolderThatCannotBeRead(): void
    {
        $tester = new CommandTester($this->batchCommand());

        self::assertSame(Command::INVALID, $tester->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/nowhere/']));
    }

    #[Test]
    public function refusesADepthThatIsNoNumber(): void
    {
        $this->pool->getConnectionForTable('pages')->insert('pages', ['uid' => 60, 'pid' => 0, 'title' => 'Root', 'doktype' => 1]);

        $tester = new CommandTester($this->batchCommand());

        self::assertSame(Command::INVALID, $tester->execute(['action' => self::TOOL, '--user' => 'admin', '--page-tree' => '60', '--depth' => 'foo']));
        self::assertStringContainsString('--depth must', $tester->getDisplay());
    }

    #[Test]
    public function needsExactlyOneScope(): void
    {
        $tester = new CommandTester($this->batchCommand());

        self::assertSame(Command::INVALID, $tester->execute(['action' => self::TOOL, '--user' => 'admin']));
        self::assertSame(Command::INVALID, $tester->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/', '--page-tree' => '1']));
    }

    #[Test]
    public function thePagesOfATreeAreThoseTheUserMayShowDownToTheDepth(): void
    {
        $connection = $this->pool->getConnectionForTable('pages');
        foreach ([[10, 0], [11, 10], [12, 11], [13, 12]] as [$uid, $pid]) {
            $connection->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => 'P' . $uid, 'doktype' => 1, 'perms_userid' => self::OWNER, 'perms_user' => 31]);
        }

        $reader = new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool);
        $user   = $GLOBALS['BE_USER'];

        self::assertSame([10], $reader->pagesIn(10, 0, $user));
        self::assertSame([10, 11, 12], $reader->pagesIn(10, 2, $user));
    }

    /**
     * An editor: uid 5 may show the tree and edit one branch of it, inside a
     * mount. What is left is what a writer could write.
     */
    #[Test]
    public function aTreeHoldsWhatTheUserMayEditInsideTheirMounts(): void
    {
        $connection = $this->pool->getConnectionForTable('pages');
        foreach ([
            [20, 0, 1, 31, 31], [21, 20, 1, 31, 1], [22, 21, 1, 31, 31], [23, 21, 254, 31, 31], [24, 21, 1, 0, 0],
            [30, 0, 1, 31, 31],
        ] as [$uid, $pid, $doktype, $user, $everybody]) {
            $connection->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => 'P' . $uid, 'doktype' => $doktype, 'perms_userid' => 5, 'perms_user' => $user, 'perms_everybody' => $everybody]);
        }

        $this->pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 7, 'username' => 'editor', 'admin' => 0, 'db_mountpoints' => '20', 'options' => 3]);
        $user = (new ActingBackendUserResolver())->resolve(AiActorContext::backendUser(7));
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        $reader = new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool);

        // 20 edit, 21 show only, 22 edit, 23 a folder, 24 hidden from the user.
        self::assertSame([20, 22], $reader->pagesIn(20, 9, $user));
        // Outside the mount, or a page that does not exist: refused, not empty.
        self::assertNull($reader->pagesIn(30, 9, $user));
        self::assertNull($reader->pagesIn(99999, 9, $user));
        // A user without the default language has no tree.
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['allowed_languages' => '1'], ['uid' => 7]);
        $translator = (new ActingBackendUserResolver())->resolve(AiActorContext::backendUser(7));
        self::assertInstanceOf(BackendUserAuthentication::class, $translator);
        self::assertSame(1, (int) $translator->checkLanguageAccess(1));
        self::assertNull($reader->pagesIn(20, 9, $translator));

        // Inside the mount, and not a page of the default language.
        $connection->insert('pages', ['uid' => 25, 'pid' => 20, 'title' => 'P25', 'doktype' => 1, 'sys_language_uid' => 1, 'perms_everybody' => 31]);
        self::assertNull($reader->pagesIn(25, 0, $user));
    }

    #[Test]
    public function aBatchEveryRecordOfWhichIsRefusedSaysSo(): void
    {
        (new ToolStateRepository($this->pool))->setEnabled(self::TOOL, false);

        $tester = new CommandTester($this->batchCommand());

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/']));
        self::assertStringContainsString('Every record was refused', $tester->getDisplay());
    }

    /**
     * A non-admin, through the real gate: a read-only mount and a writable one.
     */
    #[Test]
    public function aBatchLeavesOutFilesTheUserCannotEdit(): void
    {
        $mounts = $this->pool->getConnectionForTable('sys_filemounts');
        $mounts->insert('sys_filemounts', ['uid' => 1, 'title' => 'Read', 'identifier' => '1:/products/', 'read_only' => 1]);
        $mounts->insert('sys_filemounts', ['uid' => 2, 'title' => 'Write', 'identifier' => '1:/products/', 'read_only' => 0]);

        $users = $this->pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 7, 'username' => 'reader', 'admin' => 0, 'file_mountpoints' => '1', 'file_permissions' => 'readFolder,readFile', 'options' => 3]);
        $users->insert('be_users', ['uid' => 8, 'username' => 'writer', 'admin' => 0, 'file_mountpoints' => '2', 'file_permissions' => 'readFolder,readFile', 'options' => 3, 'allowed_languages' => '0']);
        $users->insert('be_users', ['uid' => 9, 'username' => 'translator', 'admin' => 0, 'file_mountpoints' => '2', 'file_permissions' => 'readFolder,readFile', 'options' => 3, 'allowed_languages' => '1']);

        $resolver = new ActingBackendUserResolver();
        $reader   = $resolver->resolve(AiActorContext::backendUser(7));
        $writer   = $resolver->resolve(AiActorContext::backendUser(8));
        self::assertInstanceOf(BackendUserAuthentication::class, $reader);
        self::assertInstanceOf(BackendUserAuthentication::class, $writer);
        $translator = $resolver->resolve(AiActorContext::backendUser(9));
        self::assertInstanceOf(BackendUserAuthentication::class, $translator);

        $builder = new VisionActionBuilder(
            new FalStorageGate([1], $this->getService(StorageRepository::class), $this->pool),
            $this->getService(ResourceFactory::class),
            new FalImageDownscaler($this->getService(Context::class)),
            new VisionSettings(),
        );

        self::assertFalse($builder->maySend(1, $reader));
        self::assertTrue($builder->maySend(1, $writer));
        // The writers refuse a user without the default language.
        self::assertFalse($builder->maySend(1, $translator));
    }

    #[Test]
    public function aDraftOfTheMetadataDoesNotMakeAFilledFileEmpty(): void
    {
        $this->pool->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', [
            'uid' => 90, 'pid' => 0, 'file' => 3, 'alternative' => '', 'sys_language_uid' => 0, 't3ver_wsid' => 1, 't3ver_oid' => self::METADATA_OFFSET + 3,
        ]);

        $reader = new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool);

        self::assertSame([], $reader->withEmptyFields('sys_file', [3], ['alternative']));
    }

    #[Test]
    public function aPageThatCannotBeReadIsRefused(): void
    {
        $tester = new CommandTester($this->batchCommand());

        self::assertSame(Command::INVALID, $tester->execute(['action' => self::TOOL, '--user' => 'admin', '--page-tree' => '99999']));
    }

    #[Test]
    public function anActionOrFieldThatDoesNotExistIsRefused(): void
    {
        $tester = new CommandTester($this->batchCommand());

        self::assertSame(Command::INVALID, $tester->execute(['action' => 'no_such_tool', '--user' => 'admin', '--folder' => '1:/products/']));
        self::assertSame(Command::INVALID, $tester->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/', '--only-empty' => 'titel']));
        self::assertSame([], $this->repository()->findQueued(10));
    }

    #[Test]
    public function aNamedFieldAddsToWhatAVisionActionChecks(): void
    {
        $this->pool->getConnectionForTable('sys_file_metadata')->update('sys_file_metadata', ['title' => 'Named'], ['file' => 2]);

        $tester = new CommandTester($this->batchCommand());
        $tester->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/', '--only-empty' => 'title']);

        self::assertStringContainsString('Queued 1, already filled 2,', $tester->getDisplay());
    }

    /**
     * A card somebody rejected must not come back on the next schedule, and a
     * run that failed says nothing about the record.
     */
    #[Test]
    public function aRepeatedBatchLeavesDecidedRecordsAndRetriesFailedOnes(): void
    {
        (new CommandTester($this->batchCommand()))->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/']);

        $connection = $this->pool->getConnectionForTable('tx_nrllm_agentrun');
        // What a rejection leaves when the model is asked again and stops.
        $connection->update('tx_nrllm_agentrun', ['status' => AgentRunStatus::COMPLETED->value, 'termination_reason' => AgentRunTerminationReason::COMPLETED->value], ['origin' => 'batch:' . self::TOOL . ':sys_file:1']);
        $connection->update('tx_nrllm_agentrun', ['status' => AgentRunStatus::FAILED->value, 'termination_reason' => AgentRunTerminationReason::PROVIDER_FAILED->value], ['origin' => 'batch:' . self::TOOL . ':sys_file:2']);

        $again = new CommandTester($this->batchCommand());
        $again->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/']);

        self::assertStringContainsString('Queued 1, already filled 1, skipped 1,', $again->getDisplay());
    }

    /**
     * The run stopped at the rejection: FAILED in its status, a decision in its
     * reason.
     */
    #[Test]
    public function aRejectionThatEndedTheRunHoldsItsRecord(): void
    {
        (new CommandTester($this->batchCommand()))->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/sub/']);
        $this->pool->getConnectionForTable('tx_nrllm_agentrun')->update('tx_nrllm_agentrun', ['status' => AgentRunStatus::FAILED->value, 'termination_reason' => AgentRunTerminationReason::APPROVAL_DENIED->value], ['origin' => 'batch:' . self::TOOL . ':sys_file:2']);

        $again = new CommandTester($this->batchCommand());
        $again->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/sub/']);

        self::assertStringContainsString('Queued 0,', $again->getDisplay());
    }

    /**
     * @return iterable<string, array{AgentRunStatus, AgentRunTerminationReason}>
     */
    public static function endsThatDecideNothing(): iterable
    {
        yield 'budget gone before the card' => [AgentRunStatus::COMPLETED, AgentRunTerminationReason::BUDGET_EXHAUSTED];
        yield 'cut short' => [AgentRunStatus::COMPLETED, AgentRunTerminationReason::CONTEXT_TRUNCATED];
        yield 'cancelled' => [AgentRunStatus::CANCELLED, AgentRunTerminationReason::CANCELLED];
    }

    #[Test]
    #[DataProvider('endsThatDecideNothing')]
    public function aRunThatDecidedNothingDoesNotHoldItsRecord(AgentRunStatus $status, AgentRunTerminationReason $reason): void
    {
        (new CommandTester($this->batchCommand()))->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/sub/']);
        $this->pool->getConnectionForTable('tx_nrllm_agentrun')->update('tx_nrllm_agentrun', ['status' => $status->value, 'termination_reason' => $reason->value], ['origin' => 'batch:' . self::TOOL . ':sys_file:2']);

        $again = new CommandTester($this->batchCommand());
        $again->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/sub/']);

        self::assertStringContainsString('Queued 1,', $again->getDisplay());
    }

    /**
     * Run 00241df2 on the demo (NEXT-168), its events as stored: approved by
     * user 2, the write refused, the model told and the run completed. The
     * approval decided nothing about the file, whose text is still empty, so
     * the next schedule offers it again.
     */
    #[Test]
    public function anApprovedWriteThatFailedDoesNotHoldItsRecord(): void
    {
        $this->addFinishedRun('batch:' . self::TOOL . ':sys_file:2', self::RUN_00241DF2_EVENTS);

        $again = new CommandTester($this->batchCommand());
        $again->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/sub/']);

        self::assertStringContainsString('Queued 1,', $again->getDisplay());
    }

    /**
     * @return iterable<string, array{list<array{string, string}>}>
     */
    public static function endsThatDecidedTheRecord(): iterable
    {
        $events = self::RUN_00241DF2_EVENTS;

        // The card turned down: the denied call is a failed step too.
        $denied       = $events;
        $denied[3][1] = '{"approved":false,"decidedBy":2}';
        yield 'denied' => [$denied];

        // The write took.
        $written       = $events;
        $written[4][1] = '{"kind":"tool","round":1,"durationMs":29.83,"toolName":"set_file_alternative_text","toolIsError":false,"toolOutcome":"ok","contentRedacted":true,"toolResultLength":40}';
        yield 'written' => [$written];

        // A call refused before the card, which the model then corrected.
        $corrected = [
            ['tool', '{"kind":"tool","round":1,"durationMs":3.1,"toolName":"set_file_alternative_text","toolIsError":true,"toolOutcome":"failed","contentRedacted":true,"toolResultLength":50}'],
            ...$written,
        ];
        yield 'failed before the approval' => [$corrected];
    }

    /**
     * @param list<array{string, string}> $events
     */
    #[Test]
    #[DataProvider('endsThatDecidedTheRecord')]
    public function aCompletedRunWhoseApprovedCallDidNotFailHoldsItsRecord(array $events): void
    {
        $this->addFinishedRun('batch:' . self::TOOL . ':sys_file:2', $events);

        $again = new CommandTester($this->batchCommand());
        $again->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/sub/']);

        self::assertStringContainsString('Queued 0,', $again->getDisplay());
    }

    /**
     * Cards wait for a person, so open runs use up the bound.
     */
    #[Test]
    public function openRunsCountTowardsTheBound(): void
    {
        $connection = $this->pool->getConnectionForTable('tx_nrllm_agentrun');
        for ($i = 0; $i < EditorActionBatchPlanner::MAX_BACKGROUND_RECORDS; ++$i) {
            $connection->insert('tx_nrllm_agentrun', ['uuid' => 'open-' . $i, 'status' => AgentRunStatus::WAITING_FOR_APPROVAL->value, 'origin' => 'batch:' . self::TOOL . ':sys_file:' . (1000 + $i)]);
        }

        $tester = new CommandTester($this->batchCommand());
        $tester->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/']);

        self::assertStringContainsString('Queued 0,', $tester->getDisplay());
    }

    /**
     * What the batch checked can change while the run waits.
     */
    #[Test]
    public function aFileThatCannotBeEditedAnyMoreFailsItsRun(): void
    {
        (new CommandTester($this->batchCommand()))->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/sub/']);
        $queued = $this->repository()->findQueued(10);
        self::assertCount(1, $queued);

        $users = self::createStub(ActingBackendUserResolverInterface::class);
        $user  = self::createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(true);
        $user->method('checkLanguageAccess')->willReturn(false);
        $users->method('resolve')->willReturn($user);

        $this->runtime($users)->runQueued($queued[0]->uuid);

        self::assertSame([], $this->adapter->sentMessages);
        self::assertSame(AgentRunStatus::FAILED, $this->repository()->findByUuid($queued[0]->uuid)?->statusEnum());
    }

    #[Test]
    public function aRunWithoutAnImageWhoseUserWasDisabledFailsToo(): void
    {
        $runtime = $this->runtime();
        $uuid    = $runtime->defer(new AgentRunRequest($this->configuration, [ChatMessage::user('Page text')], AiActorContext::backendUser(self::OWNER), origin: 'batch:x:pages:1'));
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['disable' => 1], ['uid' => self::OWNER]);

        $runtime->runQueued($uuid);

        self::assertSame([], $this->adapter->sentMessages);
        self::assertSame(AgentRunStatus::FAILED, $this->repository()->findByUuid($uuid)?->statusEnum());
    }

    /**
     * The content of a translated page sits on the default page, and an element
     * for all languages belongs to every translation.
     */
    #[Test]
    public function aTranslatedPageReadsItsContent(): void
    {
        $this->pool->getConnectionForTable('tt_content')->insert('tt_content', ['pid' => 50, 'CType' => 'text', 'sys_language_uid' => 1, 'bodytext' => 'Alte Rahmen']);
        $this->pool->getConnectionForTable('tt_content')->insert('tt_content', ['pid' => 50, 'CType' => 'text', 'sys_language_uid' => -1, 'bodytext' => 'For everybody']);
        $this->pool->getConnectionForTable('tt_content')->insert('tt_content', ['pid' => 50, 'CType' => 'text', 'sys_language_uid' => 0, 'bodytext' => 'Old frames']);
        $this->pool->getConnectionForTable('tt_content')->insert('tt_content', ['pid' => 50, 'CType' => 'html', 'sys_language_uid' => 1, 'bodytext' => 'Karte <script>var key = "abc";</script><style>p {}</style>']);
        $this->pool->getConnectionForTable('tt_content')->insert('tt_content', ['pid' => 50, 'CType' => 'text', 'sys_language_uid' => 1, 'bodytext' => 'Nur für Mitglieder', 'fe_group' => '3']);

        $text = (new RecordTextReader($this->pool))->textOf('pages', ['uid' => 51, 'l10n_parent' => 50, 'sys_language_uid' => 1, 'title' => 'Seite']);

        self::assertStringContainsString('Alte Rahmen', $text);
        self::assertStringContainsString('For everybody', $text);
        self::assertStringNotContainsString('Old frames', $text);
        self::assertStringContainsString('Karte', $text);
        self::assertStringNotContainsString('var key', $text);
        self::assertStringNotContainsString('p {}', $text);
        self::assertStringNotContainsString('Nur für Mitglieder', $text);
    }

    /**
     * Without the text the model has a uid and one writing tool.
     */
    #[Test]
    public function aQueuedRunOnAPageCarriesItsText(): void
    {
        $this->pool->getConnectionForTable('pages')->insert('pages', ['uid' => 40, 'pid' => 0, 'title' => 'Bicycles', 'doktype' => 1]);
        $this->pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 40, 'pid' => 40, 'CType' => 'text', 'bodytext' => '<p>Repairing old frames</p>']);

        $availability = self::createStub(ToolAvailabilityServiceInterface::class);
        $availability->method('editorActions')->willReturn(['update_page_metadata' => new EditorAction('label', 'description', 'icon', ['pages'])]);
        $availability->method('states')->willReturn([]);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($this->configuration);

        $policy = self::createStub(ToolCallPolicyInterface::class);
        $policy->method('decide')->willReturn(new ToolPolicyDecision('update_page_metadata', true, ToolDataClass::PUBLIC_CONTENT, TrustZone::LOCAL, ToolDataClass::SECRET_ADJACENT));

        $request = (new EditorActionCatalogue(
            $availability,
            $policy,
            $repository,
            self::createStub(LlmConfigurationServiceInterface::class),
            $this->vision(),
            new ConfigurationResolver(),
            new RecordTextReader($this->pool),
        ))->backgroundRequestFor('update_page_metadata', 'pages', 40, '', AiActorContext::backendUser(self::OWNER, true), $GLOBALS['BE_USER'], 'batch:x');

        self::assertNotNull($request);
        $prompt = json_encode($request->messages, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Repairing old frames', $prompt);
        self::assertStringNotContainsString('<p>', $prompt);
    }

    /**
     * The bound is one batch's, and the rest is reported rather than planned
     * (ADR-236).
     */
    #[Test]
    public function aBatchQueuesUpToTheBoundAndReportsTheRest(): void
    {
        $over       = EditorActionBatchPlanner::MAX_BACKGROUND_RECORDS + 3;
        $connection = $this->pool->getConnectionForTable('pages');
        $connection->insert('pages', ['uid' => 1000, 'pid' => 0, 'title' => 'Root', 'doktype' => 1, 'perms_userid' => self::OWNER, 'perms_user' => 31]);
        for ($i = 1; $i < $over; ++$i) {
            $connection->insert('pages', ['uid' => 1000 + $i, 'pid' => 1000, 'title' => 'P' . $i, 'doktype' => 1, 'perms_userid' => self::OWNER, 'perms_user' => 31]);
        }

        $catalogue = self::createStub(EditorActionCatalogueInterface::class);
        $catalogue->method('actionFor')->willReturn(new EditorAction('label', 'description', 'icon', ['pages']));
        $catalogue->method('backgroundRequestFor')->willReturn(new AgentRunRequest($this->configuration, [], AiActorContext::backendUser(self::OWNER)));

        $plan = (new EditorActionBatchPlanner(
            $catalogue,
            $this->registry,
            new TranscriptEstimator(),
            new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool),
            $this->persister(),
        ))->planBackground(self::TOOL, 'pages', '1000', 1, '', '', AiActorContext::backendUser(self::OWNER), $GLOBALS['BE_USER']);

        self::assertNotNull($plan);
        self::assertCount(EditorActionBatchPlanner::MAX_BACKGROUND_RECORDS, $plan->entries);
        self::assertSame(3, $plan->beyondCap);
    }

    /**
     * A folder that starts with documents still reaches its images.
     */
    #[Test]
    public function aRefusedRecordTakesNoPlaceBelowTheBound(): void
    {
        $connection = $this->pool->getConnectionForTable('pages');
        $connection->insert('pages', ['uid' => 1000, 'pid' => 0, 'title' => 'Root', 'doktype' => 1, 'perms_userid' => self::OWNER, 'perms_user' => 31]);

        $refused = EditorActionBatchPlanner::MAX_BACKGROUND_RECORDS + 1;
        for ($i = 1; $i <= $refused + 1; ++$i) {
            $connection->insert('pages', ['uid' => 1000 + $i, 'pid' => 1000, 'title' => 'P' . $i, 'doktype' => 1, 'perms_userid' => self::OWNER, 'perms_user' => 31]);
        }

        $request   = new AgentRunRequest($this->configuration, [], AiActorContext::backendUser(self::OWNER));
        $catalogue = self::createStub(EditorActionCatalogueInterface::class);
        $catalogue->method('actionFor')->willReturn(new EditorAction('label', 'description', 'icon', ['pages']));
        $catalogue->method('backgroundRequestFor')->willReturnCallback(
            static fn (string $tool, string $table, int $uid): ?AgentRunRequest => $uid > 1000 + $refused ? $request : null,
        );

        $plan = (new EditorActionBatchPlanner(
            $catalogue,
            $this->registry,
            new TranscriptEstimator(),
            new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool),
            $this->persister(),
        ))->planBackground(self::TOOL, 'pages', '1000', 1, '', '', AiActorContext::backendUser(self::OWNER), $GLOBALS['BE_USER']);

        self::assertNotNull($plan);
        self::assertCount(1, array_filter($plan->entries, static fn (EditorActionBatchEntry $entry): bool => $entry->request instanceof AgentRunRequest));
    }

    /**
     * A file with one of the fields written would get a proposal to replace it.
     */
    #[Test]
    public function aRecordCountsAsEmptyOnlyWhenAllItsFieldsAre(): void
    {
        $this->pool->getConnectionForTable('sys_file_metadata')->update('sys_file_metadata', ['description' => 'Written'], ['file' => 2]);

        $reader = new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool);

        self::assertSame([1], $reader->withEmptyFields('sys_file', [1, 2], ['title', 'description']));
    }

    #[Test]
    public function anEmptyFieldIsAlsoOneThatWasNeverWritten(): void
    {
        $this->pool->getConnectionForTable('sys_file_metadata')->update('sys_file_metadata', ['alternative' => null], ['file' => 2]);

        $reader = new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool);

        self::assertSame([1, 2], $reader->withEmptyFields('sys_file', [1, 2, 3], ['alternative']));
    }

    /**
     * A relation field stores the number of its relations, and 0 is empty
     * (ADR-268). Compared with '' instead, SQLite finds no file empty and
     * PostgreSQL refuses the query.
     */
    #[Test]
    public function aRelationFieldIsEmptyAtNoRelation(): void
    {
        $this->pool->getConnectionForTable('sys_file_metadata')->update('sys_file_metadata', ['categories' => 2], ['file' => 2]);

        $reader = new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool);

        self::assertSame([1, 3], $reader->withEmptyFields('sys_file', [1, 2, 3], ['categories']));
    }

    #[Test]
    public function aFieldTheTableDoesNotHaveIsRefused(): void
    {
        $reader = new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool);

        self::assertNull($reader->withEmptyFields('sys_file', [1, 2, 3], ['no_such_column']));
    }

    /**
     * A schedule is started in no workspace (ADR-265): the user's record names
     * a draft, and the batch still reads, records and writes live.
     */
    #[Test]
    public function aScheduledBatchStaysLiveWhicheverWorkspaceItsUserLastWorkedIn(): void
    {
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['workspace_id' => self::DRAFT], ['uid' => self::OWNER]);
        self::assertSame(self::DRAFT, (new ActingBackendUserResolver())->resolve(AiActorContext::backendUser(self::OWNER))?->workspace, 'positive control: the user comes up in the draft');
        // The draft empties the text of file 3; live, it holds one.
        $this->addMetadataVersion(3, self::DRAFT, '');

        $batch = new CommandTester($this->batchCommand());
        self::assertSame(Command::SUCCESS, $batch->execute(['action' => self::TOOL, '--user' => 'admin', '--folder' => '1:/products/']), $batch->getDisplay());
        self::assertStringContainsString('Queued 2, already filled 1,', $batch->getDisplay());

        $queued = $this->repository()->findQueued(10);
        self::assertCount(2, $queued);
        foreach ($queued as $run) {
            self::assertSame(0, $this->recordedWorkspaceOf($run->uuid));
        }

        self::assertSame(self::DRAFT, (int) $this->pool->getConnectionForTable('be_users')->select(['workspace_id'], 'be_users', ['uid' => self::OWNER])->fetchOne(), "The user's own workspace is left as it is.");
    }

    #[Test]
    public function aScheduledBatchRefusesAUserWhoMayNotWorkInLive(): void
    {
        $this->pool->getConnectionForTable('sys_workspace')->update('sys_workspace', ['members' => 'be_users_7'], ['uid' => self::DRAFT]);
        $this->pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 7, 'username' => 'drafter', 'admin' => 0, 'workspace_perms' => 0, 'workspace_id' => self::DRAFT,
        ]);
        self::assertSame(self::DRAFT, (new ActingBackendUserResolver())->resolve(AiActorContext::backendUser(7))?->workspace, 'positive control: the user may work in the draft');

        $batch = new CommandTester($this->batchCommand());

        self::assertSame(Command::INVALID, $batch->execute(['action' => self::TOOL, '--user' => 'drafter', '--folder' => '1:/products/']));
        self::assertStringContainsString('may work in the live', $batch->getDisplay());
        self::assertSame([], $this->repository()->findQueued(10));
    }

    /**
     * NEXT-168, run 00241df2 on the demo: the batch user with the rights of
     * the seeded group "AI alternative texts" and no right to work in live, a
     * run queued before runs recorded their workspace, a card approved by
     * another user. Core sets such a user up in no workspace at all (-99), so
     * the write is refused after the approval and the text stays empty. The
     * refusal names that cause, not a draft workspace the user never had.
     */
    #[Test]
    public function anOldRunWhoseUserMayWorkInNoWorkspaceIsRefusedAtTheWriteForThatReason(): void
    {
        $this->addBatchWorker(0);
        $actor = AiActorContext::backendUser(self::WORKER);
        self::assertSame(-99, (new ActingBackendUserResolver())->resolve($actor)?->workspace, 'positive control: core finds no workspace for the user');

        $approved = $this->approveTheBatchCardOf($actor);

        $tool = $this->toolStepsOf($approved);
        self::assertCount(1, $tool);
        self::assertTrue($tool[0]->toolIsError);
        self::assertStringContainsString('may not work in the live workspace', (string) $tool[0]->toolResult);
        self::assertStringNotContainsString('draft workspace', (string) $tool[0]->toolResult);
        self::assertSame('', $this->alternativeOf(2));
        self::assertSame(AgentRunStatus::COMPLETED, $this->repository()->findByUuid($approved->runUuid)?->statusEnum());
    }

    /**
     * The demo since typo3-demo#497: the same group grants live. A card
     * queued before runs recorded their workspace — the three still open on
     * the demo — writes when it is approved.
     */
    #[Test]
    public function anOldRunWhoseUserMayWorkInLiveWritesWhenApproved(): void
    {
        $this->addBatchWorker(1);
        $actor = AiActorContext::backendUser(self::WORKER);
        self::assertSame(0, (new ActingBackendUserResolver())->resolve($actor)?->workspace);

        $approved = $this->approveTheBatchCardOf($actor);

        self::assertNull($approved->error, (string) $approved->error?->getMessage());
        $tool = $this->toolStepsOf($approved);
        self::assertCount(1, $tool);
        self::assertFalse((bool) $tool[0]->toolIsError, (string) $tool[0]->toolResult);
        self::assertSame('A red square', $this->alternativeOf(2));
    }

    /**
     * Started from the module in a draft, the batch reads what the draft
     * shows: a version that holds a text fills its file, a version that
     * emptied it empties it, and another workspace's draft counts for nothing.
     */
    #[Test]
    public function aBatchInADraftCountsTheTextsTheDraftShows(): void
    {
        $this->addMetadataVersion(1, self::DRAFT, 'Written in the draft');
        $this->addMetadataVersion(3, self::DRAFT, '');
        $this->addMetadataVersion(2, self::OTHER_DRAFT, 'Written in another workspace');

        $reader = new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool);

        self::assertSame([1, 2], $reader->withEmptyFields('sys_file', [1, 2, 3], ['alternative']), 'positive control: live');
        self::assertSame([2, 3], $reader->withEmptyFields('sys_file', [1, 2, 3], ['alternative'], self::DRAFT));
    }

    /**
     * The tree as the draft shows it: a page new there, a page moved into the
     * tree there (under its live uid 65), a page deleted there gone, another
     * workspace's page absent.
     */
    #[Test]
    public function aTreeInADraftIsTheTreeTheDraftShows(): void
    {
        $this->addTreeWithDrafts();
        $reader = new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool);
        $user   = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        self::assertSame([60, 61, 63, 68], $reader->pagesIn(60, 1, $user), 'positive control: live');
        self::assertSame([60, 61, 62, 65], $reader->pagesIn(60, 1, $user, self::DRAFT));
        // A level further down, through the page moved there.
        self::assertSame([60, 61, 63, 68], $reader->pagesIn(60, 2, $user), 'positive control: live');
        self::assertSame([60, 61, 62, 65, 67], $reader->pagesIn(60, 2, $user, self::DRAFT));

        // The draft's version of 61 holds a description; live, it is empty.
        self::assertSame([60, 61], $reader->withEmptyFields('pages', [60, 61], ['description']), 'positive control: live');
        self::assertSame([60, 62, 65], $reader->withEmptyFields('pages', [60, 61, 62, 65], ['description'], self::DRAFT));
    }

    /**
     * Whether a page may be edited is the live page's question, as in core's
     * page tree: a version keeps the permissions it was created with.
     */
    #[Test]
    public function aPageInADraftIsEditableAsItsLivePageIs(): void
    {
        $connection = $this->pool->getConnectionForTable('pages');
        foreach ([
            // uid, pid, perms_user of the live page, perms_user of its draft
            [70, 0, 31, 31], [71, 70, 31, 0], [72, 70, 1, 31],
        ] as [$uid, $pid, $live, $draft]) {
            $connection->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => 'P' . $uid, 'doktype' => 1, 'perms_userid' => 7, 'perms_user' => $live]);
            $connection->insert('pages', [
                'uid'        => $uid + 100, 'pid' => $pid, 'title' => 'P' . $uid . ' draft', 'doktype' => 1, 'perms_userid' => 7, 'perms_user' => $draft,
                't3ver_wsid' => self::DRAFT, 't3ver_oid' => $uid,
            ]);
        }

        $this->pool->getConnectionForTable('sys_workspace')->update('sys_workspace', ['members' => 'be_users_7'], ['uid' => self::DRAFT]);
        $this->pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 7, 'username' => 'drafter', 'admin' => 0, 'db_mountpoints' => '70', 'options' => 3, 'workspace_perms' => 0]);
        $user = (new ActingBackendUserResolver())->resolve(AiActorContext::backendUser(7, workspaceId: self::DRAFT));
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        self::assertSame(self::DRAFT, $user->workspace, 'positive control: the editor works in the draft');

        $reader = new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool);

        self::assertSame([70, 71], $reader->pagesIn(70, 1, $user, self::DRAFT));
    }

    /**
     * From the module in a draft: each record's request carries the actor that
     * recorded the draft, and a page's run reads the page as the draft shows
     * it.
     */
    #[Test]
    public function aBatchInADraftRecordsTheDraftAndReadsThePageThere(): void
    {
        $this->addTreeWithDrafts();
        $content = $this->pool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 60, 'pid' => 61, 'CType' => 'text', 'bodytext' => '<p>Old frames, in live</p>']);
        $content->insert('tt_content', ['uid' => 160, 'pid' => 61, 'CType' => 'text', 'bodytext' => '<p>Tandems, in the draft</p>', 't3ver_wsid' => self::DRAFT, 't3ver_oid' => 60]);
        $content->insert('tt_content', ['uid' => 161, 'pid' => 61, 'CType' => 'text', 'bodytext' => '<p>A secret of another workspace</p>', 't3ver_wsid' => self::OTHER_DRAFT, 't3ver_state' => 1]);

        $user = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));
        self::assertSame(self::DRAFT, $user->workspace, 'positive control: the editor works in the draft');
        $actor = AiActorContext::fromBackendUser($user);
        self::assertSame(self::DRAFT, $actor->workspaceId);

        $asked     = [];
        $catalogue = self::createStub(EditorActionCatalogueInterface::class);
        $catalogue->method('actionFor')->willReturn(new EditorAction('label', 'description', 'icon', ['pages']));
        $catalogue->method('backgroundRequestFor')->willReturnCallback(
            function (string $tool, string $table, int $uid, string $instruction, AiActorContext $actor) use (&$asked): AgentRunRequest {
                $asked[$uid] = $actor->workspaceId;

                return new AgentRunRequest($this->configuration, [], $actor);
            },
        );

        $plan = (new EditorActionBatchPlanner(
            $catalogue,
            $this->registry,
            new TranscriptEstimator(),
            new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool),
            $this->persister(),
        ))->planBackground(self::TOOL, 'pages', '60', 1, 'description', '', $actor, $user);

        self::assertNotNull($plan);
        self::assertSame([60 => self::DRAFT, 62 => self::DRAFT, 65 => self::DRAFT], $asked);

        $prompt = json_encode($this->pageCatalogue()->backgroundRequestFor('update_page_metadata', 'pages', 61, '', $actor, $user, 'batch:x')?->messages, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Tandems, in the draft', $prompt);
        self::assertStringContainsString('P61 in the draft', $prompt);
        self::assertStringNotContainsString('Old frames', $prompt);
        self::assertStringNotContainsString('another workspace', $prompt);

        // A page new in the draft is read there too.
        $new = json_encode($this->pageCatalogue()->backgroundRequestFor('update_page_metadata', 'pages', 62, '', $actor, $user, 'batch:x')?->messages, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('P62', $new);

        // The same page, asked by an actor that recorded no workspace: live.
        $live = json_encode($this->pageCatalogue()->backgroundRequestFor('update_page_metadata', 'pages', 61, '', AiActorContext::backendUser(self::OWNER, true), $user, 'batch:x')?->messages, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Old frames', $live);
        self::assertStringNotContainsString('Tandems', $live);
    }

    /**
     * A card decided in a draft does not keep its record out of a live
     * schedule, nor the reverse (ADR-265): the runs are kept per workspace.
     */
    #[Test]
    public function aBatchDecidedInADraftDoesNotHoldItsRecordsInLive(): void
    {
        $user = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));
        $draft = AiActorContext::fromBackendUser($user);

        $catalogue = self::createStub(EditorActionCatalogueInterface::class);
        $catalogue->method('actionFor')->willReturn(new EditorAction('label', 'description', 'icon', ['sys_file']));
        $catalogue->method('backgroundRequestFor')->willReturnCallback(
            fn (string $tool, string $table, int $uid, string $instruction, AiActorContext $actor, BackendUserAuthentication $user, string $origin): AgentRunRequest => new AgentRunRequest($this->configuration, [ChatMessage::user('x')], $actor, origin: $origin),
        );
        $planner = new EditorActionBatchPlanner(
            $catalogue,
            $this->registry,
            new TranscriptEstimator(),
            new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool),
            $this->persister(),
        );

        $plan = $planner->planBackground(self::TOOL, 'sys_file', '1:/products/', 0, 'alternative', '', $draft, $user);
        self::assertNotNull($plan);
        $runtime = $this->runtime();
        foreach ($plan->entries as $entry) {
            self::assertInstanceOf(AgentRunRequest::class, $entry->request);
            $runtime->defer($entry->request);
        }

        self::assertSame(['batch@1:' . self::TOOL . ':sys_file:1', 'batch@1:' . self::TOOL . ':sys_file:2'], $this->origins());
        $this->pool->getConnectionForTable('tx_nrllm_agentrun')->update('tx_nrllm_agentrun', ['status' => AgentRunStatus::COMPLETED->value, 'termination_reason' => AgentRunTerminationReason::COMPLETED->value], []);

        // Decided in the draft: held there.
        $again = $planner->planBackground(self::TOOL, 'sys_file', '1:/products/', 0, 'alternative', '', $draft, $user);
        self::assertNotNull($again);
        self::assertSame([], array_filter($again->entries, static fn (EditorActionBatchEntry $entry): bool => $entry->isRunnable()));

        // Live, the same files are still to do.
        self::assertTrue($user->setTemporaryWorkspace(0));
        $live = $planner->planBackground(self::TOOL, 'sys_file', '1:/products/', 0, 'alternative', '', AiActorContext::fromBackendUser($user), $user);
        self::assertNotNull($live);
        self::assertCount(2, array_filter($live->entries, static fn (EditorActionBatchEntry $entry): bool => $entry->isRunnable()));
    }

    /**
     * Queued in a draft from the module, approved after the owner moved on:
     * the text lands as a version in the draft, live and the other workspace
     * stay as they were.
     */
    #[Test]
    public function aBatchQueuedInADraftWritesThereWhereverItsOwnerWorksAtApproval(): void
    {
        $user = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($this->configuration);
        $planner = new EditorActionBatchPlanner(
            new EditorActionCatalogue($this->availability(), $this->policy(), $repository, self::createStub(LlmConfigurationServiceInterface::class), $this->vision(), new ConfigurationResolver()),
            $this->registry,
            new TranscriptEstimator(),
            new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool),
            $this->persister(),
        );
        $plan = $planner->planBackground(self::TOOL, 'sys_file', '1:/products/', 0, '', '', AiActorContext::fromBackendUser($user), $user);
        self::assertNotNull($plan);
        $runtime = $this->runtime();
        foreach ($plan->entries as $entry) {
            if ($entry->request instanceof AgentRunRequest) {
                $runtime->defer($entry->request);
            }
        }

        // The owner works elsewhere by the time the worker runs and the card is decided.
        $this->pool->getConnectionForTable('be_users')->update('be_users', ['workspace_id' => self::OTHER_DRAFT], ['uid' => self::OWNER]);

        $worker = new CommandTester(new WorkAgentQueueCommand($this->persister(), $runtime, $this->getService(BudgetServiceInterface::class), self::createStub(LlmConfigurationRepository::class)));
        self::assertSame(Command::SUCCESS, $worker->execute([]), $worker->getDisplay());

        $uuid = $this->pool->getConnectionForTable('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['origin' => 'batch@1:' . self::TOOL . ':sys_file:1'])->fetchOne();
        self::assertIsString($uuid);
        $run = $this->repository()->findByUuid($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $run->statusEnum(), $worker->getDisplay());

        $digest = (new WaitingRunViewFactory(
            $this->registry,
            new SchemaPropertyClassifier(),
            new PendingTurnDigest(),
            new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
        ))->buildWaiting([$run], $user)[0]->turnDigest;
        self::assertIsString($digest);

        $approved = $runtime->approve(AiActorContext::backendUser(self::OWNER, isAdmin: true), $run->uuid, new ApprovalDecision(true, self::OWNER, $digest));
        self::assertNull($approved->error, (string) $approved->error?->getMessage());

        $version = $this->pool->getConnectionForTable('sys_file_metadata')->select(['*'], 'sys_file_metadata', ['t3ver_oid' => self::METADATA_OFFSET + 1, 't3ver_wsid' => self::DRAFT])->fetchAssociative();
        self::assertIsArray($version, 'A version in the draft the batch was queued in.');
        self::assertSame('A red square', $version['alternative']);
        self::assertSame('', $this->alternativeOf(1), 'Live stays as it was.');
        self::assertSame(0, $this->pool->getConnectionForTable('sys_file_metadata')->count('uid', 'sys_file_metadata', ['t3ver_wsid' => self::OTHER_DRAFT]));
    }

    /**
     * The demo's batch user (typo3-demo `data/seed-extensions.sql`): group 994
     * "AI alternative texts" with one writable file mount, read access to files
     * and metadata, `sys_file_metadata` to modify, and no exclude fields; the
     * user holds no workspace right of its own and inherits the mounts.
     */
    private function addBatchWorker(int $groupWorkspacePerms): void
    {
        $this->pool->getConnectionForTable('sys_filemounts')->insert('sys_filemounts', [
            'uid' => self::WORKER, 'pid' => 0, 'title' => 'AI alternative texts', 'identifier' => '1:/products/', 'read_only' => 0,
        ]);
        $this->pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid'              => self::WORKER, 'pid' => 0, 'title' => 'AI alternative texts',
            'file_mountpoints' => (string) self::WORKER, 'file_permissions' => 'readFolder,readFile',
            'tables_select'    => 'sys_file,sys_file_metadata', 'tables_modify' => 'sys_file_metadata',
            'workspace_perms'  => $groupWorkspacePerms,
        ]);
        $this->pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid'       => self::WORKER, 'pid' => 0, 'username' => 'nr_llm_worker', 'admin' => 0,
            'usergroup' => (string) self::WORKER, 'options' => 3, 'workspace_perms' => 0,
        ]);
    }

    /**
     * Queue the batch over `products/sub/` (file 2) for `$actor`, let the
     * worker produce the card, and approve it as the admin, who is not the
     * run's user — the sequence of run 00241df2.
     */
    private function approveTheBatchCardOf(AiActorContext $actor): AgentRunResult
    {
        $user = (new ActingBackendUserResolver())->resolve($actor);
        self::assertInstanceOf(BackendUserAuthentication::class, $user);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($this->configuration);
        $planner = new EditorActionBatchPlanner(
            new EditorActionCatalogue($this->availability(), $this->policy(), $repository, self::createStub(LlmConfigurationServiceInterface::class), $this->vision(), new ConfigurationResolver()),
            $this->registry,
            new TranscriptEstimator(),
            new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool),
            $this->persister(),
        );
        $plan = $planner->planBackground(self::TOOL, 'sys_file', '1:/products/sub/', 0, '', '', $actor, $user);
        self::assertNotNull($plan);
        $runtime = $this->runtime();
        foreach ($plan->entries as $entry) {
            if ($entry->request instanceof AgentRunRequest) {
                $runtime->defer($entry->request);
            }
        }

        $this->adapter = new ScriptedToolAdapter('Done.', self::TOOL, ['uid' => 2, 'alternative' => 'A red square']);
        $runtime       = $this->runtime();
        $worker        = new CommandTester(new WorkAgentQueueCommand($this->persister(), $runtime, $this->getService(BudgetServiceInterface::class), self::createStub(LlmConfigurationRepository::class)));
        self::assertSame(Command::SUCCESS, $worker->execute([]), $worker->getDisplay());

        $uuid = $this->pool->getConnectionForTable('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['origin' => 'batch:' . self::TOOL . ':sys_file:2'])->fetchOne();
        self::assertIsString($uuid);
        $run = $this->repository()->findByUuid($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertSame(AgentRunStatus::WAITING_FOR_APPROVAL, $run->statusEnum(), $worker->getDisplay());

        $digest = (new WaitingRunViewFactory(
            $this->registry,
            new SchemaPropertyClassifier(),
            new PendingTurnDigest(),
            new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
        ))->buildWaiting([$run], $GLOBALS['BE_USER'])[0]->turnDigest;
        self::assertIsString($digest);

        return $runtime->approve(AiActorContext::backendUser(self::OWNER, isAdmin: true), $run->uuid, new ApprovalDecision(true, self::OWNER, $digest));
    }

    /**
     * A run that completed as run 00241df2 did, with `$events` as its stored
     * event stream (kind and payload, in sequence).
     *
     * @param list<array{string, string}> $events
     */
    private function addFinishedRun(string $origin, array $events): void
    {
        $runs = $this->pool->getConnectionForTable('tx_nrllm_agentrun');
        $runs->insert('tx_nrllm_agentrun', [
            'uuid'               => '00241df2-aaf0-4648-9334-2e9cb86d718d', 'status' => AgentRunStatus::COMPLETED->value,
            'termination_reason' => AgentRunTerminationReason::COMPLETED->value, 'be_user' => self::WORKER, 'origin' => $origin,
        ]);
        $run = (int) $runs->lastInsertId();

        foreach ($events as $sequence => [$kind, $payload]) {
            $this->pool->getConnectionForTable('tx_nrllm_agentrun_event')->insert('tx_nrllm_agentrun_event', [
                'run' => $run, 'sequence' => $sequence, 'kind' => $kind, 'payload' => $payload,
            ]);
        }
    }

    /**
     * @return list<RunStep>
     */
    private function toolStepsOf(AgentRunResult $result): array
    {
        return array_values(array_filter($result->steps, static fn (RunStep $step): bool => $step->kind === RunStep::KIND_TOOL));
    }

    /**
     * @return list<string>
     */
    private function origins(): array
    {
        $origins = $this->pool->getConnectionForTable('tx_nrllm_agentrun')->select(['origin'], 'tx_nrllm_agentrun', [], [], ['origin' => 'ASC'])->fetchFirstColumn();

        return array_values(array_map(static fn (mixed $origin): string => is_string($origin) ? $origin : '', $origins));
    }

    /**
     * Pages 60..66: 61 live with a draft version holding a description, 62
     * new in the draft, 63 deleted in the draft, 64 new in another workspace,
     * 65 live outside the tree and moved into it in the draft (its version
     * 66, reported under 65), with 67 below it. 68 is a page in live and a
     * folder in the draft, 69 a translation of 61 new in the draft.
     */
    private function addTreeWithDrafts(): void
    {
        $connection = $this->pool->getConnectionForTable('pages');
        $page       = static fn (int $uid, int $pid, int $workspace = 0, int $original = 0, int $state = 0, string $title = '', string $description = ''): array => [
            'uid'          => $uid, 'pid' => $pid, 'title' => $title !== '' ? $title : 'P' . $uid, 'doktype' => 1,
            'perms_userid' => self::OWNER, 'perms_user' => 31, 'description' => $description,
            't3ver_wsid'   => $workspace, 't3ver_oid' => $original, 't3ver_state' => $state,
        ];

        foreach ([
            $page(60, 0), $page(61, 60), $page(63, 60), $page(65, 0), $page(67, 65), $page(68, 60),
            $page(161, 60, self::DRAFT, 61, 0, 'P61 in the draft', 'Written in the draft'),
            $page(62, 60, self::DRAFT, 0, 1),
            $page(163, 60, self::DRAFT, 63, 2),
            $page(64, 60, self::OTHER_DRAFT, 0, 1),
            $page(66, 60, self::DRAFT, 65, 4),
            // A folder only in the draft, and a translation new there.
            ['doktype' => 254] + $page(168, 60, self::DRAFT, 68),
            ['sys_language_uid' => 1, 'l10n_parent' => 61] + $page(69, 60, self::DRAFT, 0, 1),
        ] as $row) {
            $connection->insert('pages', $row);
        }
    }

    private function pageCatalogue(): EditorActionCatalogue
    {
        $availability = self::createStub(ToolAvailabilityServiceInterface::class);
        $availability->method('editorActions')->willReturn(['update_page_metadata' => new EditorAction('label', 'description', 'icon', ['pages'])]);
        $availability->method('states')->willReturn([]);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($this->configuration);

        $policy = self::createStub(ToolCallPolicyInterface::class);
        $policy->method('decide')->willReturn(new ToolPolicyDecision('update_page_metadata', true, ToolDataClass::PUBLIC_CONTENT, TrustZone::LOCAL, ToolDataClass::SECRET_ADJACENT));

        return new EditorActionCatalogue(
            $availability,
            $policy,
            $repository,
            self::createStub(LlmConfigurationServiceInterface::class),
            $this->vision(),
            new ConfigurationResolver(),
            new RecordTextReader($this->pool),
        );
    }

    private function addMetadataVersion(int $file, int $workspace, string $alternative): void
    {
        $this->pool->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', [
            'pid'        => 0, 'file' => $file, 'alternative' => $alternative, 'sys_language_uid' => 0,
            't3ver_wsid' => $workspace, 't3ver_oid' => self::METADATA_OFFSET + $file,
        ]);
    }

    private function recordedWorkspaceOf(string $uuid): ?int
    {
        $run = $this->repository()->findByUuid($uuid);
        self::assertInstanceOf(AgentRun::class, $run);
        self::assertIsString($run->queuedRequest);

        $request = json_decode($run->queuedRequest, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($request);
        $actor = $request['actor'] ?? null;
        self::assertIsArray($actor);
        $workspace = $actor['workspaceId'] ?? null;

        return is_int($workspace) ? $workspace : null;
    }

    private function addImage(int $uid, string $identifier, string $alternative): void
    {
        $image = imagecreatetruecolor(64, 48);
        self::assertNotFalse($image);
        self::assertTrue(imagepng($image, $this->instancePath . '/fileadmin' . $identifier));

        $this->pool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid'             => $uid, 'pid' => 0, 'storage' => 1, 'identifier' => $identifier,
            'identifier_hash' => sha1($identifier), 'folder_hash' => sha1(dirname($identifier) . '/'), // NOSONAR php:S4790 — the hash TYPO3 stores for an identifier
            'name'            => basename($identifier), 'extension' => 'png', 'mime_type' => 'image/png', 'type' => 2, 'size' => 100,
        ]);
        // Its own uid, apart from the file's: a mix-up of the two must show.
        $this->pool->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', [
            'uid' => self::METADATA_OFFSET + $uid, 'pid' => 0, 'file' => $uid, 'alternative' => $alternative, 'sys_language_uid' => 0,
        ]);
    }

    private function alternativeOf(int $file): string
    {
        $value = $this->pool->getConnectionForTable('sys_file_metadata')->select(['alternative'], 'sys_file_metadata', ['file' => $file, 't3ver_wsid' => 0])->fetchOne();

        return is_string($value) ? $value : '';
    }

    private function tool(): SetFileAlternativeTextTool
    {
        return new SetFileAlternativeTextTool(
            $this->pool,
            $this->gate(),
            new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
        );
    }

    private function gate(): FalStorageGate
    {
        return new FalStorageGate([1], $this->getService(StorageRepository::class), $this->pool);
    }

    private function vision(): VisionActionBuilder
    {
        return new VisionActionBuilder($this->gate(), $this->getService(ResourceFactory::class), new FalImageDownscaler($this->getService(Context::class)), new VisionSettings());
    }

    private function policy(): ToolCallPolicy
    {
        return new ToolCallPolicy(
            $this->registry,
            $this->availability(),
            new AllowedToolsResolver(new SkillComposer(), $this->registry),
            new ToolDataClassResolver($this->registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );
    }

    private function availability(): ToolAvailabilityService
    {
        return new ToolAvailabilityService($this->registry, new ToolStateRepository($this->pool), new ToolGroupStateRepository($this->pool));
    }

    private function batchCommand(): QueueEditorActionBatchCommand
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findDefault')->willReturn($this->configuration);

        $catalogue = new EditorActionCatalogue(
            $this->availability(),
            $this->policy(),
            $repository,
            self::createStub(LlmConfigurationServiceInterface::class),
            $this->vision(),
            new ConfigurationResolver(),
        );

        return new QueueEditorActionBatchCommand(
            new EditorActionBatchPlanner(
                $catalogue,
                $this->registry,
                new TranscriptEstimator(),
                new BatchScopeReader($this->getService(ResourceFactory::class), $this->pool),
                $this->persister(),
            ),
            $this->runtime(),
            new ActingBackendUserResolver(),
            $this->pool,
        );
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

    private function runtime(?ActingBackendUserResolverInterface $users = null): AgentRuntime
    {
        $adapters = self::createStub(ProviderAdapterRegistryInterface::class);
        $adapters->method('createAdapterFromModel')->willReturn($this->adapter);

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn([]);

        $policy = $this->policy();
        $loop   = new ToolLoopService(
            $this->createLlmServiceManager($extensionConfiguration, new NullLogger(), $adapters, new MiddlewarePipeline([]), self::createStub(CacheManagerInterface::class), runPrivacy: self::createStub(RunProviderPrivacyInterface::class)),
            $this->registry,
            $policy,
            new NullLogger(),
        );

        $configurations = self::createStub(LlmConfigurationRepository::class);
        $configurations->method('findByUid')->willReturn($this->configuration);

        return new AgentRuntime(
            toolLoop: $loop,
            persister: $this->persister(),
            configurationRepository: $configurations,
            logger: new NullLogger(),
            messageBus: self::createStub(MessageBusInterface::class),
            actingBackendUserResolver: $users ?? new ActingBackendUserResolver(),
            toolEffectResolver: new ToolEffectResolver($this->registry),
            toolPolicy: $policy,
            visionActionBuilder: $this->vision(),
        );
    }
}

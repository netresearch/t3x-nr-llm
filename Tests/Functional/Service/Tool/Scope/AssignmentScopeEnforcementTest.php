<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool\Scope;

use Closure;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Netresearch\NrLlm\Domain\Enum\ChangeAction;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\ObservedChangeSet;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ScopedPage;
use Netresearch\NrLlm\Domain\ValueObject\ScopeRefusal;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolLoopResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\ClearCacheTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler;
use Netresearch\NrLlm\Service\Tool\Change\ToolChangeCollector;
use Netresearch\NrLlm\Service\Tool\DependencyCheckpoint;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolDependencyPendingException;
use Netresearch\NrLlm\Service\Tool\Exception\ToolDependencyRequiredException;
use Netresearch\NrLlm\Service\Tool\Rule\RequireAssignmentScopeRule;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeChecker;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeEffect;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeGuard;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeHook;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeTransaction;
use Netresearch\NrLlm\Service\Tool\Scope\DatabaseRecordTopology;
use Netresearch\NrLlm\Service\Tool\Scope\RecordFacts;
use Netresearch\NrLlm\Service\Tool\Scope\RecordTopologyInterface;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedDestination;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWrite;
use Netresearch\NrLlm\Service\Tool\Scope\WriteOutsideTheAssignmentScope;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolInvocationPolicy;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Service\Tool\WorkerWriteEnvironment;
use Netresearch\NrLlm\Tests\Fixtures\Database\FailsOneReadConnectionPool;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheFailingHookTrait;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\ReplacesTheCommandMapHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RunsANestedToolDataHandlerHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RecordingLogger;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\ScopedWriteFakeDependencyTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\ScopedWriteFakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The scope of an assignment against a real database, the real DataHandler
 * with its hooks and the real tool loop (ADR-295). Only the provider is
 * scripted.
 *
 *     1 site root
 *     ├ 2 the assignment        content 21
 *     │ └ 3 one level below     content 22
 *     │   └ 4 two levels below  content 24
 *     └ 5 another branch        content 23
 *
 * The scope is page 2 and one level below it, so pages 2 and 3 and their
 * content are in scope.
 *
 * The tool of each test claims what the first layer reads and does what the
 * test says. A call that claims a record in scope and writes elsewhere is the
 * case the first layer cannot see.
 */
#[CoversClass(AssignmentScopeHook::class)]
#[CoversClass(AssignmentScopeGuard::class)]
#[CoversClass(ToolLoopService::class)]
final class AssignmentScopeEnforcementTest extends AbstractFunctionalTestCase
{
    use RegistersTheFailingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    private const SCOPED = 2;

    private const BELOW = 3;

    private const TOO_DEEP = 4;

    private const OTHER = 5;

    private const IN_SCOPE_ELEMENT = 21;

    private const BELOW_ELEMENT = 22;

    private const OUTSIDE_ELEMENT = 23;

    private const DEEP_ELEMENT = 24;

    private const DRAFT = 1;

    private const GERMAN = 1;

    /** The German overlay of the scoped page, where a test makes one. */
    private const OVERLAY = 60;

    private ConnectionPool $connectionPool;

    /** What the model is told of a rollback that happened; "NOT rolled back" does not contain it. */
    private const ROLLED_BACK = 'All database changes of this call were rolled back.';

    private ?AssignmentScopeChecker $checkerOverride = null;

    private ?LoggerInterface $loggerOverride = null;

    private ?WorkerWriteEnvironment $writeEnvironmentOverride = null;

    private ?EventDispatcherInterface $eventDispatcherOverride = null;

    protected function setUp(): void
    {
        parent::setUp();

        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->connectionPool = $pool;

        $this->importFixture('BeUsers.csv');
        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);

        $pages = $this->connectionPool->getConnectionForTable('pages');
        foreach ([[1, 0, 1], [self::SCOPED, 1, 0], [self::BELOW, self::SCOPED, 0], [self::TOO_DEEP, self::BELOW, 0], [self::OTHER, 1, 0]] as [$uid, $pid, $siteRoot]) {
            $pages->insert('pages', [
                'uid'           => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'doktype' => 1, 'slug' => '/' . $uid,
                'is_siteroot'   => $siteRoot, 'sorting' => $uid * 256,
                'perms_userid'  => 1, 'perms_user' => Permission::ALL,
                'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
            ]);
        }

        $content = $this->connectionPool->getConnectionForTable('tt_content');
        foreach ([[self::IN_SCOPE_ELEMENT, self::SCOPED], [self::BELOW_ELEMENT, self::BELOW], [self::OUTSIDE_ELEMENT, self::OTHER], [self::DEEP_ELEMENT, self::TOO_DEEP]] as [$uid, $pid]) {
            $content->insert('tt_content', ['uid' => $uid, 'pid' => $pid, 'colPos' => 0, 'CType' => 'text', 'header' => 'Original ' . $uid, 'sorting' => $uid * 256]);
        }

        $siteWriter = $this->get(SiteWriter::class);
        self::assertInstanceOf(SiteWriter::class, $siteWriter);
        $siteWriter->write('testing', [
            'rootPageId' => 1,
            'base'       => 'https://example.com/',
            'languages'  => [
                ['languageId' => 0, 'title' => 'English', 'base' => '/', 'locale' => 'en_US.UTF-8', 'flag' => 'us'],
                ['languageId' => self::GERMAN, 'title' => 'German', 'base' => '/de/', 'locale' => 'de_DE.UTF-8', 'flag' => 'de', 'fallbackType' => 'strict'],
            ],
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
        ToolChangeCollector::reset();
        AssignmentScopeGuard::reset();
        RunsANestedToolDataHandlerHook::reset();
    }

    protected function tearDown(): void
    {
        $this->unregisterHook('processDatamapClass', RunsANestedToolDataHandlerHook::class);
        RunsANestedToolDataHandlerHook::reset();
        $this->unregisterHook('processCmdmapClass', ReplacesTheCommandMapHook::class);
        ReplacesTheCommandMapHook::$cmdmap = null;
        ToolChangeCollector::reset();
        AssignmentScopeGuard::reset();
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    // --- allowed -------------------------------------------------------------

    #[Test]
    public function aWriteInsideTheScopeIsAllowedAndLeavesNothingRefused(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT, self::BELOW_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Changed in scope', $context);
                $this->updateHeader(self::BELOW_ELEMENT, 'Changed below', $context);
            },
        );

        self::assertSame('Changed in scope', $this->header(self::IN_SCOPE_ELEMENT));
        self::assertSame('Changed below', $this->header(self::BELOW_ELEMENT));
        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame('ok', $outcome['toolResult']);
    }

    #[Test]
    public function aRunWithoutAScopeIsNotHeldToAnythingUnlessTheConfigurationRequiresOne(): void
    {
        $this->call(
            $this->claimingRecords([self::OUTSIDE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Unbound', $context);
            },
            scope: null,
        );

        self::assertSame('Unbound', $this->header(self::OUTSIDE_ELEMENT));
    }

    #[Test]
    public function aWriteOutsideAToolCallIsNotHeldToTheScope(): void
    {
        $user = $this->useASessionlessAmbientUser(1);
        $this->updateHeader(self::OUTSIDE_ELEMENT, 'By an editor', ToolExecutionContext::fromBackendUser($user));

        self::assertSame('By an editor', $this->header(self::OUTSIDE_ELEMENT));
        self::assertFalse(AssignmentScopeGuard::isArmed());
    }

    // --- layer one -----------------------------------------------------------

    #[Test]
    public function aCallThatClaimsARecordOutsideTheScopeIsRefusedBeforeItRuns(): void
    {
        $ran     = false;
        $outcome = $this->call(
            $this->claimingRecords([self::OUTSIDE_ELEMENT]),
            function (ToolExecutionContext $context) use (&$ran): void {
                $ran = true;
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Never', $context);
            },
            approve: false,
        );

        self::assertFalse($ran);
        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT));
        self::assertStringContainsString('rule: assignment_scope, reason: outside_scope', $outcome['toolResult']);
        self::assertStringContainsString('tt_content:23', $outcome['toolResult']);
        self::assertSame(['update tt_content:23 outside_scope'], $this->refusals($outcome['trace']), 'the refusal is on the run, for the changes view');
    }

    #[Test]
    public function aConfigurationThatRequiresAScopeRefusesAWriteOfARunWithoutOne(): void
    {
        $ran     = false;
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context) use (&$ran): void {
                $ran = true;
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Never', $context);
            },
            scope: null,
            required: true,
            approve: false,
        );

        self::assertFalse($ran);
        self::assertSame('Original 21', $this->header(self::IN_SCOPE_ELEMENT));
        self::assertStringContainsString('reason: scope_required', $outcome['toolResult']);
        self::assertSame([], $this->refusals($outcome['trace']), 'nothing lies outside a scope that is missing');
    }

    // --- layer two: what the first layer cannot see ---------------------------

    /**
     * The call claims a record in scope, so the first layer lets it through
     * and the editor approves. What it writes is another record.
     */
    #[Test]
    public function aWriteTheClaimDoesNotNameIsRefusedByTheDataHandlerLayer(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Sneaked past the claim', $context);
            },
        );

        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT));
        self::assertSame(['update tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
        self::assertStringStartsWith('Error: tool "scoped_probe" did not complete.', $outcome['toolResult']);
        self::assertStringContainsString('refused update of tt_content:23 (outside_scope)', $outcome['toolResult']);
        self::assertStringContainsString('These writes did not happen', $outcome['toolResult']);
        self::assertFalse(AssignmentScopeGuard::isArmed());
    }

    /**
     * The control of the test above: the same call, with only the DataHandler
     * hook taken away, writes up to the end of the call. The effect check then
     * finds the write and the call's transaction is rolled back, so the write is
     * gone again.
     */
    #[Test]
    public function withoutTheDataHandlerHookTheEffectCheckRollsTheCallBack(): void
    {
        $this->unregisterTheDataHandlerLayer();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Sneaked past the claim', $context);
            },
        );

        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT));
        self::assertSame(['effect_updated tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
        self::assertStringContainsString('wrote outside the assignment of this run', $outcome['toolResult']);
        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
    }

    /**
     * An installation hook that writes another record through a DataHandler of
     * its own, while the call writes an element that is in scope: a real
     * source of writes no tool names.
     */
    #[Test]
    public function aHookOfTheInstallationWritingAnotherRecordIsStoppedInTheNestedRun(): void
    {
        $this->registerHook('processDatamapClass', RunsANestedToolDataHandlerHook::class);
        RunsANestedToolDataHandlerHook::$datamap = ['tt_content' => [self::OUTSIDE_ELEMENT => ['header' => 'By the hook']]];

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'The call itself', $context);
            },
        );

        self::assertSame('The call itself', $this->header(self::IN_SCOPE_ELEMENT), 'the write the call claimed went through');
        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT), 'the hook wrote nothing');
        self::assertSame(['update tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
    }

    #[Test]
    public function withoutTheDataHandlerHookTheEffectCheckRollsBackTheHookOfTheInstallationAndTheCallWithIt(): void
    {
        $this->unregisterTheDataHandlerLayer();
        $this->registerHook('processDatamapClass', RunsANestedToolDataHandlerHook::class);
        RunsANestedToolDataHandlerHook::$datamap = ['tt_content' => [self::OUTSIDE_ELEMENT => ['header' => 'By the hook']]];

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'The call itself', $context);
            },
        );

        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT));
        self::assertSame('Original 21', $this->header(self::IN_SCOPE_ELEMENT), 'the whole call is rolled back, not only the write outside');
        self::assertSame(['effect_updated tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
    }

    #[Test]
    public function aRecordCreatedUnderAPageOutsideTheScopeIsRefusedAndOneInsideIsNot(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->create(['pid' => self::BELOW, 'header' => 'Inside', 'CType' => 'text'], $context);
                $this->create(['pid' => self::OTHER, 'header' => 'Outside', 'CType' => 'text'], $context);
            },
        );

        self::assertSame(1, $this->countByHeader('Inside'), 'positive control: a record under a page in scope is created');
        self::assertSame(0, $this->countByHeader('Outside'));
        self::assertSame(['create tt_content (on page 5) outside_scope'], $this->refusals($outcome['trace']));
    }

    #[Test]
    public function aRecordCreatedAfterARecordOutsideTheScopeIsRefused(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                // A negative pid puts the new record behind the named one, on its page.
                $this->create(['pid' => -self::OUTSIDE_ELEMENT, 'header' => 'Behind the foreign element', 'CType' => 'text'], $context);
            },
        );

        self::assertSame(0, $this->countByHeader('Behind the foreign element'));
        self::assertSame(['create tt_content (on page 5) outside_scope'], $this->refusals($outcome['trace']));
    }

    #[Test]
    public function aPageCreatedTooDeepIsRefused(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->createPage(self::SCOPED, 'Fits', $context);
                $this->createPage(self::BELOW, 'One level too deep', $context);
            },
        );

        self::assertSame(1, $this->countPages('Fits'));
        self::assertSame(0, $this->countPages('One level too deep'));
        self::assertSame(['create pages (on page 3) outside_scope'], $this->refusals($outcome['trace']));
    }

    #[Test]
    public function aMoveOutOfTheScopeAndOneIntoItAreRefused(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['move' => self::OTHER]]], $context);
                $this->command(['tt_content' => [self::OUTSIDE_ELEMENT => ['move' => self::SCOPED]]], $context);
                // Behind a record that lies outside: the destination is that record's page.
                $this->command(['tt_content' => [self::BELOW_ELEMENT => ['move' => -self::OUTSIDE_ELEMENT]]], $context);
                // The one move that stays inside.
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['move' => self::BELOW]]], $context);
            },
        );

        self::assertSame(self::BELOW, $this->pidOf('tt_content', self::IN_SCOPE_ELEMENT), 'positive control: the move inside the scope happened');
        self::assertSame(self::OTHER, $this->pidOf('tt_content', self::OUTSIDE_ELEMENT));
        self::assertSame(self::BELOW, $this->pidOf('tt_content', self::BELOW_ELEMENT));
        self::assertSame(
            ['move tt_content (on page 5) outside_scope', 'move tt_content:23 outside_scope', 'move tt_content (on page 5) outside_scope'],
            $this->refusals($outcome['trace']),
        );
    }

    #[Test]
    public function aTranslationOfARecordOutsideTheScopeIsRefusedAndOneInsideIsNot(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['tt_content' => [self::OUTSIDE_ELEMENT => ['localize' => self::GERMAN]]], $context);
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['localize' => self::GERMAN]]], $context);
            },
        );

        self::assertSame(0, $this->translationsOf(self::OUTSIDE_ELEMENT));
        self::assertSame(1, $this->translationsOf(self::IN_SCOPE_ELEMENT), 'positive control: the translation inside the scope was made');
        self::assertSame(['translate tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
    }

    #[Test]
    public function aRecordCreatedAsTheTranslationOfARecordOutsideTheScopeIsRefused(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->create(['pid' => self::SCOPED, 'header' => 'A claim on a foreign element', 'CType' => 'text', 'sys_language_uid' => self::GERMAN, 'l18n_parent' => self::OUTSIDE_ELEMENT], $context);
            },
        );

        self::assertSame(0, $this->countByHeader('A claim on a foreign element'));
        self::assertSame(['translate tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
    }

    /**
     * A delete takes a page's subpages along without naming them, so a scope
     * that reaches only part of the tree must refuse the command whole.
     */
    #[Test]
    public function aPageDeleteReachingPagesBeyondTheDepthIsRefusedWholeAndNothingIsDeleted(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['pages' => [self::BELOW => ['delete' => 1]]], $context);
            },
        );

        self::assertSame(0, $this->goneCount('pages', [self::BELOW, self::TOO_DEEP]), 'neither the page nor the page below it was deleted');
        self::assertSame(0, $this->goneCount('tt_content', [self::BELOW_ELEMENT, self::DEEP_ELEMENT]));
        self::assertSame(['delete pages:4 subtree_outside_scope'], $this->refusals($outcome['trace']));
    }

    #[Test]
    public function theSamePageDeleteIsAllowedWhenTheScopeReachesFarEnough(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['pages' => [self::BELOW => ['delete' => 1]]], $context);
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 2)]),
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame(2, $this->goneCount('pages', [self::BELOW, self::TOO_DEEP]));
    }

    /**
     * A copy of a page copies its subpages only where the user's copy levels
     * say so; the built-in tool pins them to 0. The page below the one copied
     * lies outside the scope.
     */
    #[Test]
    public function aPageCopyIsJudgedOnTheSubtreeOnlyWhenItTakesSubpagesAlong(): void
    {
        $copy = (fn (int $levels): array => $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context) use ($levels): void {
                $user = $context->actingBackendUser();
                self::assertInstanceOf(BackendUserAuthentication::class, $user);
                $user->uc['copyLevels'] = $levels;
                $this->command(['pages' => [self::BELOW => ['copy' => self::SCOPED]]], $context);
            },
        ));

        $page = $copy(0);
        self::assertSame([], $this->refusals($page['trace']));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM pages WHERE pid = ?', [self::SCOPED]), 'the page was copied under its parent');

        $tree = $copy(1);
        self::assertSame(['copy pages:4 subtree_outside_scope'], $this->refusals($tree['trace']));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM pages WHERE pid = ?', [self::SCOPED]), 'the second copy was refused whole');
    }

    /**
     * A moved page takes its subpages along. Page 3 has page 4 below it, so a
     * scope of one level admits neither page 3 under page 2 (its subpage would
     * be two levels down) nor anything that puts it deeper.
     */
    #[Test]
    public function aPageMoveNeedsItsSubpagesInScopeAtTheNewPosition(): void
    {
        $shallow = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['pages' => [self::BELOW => ['move' => self::SCOPED]]], $context);
            },
        );
        self::assertSame(['move pages:3 outside_scope'], $this->refusals($shallow['trace']));

        $deep = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['pages' => [self::BELOW => ['move' => self::SCOPED]]], $context);
                // Behind a page of another branch.
                $this->command(['pages' => [self::BELOW => ['move' => -self::OTHER]]], $context);
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 2)]),
        );
        self::assertSame(['move pages:3 outside_scope'], $this->refusals($deep['trace']), 'only the move out of the scope is refused');
        self::assertSame(self::SCOPED, $this->pidOf('pages', self::BELOW));
    }

    #[Test]
    public function aNewRecordWithoutALanguageIsJudgedAsTheDefaultLanguage(): void
    {
        $allowed = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->create(['pid' => self::SCOPED, 'header' => 'Default language', 'CType' => 'text'], $context);
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 1)], [], null, [0]),
        );
        self::assertSame([], $this->refusals($allowed['trace']));
        self::assertSame(1, $this->countByHeader('Default language'));

        $refused = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context): void {
                $this->create(['pid' => self::SCOPED, 'header' => 'No language given', 'CType' => 'text'], $context);
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 1)], [], null, [self::GERMAN]),
        );
        self::assertSame(['create tt_content (on page 0) language_not_allowed'], $this->refusals($refused['trace']));
        self::assertSame(0, $this->countByHeader('No language given'));
    }

    /**
     * Listing an existing record in an inline field re-parents it with a query
     * of the relation handler, so it has to be in scope; no datamap entry
     * names it.
     */
    #[Test]
    public function anInlineFieldNamingAChildOutsideTheScopeIsRefused(): void
    {
        $reference = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $reference->insert('sys_file_reference', ['uid' => 50, 'pid' => self::OTHER, 'uid_local' => 1, 'uid_foreign' => self::OUTSIDE_ELEMENT, 'tablenames' => 'tt_content', 'fieldname' => 'assets', 'sorting_foreign' => 1]);
        $reference->insert('sys_file_reference', ['uid' => 51, 'pid' => self::SCOPED, 'uid_local' => 1, 'uid_foreign' => self::IN_SCOPE_ELEMENT, 'tablenames' => 'tt_content', 'fieldname' => 'assets', 'sorting_foreign' => 1]);

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['tt_content' => [self::IN_SCOPE_ELEMENT => ['assets' => '51,50']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
        );

        self::assertSame(['update sys_file_reference:50 outside_scope'], $this->refusals($outcome['trace']));
        self::assertSame(self::OUTSIDE_ELEMENT, (int) $this->scalar('SELECT uid_foreign FROM sys_file_reference WHERE uid = 50', []), 'the foreign reference was not taken over');

        $inside = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['tt_content' => [self::IN_SCOPE_ELEMENT => ['assets' => '51']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
        );
        self::assertSame([], $this->refusals($inside['trace']), 'a child on a page in scope is let through');
    }

    /**
     * A page overlay lies beside its source, under the source's parent, which
     * the scope does not contain; it is judged on the page it translates.
     */
    #[Test]
    public function aTranslationOfAPageInScopeIsAllowedWhoseParentIsNot(): void
    {
        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context): void {
                $this->command(['pages' => [self::BELOW => ['localize' => self::GERMAN]]], $context);
            },
            scope: AssignmentScope::forPage(self::BELOW),
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pages WHERE l10n_parent = ?', [self::BELOW]), 'the overlay was created');
    }

    /**
     * A scope of single records has no page: the translation and the file
     * reference of a scoped record are in it, those of another record are not.
     */
    #[Test]
    public function aScopeOfOneRecordAdmitsItsTranslationAndItsFileReferencesOnly(): void
    {
        $scope = new AssignmentScope([], [new RecordReference('tt_content', self::IN_SCOPE_ELEMENT)]);
        $this->insertAFile();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['localize' => self::GERMAN]]], $context);
                foreach ([self::IN_SCOPE_ELEMENT => self::SCOPED, self::OUTSIDE_ELEMENT => self::OTHER] as $host => $pid) {
                    $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                    $dataHandler->start(['sys_file_reference' => ['NEW1' => ['pid' => $pid, 'uid_local' => 1, 'uid_foreign' => $host, 'tablenames' => 'tt_content', 'fieldname' => 'assets']]], [], $context->actingBackendUser());
                    $dataHandler->process_datamap();
                }
            },
            scope: $scope,
        );

        self::assertSame(1, $this->translationsOf(self::IN_SCOPE_ELEMENT), 'the translation of a scoped record');
        self::assertSame(['attach tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM sys_file_reference WHERE tablenames = ? AND uid_foreign = ?', ['tt_content', self::IN_SCOPE_ELEMENT]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM sys_file_reference WHERE tablenames = ? AND uid_foreign = ?', ['tt_content', self::OUTSIDE_ELEMENT]));
    }

    /**
     * A translation or a file reference that is in scope through its origin or
     * host is still written where it says: on a page outside the scope it is
     * refused, whatever its origin is.
     */
    #[Test]
    public function aTranslationOrAFileReferenceOfARecordInScopeIsRefusedOnAPageOutsideTheScope(): void
    {
        $this->insertAFile();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->create(['pid' => self::OTHER, 'header' => 'Translation on a foreign page', 'CType' => 'text', 'sys_language_uid' => self::GERMAN, 'l18n_parent' => self::IN_SCOPE_ELEMENT], $context);
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['sys_file_reference' => ['NEW1' => ['pid' => self::OTHER, 'uid_local' => 1, 'uid_foreign' => self::IN_SCOPE_ELEMENT, 'tablenames' => 'tt_content', 'fieldname' => 'assets']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
        );

        self::assertSame(['create tt_content (on page 5) outside_scope', 'create sys_file_reference (on page 5) outside_scope'], $this->refusals($outcome['trace']));
        self::assertSame(0, $this->countByHeader('Translation on a foreign page'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM sys_file_reference WHERE pid = ?', [self::OTHER]));
    }

    /**
     * Core removes and moves the translations of a record with it, in every
     * language, so a language list has to cover them.
     */
    #[Test]
    public function aDeleteOrMoveTakingTranslationsInAnotherLanguageAlongIsRefused(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 41, 'pid' => self::SCOPED, 'colPos' => 0, 'CType' => 'text', 'header' => 'Uebersetzung', 'sys_language_uid' => self::GERMAN, 'l18n_parent' => self::IN_SCOPE_ELEMENT, 'sorting' => 1]);

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['delete' => 1]]], $context);
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['move' => self::BELOW]]], $context);
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 1)], [], null, [0]),
        );

        self::assertSame(['delete tt_content:21 language_not_allowed', 'move tt_content:21 language_not_allowed'], $this->refusals($outcome['trace']));
        self::assertSame(0, $this->goneCount('tt_content', [self::IN_SCOPE_ELEMENT, 41]));
        self::assertSame(self::SCOPED, $this->pidOf('tt_content', 41));

        $listed = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['move' => self::BELOW]]], $context);
            },
        );
        self::assertSame([], $this->refusals($listed['trace']), 'with no language list the move is allowed');
    }

    /**
     * Core reads the target of a move as an integer, which is 1 for an array
     * that is not a paste command.
     */
    #[Test]
    public function aMoveWhoseTargetIsAnArrayIsJudgedAsCoreReadsIt(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['move' => ['target' => self::BELOW]]]], $context);
            },
        );

        self::assertSame(['move tt_content (on page 1) outside_scope'], $this->refusals($outcome['trace']));
        self::assertSame(self::SCOPED, $this->pidOf('tt_content', self::IN_SCOPE_ELEMENT));
    }

    #[Test]
    public function aChildNamedWithItsTableAndTheParentPointerOfAFileReferenceAreHeldToTheScope(): void
    {
        $this->insertAFile();
        $reference = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $reference->insert('sys_file_reference', ['uid' => 50, 'pid' => self::OTHER, 'uid_local' => 1, 'uid_foreign' => self::OUTSIDE_ELEMENT, 'tablenames' => 'tt_content', 'fieldname' => 'assets', 'sorting_foreign' => 1]);
        $reference->insert('sys_file_reference', ['uid' => 51, 'pid' => self::SCOPED, 'uid_local' => 1, 'uid_foreign' => self::IN_SCOPE_ELEMENT, 'tablenames' => 'tt_content', 'fieldname' => 'assets', 'sorting_foreign' => 1]);

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                // The `table_uid` form of a child core accepts in a relation field.
                $dataHandler->start(['tt_content' => [self::IN_SCOPE_ELEMENT => ['assets' => 'sys_file_reference_50']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
                // A child of this record pointed at another record.
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['sys_file_reference' => [51 => ['uid_foreign' => self::OUTSIDE_ELEMENT]]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
        );

        self::assertSame(['update sys_file_reference:50 outside_scope', 'attach tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
        self::assertSame(self::IN_SCOPE_ELEMENT, (int) $this->scalar('SELECT uid_foreign FROM sys_file_reference WHERE uid = 51', []));
    }

    // --- the input check fails closed -----------------------------------------

    /**
     * A value of an inline field that is no placeholder, no canonical uid and no
     * `table_uid` of a table is refused, not skipped.
     */
    #[Test]
    public function anInlineItemThatCannotBeReadIsRefusedAndNotSkipped(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                foreach (['abc', 'NEW1,abc', '007', '-5', '5 x', 'sys_file_reference_x', 'nosuchtable_5', 'sys_file_reference_007', '_5'] as $item) {
                    $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                    $dataHandler->start(['tt_content' => [self::IN_SCOPE_ELEMENT => ['assets' => $item]]], [], $context->actingBackendUser());
                    $dataHandler->process_datamap();
                }
            },
        );

        self::assertSame(array_fill(0, 9, 'update sys_file_reference (on page 0) unresolvable'), $this->refusals($outcome['trace']));
    }

    /**
     * A parent pointer that is present and wrong is refused; the stored value
     * stands in only for a pointer the entry does not carry.
     */
    #[Test]
    public function aParentPointerThatIsPresentButUnreadableIsRefused(): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', ['uid' => 51, 'pid' => self::SCOPED, 'uid_local' => 1, 'uid_foreign' => self::IN_SCOPE_ELEMENT, 'tablenames' => 'tt_content', 'fieldname' => 'assets', 'sorting_foreign' => 1]);

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                foreach ([['uid_foreign' => 'abc'], ['uid_foreign' => '021'], ['tablenames' => 'no_such_table'], ['tablenames' => ['tt_content']]] as $fields) {
                    $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                    $dataHandler->start(['sys_file_reference' => [51 => $fields]], [], $context->actingBackendUser());
                    $dataHandler->process_datamap();
                }
            },
        );

        self::assertSame(array_fill(0, 4, 'update sys_file_reference:51 unresolvable'), $this->refusals($outcome['trace']));
    }

    /**
     * Core copies a record with its translations unless the command says
     * `ignoreLocalization`, and a page with the content and translations on it.
     */
    #[Test]
    public function aCopyThatTakesTranslationsInAnotherLanguageAlongIsRefused(): void
    {
        $content = $this->connectionPool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 41, 'pid' => self::SCOPED, 'colPos' => 0, 'CType' => 'text', 'header' => 'Uebersetzung', 'sys_language_uid' => self::GERMAN, 'l18n_parent' => self::IN_SCOPE_ELEMENT, 'sorting' => 1]);
        $content->insert('tt_content', ['uid' => 42, 'pid' => self::BELOW, 'colPos' => 0, 'CType' => 'text', 'header' => 'Uebersetzung unten', 'sys_language_uid' => self::GERMAN, 'l18n_parent' => self::BELOW_ELEMENT, 'sorting' => 1]);

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['copy' => self::SCOPED]]], $context);
                $this->command(['pages' => [self::BELOW => ['copy' => self::SCOPED]]], $context);
                // Leaving the translations behind is allowed.
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['copy' => ['target' => self::SCOPED, 'ignoreLocalization' => true]]]], $context);
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 1)], [], null, [0]),
        );

        self::assertSame(['copy tt_content:21 language_not_allowed', 'copy pages:3 language_not_allowed'], $this->refusals($outcome['trace']));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM tt_content WHERE header LIKE ? AND deleted = 0', ['Original 21%']), 'only the copy that leaves the translations behind was made');
    }

    /**
     * A write to a default-language page that has a translation, in a scope of
     * that page and the default language only, as the A2A page metadata skill
     * sets it. Core's data-map processor adds an entry for the translation to
     * every such write, carrying the fields it synchronises from the page
     * (`l10n_mode` `exclude`), whether they changed or not.
     */
    #[Test]
    #[DataProvider('workspaces')]
    public function aWriteToAPageWithATranslationIsNotRefusedForTheEntryCoreAddsForTheTranslation(bool $draft): void
    {
        $this->insertAGermanOverlayOfTheScopedPage();
        $user = $this->setUpBackendUser(1);
        if ($draft) {
            self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));
        }

        $before  = $this->pageRows(self::OVERLAY);
        $outcome = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('update', [new RecordReference('pages', self::SCOPED)], [], false, 0),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['pages' => [self::SCOPED => ['description' => 'Described']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 0)], [], ['pages'], [0]),
            user: $user,
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame('Described', $this->scalar('SELECT description FROM pages WHERE ' . ($draft ? 't3ver_oid' : 'uid') . ' = ? AND sys_language_uid = 0', [self::SCOPED]));
        self::assertSame($before, $this->pageRows(self::OVERLAY), 'the translation is not written, and gets no version');
    }

    /**
     * The same write when an editor has already changed the page in the draft
     * workspace, so the page has a version there before the call, and core
     * gave the translation one with it.
     */
    #[Test]
    public function aWriteToAPageWithATranslationThatAlreadyHasADraftIsNotRefused(): void
    {
        $this->insertAGermanOverlayOfTheScopedPage();
        $user = $this->setUpBackendUser(1);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));
        $editor = GeneralUtility::makeInstance(DataHandler::class);
        $editor->start(['pages' => [self::SCOPED => ['description' => 'By an editor']]], [], $user);
        $editor->process_datamap();
        self::assertSame('By an editor', $this->scalar('SELECT description FROM pages WHERE t3ver_oid = ? AND t3ver_wsid = ?', [self::SCOPED, self::DRAFT]), 'the draft exists before the call');

        $before  = $this->pageRows(self::OVERLAY);
        $outcome = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('update', [new RecordReference('pages', self::SCOPED)], [], false, 0),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['pages' => [self::SCOPED => ['description' => 'Described']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 0)], [], ['pages'], [0]),
            user: $user,
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame('Described', $this->scalar('SELECT description FROM pages WHERE t3ver_oid = ? AND t3ver_wsid = ?', [self::SCOPED, self::DRAFT]));
        self::assertSame($before, $this->pageRows(self::OVERLAY), 'the translation and its draft stay as they were');
    }

    /**
     * A translation translated from another one (`l10n_source`): core adds an
     * entry for it to a write of its source as it does to a write of the
     * default-language page. A write that synchronises nothing is not refused
     * for it; a write of a field that reaches it is refused at the source.
     */
    #[Test]
    public function aTranslationTranslatedFromTheWrittenOneIsHeldToTheSameRules(): void
    {
        $this->insertAGermanOverlayOfTheScopedPage(['l10n_state' => '{"author":"custom"}']);
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'              => self::OVERLAY + 1, 'pid' => 1, 'title' => 'Page 2 (2)', 'doktype' => 1, 'slug' => '/2',
            'sys_language_uid' => 2, 'l10n_parent' => self::SCOPED, 'l10n_source' => self::OVERLAY, 'l10n_state' => '{"author":"source"}',
            'perms_userid'     => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL,
        ]);
        $sourced = $this->pageRows(self::OVERLAY + 1);
        $scope   = new AssignmentScope([new ScopedPage(self::SCOPED, 0)], [], ['pages'], [0, self::GERMAN]);

        $plain = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('update', [new RecordReference('pages', self::OVERLAY)], [], false, self::GERMAN),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['pages' => [self::OVERLAY => ['description' => 'Beschrieben']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            scope: $scope,
        );
        self::assertSame([], $this->refusals($plain['trace']));
        self::assertSame('Beschrieben', $this->scalar('SELECT description FROM pages WHERE uid = ?', [self::OVERLAY]));
        self::assertSame($sourced, $this->pageRows(self::OVERLAY + 1));

        $synchronised = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('update', [new RecordReference('pages', self::OVERLAY)], [], false, self::GERMAN),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['pages' => [self::OVERLAY => ['author' => 'Jemand']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            scope: $scope,
        );
        self::assertContains('update pages:60 language_not_allowed', $this->refusals($synchronised['trace']));
        self::assertSame('', $this->scalar('SELECT author FROM pages WHERE uid = ?', [self::OVERLAY]));
        self::assertSame($sourced, $this->pageRows(self::OVERLAY + 1));
    }

    /**
     * For a synchronised file field core copies or localises the children
     * before any entry is judged, so an entry core added that lists children
     * is judged as any other and not left out: a known limit, the call is
     * refused for a page whose translation carries a synchronised file field.
     */
    #[Test]
    public function anEntryCoreAddedThatListsChildrenIsJudgedAndNotLeftOut(): void
    {
        $this->insertAFile();
        $this->insertAGermanOverlayOfTheScopedPage(['media' => 1]);
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['media' => 1], ['uid' => self::SCOPED]);
        $references = $this->connectionPool->getConnectionForTable('sys_file_reference');
        $references->insert('sys_file_reference', ['uid' => 70, 'pid' => self::SCOPED, 'uid_local' => 1, 'uid_foreign' => self::SCOPED, 'tablenames' => 'pages', 'fieldname' => 'media', 'sorting_foreign' => 1]);
        $references->insert('sys_file_reference', ['uid' => 71, 'pid' => self::SCOPED, 'uid_local' => 1, 'uid_foreign' => self::OVERLAY, 'tablenames' => 'pages', 'fieldname' => 'media', 'sorting_foreign' => 1, 'sys_language_uid' => self::GERMAN, 'l10n_parent' => 70]);

        $outcome = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('update', [new RecordReference('pages', self::SCOPED)], [], false, 0),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['pages' => [self::SCOPED => ['description' => 'Described']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 0)], [], ['pages'], [0]),
        );

        self::assertSame(['update pages:60 language_not_allowed'], $this->refusals($outcome['trace']));
    }

    /**
     * An entry for the translation that the run submitted itself is judged as
     * any other, even when it holds only fields core synchronises.
     */
    #[Test]
    public function anEntryForTheTranslationThatTheRunSubmittedIsRefused(): void
    {
        $this->insertAGermanOverlayOfTheScopedPage();
        $overlay = $this->pageRows(self::OVERLAY);

        $outcome = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('update', [new RecordReference('pages', self::SCOPED)], [], false, 0),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['pages' => [self::SCOPED => ['description' => 'Described'], self::OVERLAY => ['nav_hide' => 1]]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 0)], [], ['pages'], [0]),
        );

        self::assertSame(['update pages:60 language_not_allowed'], $this->refusals($outcome['trace']));
        self::assertSame($overlay, $this->pageRows(self::OVERLAY));
    }

    /**
     * A field core synchronises (`layout` is `l10n_mode` `exclude`) reaches
     * the translation, so the write is refused at the page, and nothing is
     * stored: neither the page nor its translation changes.
     */
    #[Test]
    #[DataProvider('workspaces')]
    public function aWriteOfASynchronisedFieldIsRefusedWhenATranslationIsInALanguageOutsideTheScope(bool $draft): void
    {
        $this->insertAGermanOverlayOfTheScopedPage();
        $user = $this->setUpBackendUser(1);
        if ($draft) {
            self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));
        }

        $page    = $this->pageRows(self::SCOPED);
        $overlay = $this->pageRows(self::OVERLAY);
        $outcome = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('update', [new RecordReference('pages', self::SCOPED)], [], false, 0),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['pages' => [self::SCOPED => ['layout' => 2, 'description' => 'Described']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 0)], [], ['pages'], [0]),
            user: $user,
        );

        self::assertSame(['update pages:2 language_not_allowed', 'update pages:60 language_not_allowed'], $this->refusals($outcome['trace']));
        self::assertStringStartsWith('Error: tool "scoped_probe" did not complete.', $outcome['toolResult']);
        self::assertSame($page, $this->pageRows(self::SCOPED), 'the page is not written');
        self::assertSame($overlay, $this->pageRows(self::OVERLAY), 'the translation is not written');

        $admitted = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('update', [new RecordReference('pages', self::SCOPED)]),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['pages' => [self::SCOPED => ['layout' => 2]]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 0)], [], ['pages'], [0, self::GERMAN]),
            user: $user,
        );
        self::assertSame([], $this->refusals($admitted['trace']), 'with the language of the translation in scope core synchronises it');
        self::assertSame(2, (int) $this->scalar('SELECT layout FROM pages WHERE ' . ($draft ? 't3ver_oid' : 'uid') . ' = ?', [self::OVERLAY]), 'the translation took the field');
    }

    /**
     * The same holds for content: a write to an element with a translation in a
     * language outside the scope is not refused for what core adds for it.
     */
    #[Test]
    public function aWriteToAnElementWithATranslationIsNotRefusedForTheEntryCoreAddsForTheTranslation(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 41, 'pid' => self::SCOPED, 'colPos' => 0, 'CType' => 'text', 'header' => 'Uebersetzung', 'sys_language_uid' => self::GERMAN, 'l18n_parent' => self::IN_SCOPE_ELEMENT, 'sorting' => 1]);

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Changed in scope', $context);
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 1)], [], null, [0]),
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame('Changed in scope', $this->header(self::IN_SCOPE_ELEMENT));
        self::assertSame('Uebersetzung', $this->header(41));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function workspaces(): iterable
    {
        yield 'live workspace' => [false];
        yield 'draft workspace' => [true];
    }

    /**
     * Every table a DataHandler run writes, as rows: what a rollback has to leave
     * exactly as it found it.
     */
    private function fingerprint(): string
    {
        $state = [];
        foreach (['pages', 'tt_content', 'sys_file_reference', 'sys_history', 'sys_refindex', 'sys_log'] as $table) {
            $state[$table] = $this->connectionPool->getConnectionForTable($table)->executeQuery('SELECT * FROM ' . $table . ' ORDER BY 1, 2')->fetchAllAssociative();
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    /**
     * Each of these is a write the input check refuses. With only that check
     * removed, the writes go through the DataHandler; the check of the effect
     * finds them in the database before the call's transaction is committed, and
     * the rollback leaves every table as it was: rows, `sys_history`,
     * `sys_refindex` and workspace versions. This is what makes the second layer
     * independent of the shapes the first has to parse.
     *
     * @param Closure(ToolExecutionContext): void $act
     */
    private function assertTheEffectCheckRollsBack(Closure $act, bool $draft, ?AssignmentScope $scope = null): void
    {
        $this->unregisterTheDataHandlerLayer();
        $user = $this->setUpBackendUser(1);
        if ($draft) {
            self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));
        }

        $before = $this->fingerprint();

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            $act,
            scope: $scope ?? new AssignmentScope([new ScopedPage(self::SCOPED, 1)]),
            user: $user,
        );

        $refusals = $this->refusals($outcome['trace']);
        self::assertNotSame([], $refusals, 'the effect check found the write');
        self::assertSame([], array_values(array_filter($refusals, static fn (string $line): bool => !str_starts_with($line, 'effect_'))), 'only the effect check refused, the input check was taken away');
        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertSame($before, $this->fingerprint(), 'the database is exactly as before the call');
    }

    #[Test]
    #[DataProvider('workspaces')]
    public function theEffectCheckRollsBackAnUpdateOutsideTheScope(bool $draft): void
    {
        $this->assertTheEffectCheckRollsBack(
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);
            },
            $draft,
        );
    }

    #[Test]
    #[DataProvider('workspaces')]
    public function theEffectCheckRollsBackARecordCreatedOutsideTheScope(bool $draft): void
    {
        $this->assertTheEffectCheckRollsBack(
            function (ToolExecutionContext $context): void {
                $this->create(['pid' => self::OTHER, 'header' => 'Created outside', 'CType' => 'text'], $context);
            },
            $draft,
        );
    }

    #[Test]
    #[DataProvider('workspaces')]
    public function theEffectCheckRollsBackAMoveOutOfTheScope(bool $draft): void
    {
        $this->assertTheEffectCheckRollsBack(
            function (ToolExecutionContext $context): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['move' => self::OTHER]]], $context);
            },
            $draft,
        );
    }

    #[Test]
    #[DataProvider('workspaces')]
    public function theEffectCheckRollsBackARecordWithoutALanguageTheScopeDoesNotAdmit(bool $draft): void
    {
        $this->assertTheEffectCheckRollsBack(
            function (ToolExecutionContext $context): void {
                $this->create(['pid' => self::SCOPED, 'header' => 'No language', 'CType' => 'text'], $context);
            },
            $draft,
            new AssignmentScope([new ScopedPage(self::SCOPED, 1)], [], null, [self::GERMAN]),
        );
    }

    #[Test]
    #[DataProvider('workspaces')]
    public function theEffectCheckRollsBackATranslationPlacedOnAForeignPage(bool $draft): void
    {
        $this->assertTheEffectCheckRollsBack(
            function (ToolExecutionContext $context): void {
                $this->create(['pid' => self::OTHER, 'header' => 'Translation abroad', 'CType' => 'text', 'sys_language_uid' => self::GERMAN, 'l18n_parent' => self::IN_SCOPE_ELEMENT], $context);
            },
            $draft,
        );
    }

    /**
     * Another user's draft, made before the call, is not the call's and is not
     * touched by its rollback.
     */
    #[Test]
    public function aDraftOfAnotherUserSurvivesTheRollbackOfALaterCall(): void
    {
        $this->unregisterTheDataHandlerLayer();
        $user = $this->setUpBackendUser(1);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['tt_content' => [self::OUTSIDE_ELEMENT => ['header' => 'Editor draft', 'subheader' => 'Editor subheader']]], [], $user);
        $dataHandler->process_datamap();
        self::assertSame(1, $this->versionsOf(self::OUTSIDE_ELEMENT));
        $before = $this->fingerprint();

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Written by the call', $context);
            },
            user: $user,
        );

        self::assertNotSame([], $this->refusals($outcome['trace']));
        self::assertSame($before, $this->fingerprint(), "the editor's version is as the editor left it");
        self::assertSame('Editor draft', $this->scalar('SELECT header FROM tt_content WHERE t3ver_oid = ? AND t3ver_wsid = ?', [self::OUTSIDE_ELEMENT, self::DRAFT]));
    }

    /**
     * A call run inside a transaction that is already open gets a savepoint: the
     * rollback undoes the call and leaves the outer transaction, with what it had
     * written before, to its owner.
     */
    #[Test]
    public function aRollbackInsideAnOpenTransactionLeavesTheOuterOneAlone(): void
    {
        $this->unregisterTheDataHandlerLayer();
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->beginTransaction();
        try {
            $connection->executeStatement('UPDATE tt_content SET header = ? WHERE uid = ?', ['By the outer transaction', self::IN_SCOPE_ELEMENT]);

            $kept = $this->call(
                static fn (): ScopedWrite => ScopedWrite::nothing(),
                function (ToolExecutionContext $context): void {
                    $this->updateHeader(self::BELOW_ELEMENT, 'In scope, inside the outer transaction', $context);
                },
            );
            self::assertSame([], $this->refusals($kept['trace']));
            self::assertSame('In scope, inside the outer transaction', $this->header(self::BELOW_ELEMENT));

            $undone = $this->call(
                static fn (): ScopedWrite => ScopedWrite::nothing(),
                function (ToolExecutionContext $context): void {
                    $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);
                },
            );
            self::assertNotSame([], $this->refusals($undone['trace']));
            self::assertTrue($connection->isTransactionActive(), 'the outer transaction is still open');
            self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT));
            self::assertSame('By the outer transaction', $this->header(self::IN_SCOPE_ELEMENT), 'what the outer transaction had written stands');
            self::assertSame('In scope, inside the outer transaction', $this->header(self::BELOW_ELEMENT), 'and so does the call that was in scope');
        } finally {
            $connection->commit();
        }

        self::assertSame('By the outer transaction', $this->header(self::IN_SCOPE_ELEMENT));
    }

    /**
     * A table mapped to another connection cannot be covered by one transaction:
     * the call is not run.
     */
    #[Test]
    public function aCallIsNotRunWhereTablesAreMappedToAnotherConnection(): void
    {
        $configuration = $GLOBALS['TYPO3_CONF_VARS'];
        self::assertIsArray($configuration);
        $GLOBALS['TYPO3_CONF_VARS'] = array_replace_recursive($configuration, ['DB' => ['TableMapping' => ['sys_log' => 'Other']]]);
        $ran                        = false;

        try {
            $outcome = $this->call(
                $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
                function () use (&$ran): void {
                    $ran = true;
                },
            );
        } finally {
            $GLOBALS['TYPO3_CONF_VARS'] = $configuration;
        }

        self::assertFalse($ran);
        self::assertStringContainsString('cannot be enforced on this database setup', $outcome['toolResult']);
    }

    /**
     * A page is copied with free-mode translations (a language, no translation
     * parent), and `ignoreLocalization` does not leave them behind.
     */
    #[Test]
    public function aPageCopyIsHeldToTheLanguagesOfFreeModeContentWhateverIgnoreLocalizationSays(): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 43, 'pid' => self::BELOW, 'colPos' => 0, 'CType' => 'text', 'header' => 'Free mode', 'sys_language_uid' => self::GERMAN, 'l18n_parent' => 0, 'sorting' => 1]);

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['pages' => [self::BELOW => ['copy' => ['target' => self::SCOPED, 'ignoreLocalization' => true]]]], $context);
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 1)], [], null, [0]),
        );

        self::assertSame(['copy pages:3 language_not_allowed'], $this->refusals($outcome['trace']));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pages WHERE pid = ?', [self::SCOPED]));
    }

    /**
     * A malformed item is read by core as nothing; nothing is written, so there
     * is nothing for the check of the effect to find.
     */
    #[Test]
    public function aMalformedInlineItemWithoutTheInputCheckWritesNothingTheEffectCheckCouldFind(): void
    {
        $this->unregisterTheDataHandlerLayer();
        $user = $this->setUpBackendUser(1);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['tt_content' => [self::IN_SCOPE_ELEMENT => ['assets' => 'abc']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            user: $user,
        );

        self::assertSame([], $this->refusals($outcome['trace']));
    }

    #[Test]
    public function anUnknownCommandIsRefused(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['somethingNew' => 1]]], $context);
            },
        );

        self::assertSame(['command tt_content:21 unresolvable'], $this->refusals($outcome['trace']));
    }

    #[Test]
    public function theTablesOfTheScopeNarrowTheDataHandlerLayerToo(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Allowed table', $context);
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['pages' => [self::SCOPED => ['title' => 'Not an allowed table']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 1)], [], ['tt_content']),
        );

        self::assertSame('Allowed table', $this->header(self::IN_SCOPE_ELEMENT));
        self::assertSame('Page 2', $this->scalar('SELECT title FROM pages WHERE uid = ?', [self::SCOPED]));
        self::assertSame(['update pages:2 table_not_allowed'], $this->refusals($outcome['trace']));
    }

    #[Test]
    public function aWriteInADraftWorkspaceIsHeldToTheScopeAndAPageCreatedThereIsInIt(): void
    {
        $user = $this->useASessionlessAmbientUser(1);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                // A page of the draft, and content on that page, which exists in no live tree.
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start([
                    'pages'      => ['NEWpage' => ['pid' => self::SCOPED, 'title' => 'Draft page', 'doktype' => 1]],
                    'tt_content' => ['NEWcontent' => ['pid' => 'NEWpage', 'header' => 'On the draft page', 'CType' => 'text']],
                ], [], $context->actingBackendUser());
                $dataHandler->process_datamap();

                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Foreign, in the draft', $context);
            },
            user: $user,
        );

        $draft = $this->connectionPool->getConnectionForTable('pages')->executeQuery('SELECT uid, t3ver_wsid FROM pages WHERE title = ?', ['Draft page'])->fetchAssociative();
        self::assertIsArray($draft, 'the page under the scope was created in the workspace');
        self::assertSame(self::DRAFT, (int) $draft['t3ver_wsid']);
        self::assertSame(1, $this->countByHeader('On the draft page'), 'content on the page the run just created is in scope');
        self::assertSame(0, $this->versionsOf(self::OUTSIDE_ELEMENT), 'no draft of the foreign element was made');
        self::assertSame(['update tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
    }

    #[Test]
    public function theGuardIsReleasedWhenTheToolThrows(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            static function (): never {
                throw new RuntimeException('boom', 1799990300);
            },
        );

        self::assertStringStartsWith('Error: tool "scoped_probe" failed.', $outcome['toolResult']);
        self::assertFalse(AssignmentScopeGuard::isArmed());
        self::assertFalse(ToolChangeCollector::isArmed());
    }

    #[Test]
    public function aThrowingToolThatWasRefusedPartwayStillTellsTheModelWhatTheScopeStopped(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): never {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Refused', $context);

                throw new RuntimeException('boom after the refusal', 1799990301);
            },
        );

        self::assertStringStartsWith('Error: tool "scoped_probe" failed.', $outcome['toolResult']);
        self::assertStringContainsString('refused update of tt_content:23', $outcome['toolResult']);
        self::assertSame(['update tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
    }

    // --- a workspace version is its live record --------------------------------

    #[Test]
    public function aRecordInTheScopeIsUpdatedInADraftWorkspaceWhenOnlyTheRecordIsInTheScope(): void
    {
        $user = $this->setUpBackendUser(1);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Draft of the record', $context);
            },
            scope: new AssignmentScope([], [new RecordReference('tt_content', self::IN_SCOPE_ELEMENT)]),
            user: $user,
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame(1, $this->versionsOf(self::IN_SCOPE_ELEMENT), 'the version stays');
        self::assertSame('Original 21', $this->header(self::IN_SCOPE_ELEMENT), 'live is untouched');
    }

    #[Test]
    public function theTitleOfTheScopedPageItselfIsEditedInADraftWorkspace(): void
    {
        $user = $this->setUpBackendUser(1);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));

        $outcome = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('update', [new RecordReference('pages', self::SCOPED)]),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['pages' => [self::SCOPED => ['title' => 'Draft title']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            user: $user,
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM pages WHERE t3ver_oid = ? AND t3ver_wsid = ?', [self::SCOPED, self::DRAFT]), 'the page has a version in the workspace');
    }

    // --- the transaction of a call ---------------------------------------------

    /**
     * What a call leaves behind in static state: nothing. A guard left armed
     * would hold the next, unrelated write of the process to this call's scope.
     */
    private function assertNothingLeftArmed(): void
    {
        self::assertFalse(AssignmentScopeGuard::isArmed(), 'the scope guard is disarmed');
        self::assertFalse(ToolChangeCollector::isArmed(), 'the change collector is disarmed');
        self::assertFalse(AssignmentScopeTransaction::defer(static function (): void {}), 'no call is open');
    }

    private function transactionIsOpen(): bool
    {
        return $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)->isTransactionActive();
    }

    /**
     * The commit is what makes an in-scope call stand: without it the connection
     * stays inside the transaction the call opened.
     */
    #[Test]
    public function aCallInScopeIsCommittedAndLeavesNoTransactionOpen(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Committed', $context);
            },
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertFalse($this->transactionIsOpen(), 'the call committed');
        self::assertSame('Committed', $this->header(self::IN_SCOPE_ELEMENT));
    }

    #[Test]
    public function aRolledBackCallLeavesNoTransactionOpen(): void
    {
        // Only the effect check can refuse this write, so the call is really rolled back.
        $this->unregisterTheDataHandlerLayer();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);
            },
        );

        self::assertNotSame([], $this->refusals($outcome['trace']));
        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT), 'the write is gone, not committed');
        self::assertFalse($this->transactionIsOpen());
        $this->assertNothingLeftArmed();
    }

    #[Test]
    public function aToolThatThrowsEndsItsTransaction(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): never {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Written before the failure', $context);

                throw new RuntimeException('boom', 1799990310);
            },
        );

        self::assertStringStartsWith('Error: tool "scoped_probe" failed.', $outcome['toolResult']);
        self::assertFalse($this->transactionIsOpen());
        $this->assertNothingLeftArmed();
        self::assertSame('Written before the failure', $this->header(self::IN_SCOPE_ELEMENT), 'what a failed call wrote in scope stands, as before');
    }

    #[Test]
    public function aToolThatThrowsAfterWritingOutsideTheScopeIsRolledBack(): void
    {
        $this->unregisterTheDataHandlerLayer();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): never {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);

                throw new RuntimeException('boom', 1799990311);
            },
        );

        self::assertFalse($this->transactionIsOpen());
        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT));
        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
    }

    #[Test]
    public function aCallThatHandsItsWorkToAnExternalOperationCommitsWhatItWrote(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): never {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Before the hand-over', $context);

                throw new ToolDependencyPendingException(new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'call_1', 'a2a_agent_skill', 7, 3, 'remote-1', 4, 0, 1000, 4600, 1, 512), 'waiting');
            },
            dependencyTool: true,
        );

        self::assertTrue($outcome['suspendedForDependency'], 'a clean call is handed over');
        self::assertFalse($this->transactionIsOpen());
        $this->assertNothingLeftArmed();
        self::assertSame('Before the hand-over', $this->header(self::IN_SCOPE_ELEMENT), 'and what it wrote is committed');
    }

    /**
     * A write outside the scope followed by "my work is handed over" is judged
     * like any other call: rolled back, and the hand-over is not honoured.
     */
    #[Test]
    public function aWriteOutsideTheScopeBeforeADependencyHandOverIsRolledBack(): void
    {
        $this->unregisterTheDataHandlerLayer();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): never {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);

                throw new ToolDependencyPendingException(new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'call_1', 'a2a_agent_skill', 7, 3, 'remote-1', 4, 0, 1000, 4600, 1, 512), 'waiting');
            },
            dependencyTool: true,
        );

        self::assertFalse($outcome['suspendedForDependency'], 'the hand-over is not honoured');
        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT), 'nothing outside the scope was committed');
        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertNotSame([], $this->refusals($outcome['trace']));
        self::assertFalse($this->transactionIsOpen());
        $this->assertNothingLeftArmed();
    }

    #[Test]
    public function aWriteTheDataHandlerLayerRefusedBeforeADependencyHandOverIsReported(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): never {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);

                throw new ToolDependencyPendingException(new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'call_1', 'a2a_agent_skill', 7, 3, 'remote-1', 4, 0, 1000, 4600, 1, 512), 'waiting');
            },
            dependencyTool: true,
        );

        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT));
        self::assertSame(['update tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
        self::assertFalse($this->transactionIsOpen());
    }

    // --- the ways a transaction can end, and what the model is told ------------

    /**
     * A statement that commits on its own ends the transaction behind DBAL's back,
     * as TRUNCATE does on MySQL and MariaDB. Issued by hand, it does the same on
     * every database the tests run on.
     */
    private function commitBehindTheBack(): void
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->executeStatement('COMMIT');
        // PDO before PHP 8.4 keeps its own flag for an open transaction and cannot
        // roll back once the database has none; a transaction opened by hand keeps
        // the flag true, as on the databases that end one on their own.
        $connection->executeStatement('BEGIN');
    }

    /**
     * A statement that rolls the transaction back behind DBAL's back, as a hook
     * of the installation could. A transaction is opened by hand afterwards, for
     * the reason {@see self::commitBehindTheBack()} gives.
     */
    private function rollBackBehindTheBack(): void
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $connection->executeStatement('ROLLBACK');
        $connection->executeStatement('BEGIN');
    }

    /**
     * The database committed the call before the call ended (NEXT-240): its
     * writes are stored, so the call is reported as it ran, and the flush it
     * asked for runs. The log says that this call could not have been rolled
     * back.
     */
    #[Test]
    public function aCommitByTheDatabaseIsReportedAsTheCallRan(): void
    {
        $flushed              = 0;
        $logger               = $this->recordingLogger();
        $this->loggerOverride = $logger;

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context) use (&$flushed): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Saved by the database', $context);
                AssignmentScopeTransaction::defer(static function () use (&$flushed): void {
                    ++$flushed;
                });
                $this->commitBehindTheBack();
            },
        );

        self::assertSame('ok', $outcome['toolResult']);
        self::assertFalse($this->transactionIsOpen(), 'DBAL is back in line with the database');
        self::assertSame('Saved by the database', $this->header(self::IN_SCOPE_ELEMENT));
        self::assertSame(1, $flushed, 'the flush the call asked for still runs: the connection is free');
        self::assertCount(1, array_filter($logger->records, static fn (string $record): bool => str_starts_with($record, 'warning: The database committed the transaction of a scoped tool call')));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM sys_registry WHERE entry_namespace = ?', ['tx_nrllm_scope']), 'no marker stays behind');
        $this->assertNothingLeftArmed();
    }

    /**
     * The transaction was rolled back behind the call's back, and a write the
     * DataHandler did not report (so the effect check had nothing to judge) was
     * made before: what is stored is not known, and the call is not reported as
     * a success.
     */
    #[Test]
    public function aCommitThatCannotBeConfirmedIsNotReportedAsSuccess(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (): void {
                $this->connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['header' => 'Rolled back by the database'], ['uid' => self::IN_SCOPE_ELEMENT]);
                $this->rollBackBehindTheBack();
            },
        );

        self::assertStringContainsString('Error: tool "scoped_probe" ended with its changes NOT committed as such', $outcome['toolResult']);
        self::assertFalse($this->transactionIsOpen(), 'DBAL is back in line with the database');
        self::assertSame('Original ' . self::IN_SCOPE_ELEMENT, $this->header(self::IN_SCOPE_ELEMENT));
        $this->assertNothingLeftArmed();
    }

    #[Test]
    public function aRollbackThatCannotHappenIsNotReportedAsOne(): void
    {
        $this->unregisterTheDataHandlerLayer();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);
                $this->commitBehindTheBack();
            },
        );

        self::assertStringContainsString('were NOT rolled back', $outcome['toolResult']);
        self::assertStringNotContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertSame('Outside', $this->header(self::OUTSIDE_ELEMENT), 'what the database committed stands, and the result says so');
        self::assertFalse($this->transactionIsOpen());
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM sys_registry WHERE entry_namespace = ?', ['tx_nrllm_scope']), 'the committed marker does not stay behind');
    }

    /**
     * Whatever escapes the loop's own bookkeeping after the tool has run, the
     * transaction is not left open: here the logger throws while the loop reports
     * a failed tool.
     */
    #[Test]
    public function aThrowAfterTheToolHasRunStillEndsTheTransaction(): void
    {
        $this->loggerOverride = new class extends AbstractLogger {
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('the log is full', 1799990320);
            }
        };

        try {
            $this->call(
                $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
                function (ToolExecutionContext $context): never {
                    $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Not judged', $context);

                    throw new RuntimeException('boom', 1799990321);
                },
            );
            self::fail('the failure of the logger escapes');
        } catch (RuntimeException $escaped) {
            self::assertSame('the log is full', $escaped->getMessage());
        }

        self::assertFalse($this->transactionIsOpen(), 'the transaction was ended');
        self::assertSame('Original 21', $this->header(self::IN_SCOPE_ELEMENT), 'what nothing judged is taken back');
        $this->assertNothingLeftArmed();

        // A later, unrelated write of the same process is not judged by the scope
        // of the call that threw.
        $this->loggerOverride = null;
        $user                 = $GLOBALS['BE_USER'];
        self::assertInstanceOf(BackendUserAuthentication::class, $user);
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['tt_content' => [self::OUTSIDE_ELEMENT => ['header' => 'Unrelated write']]], [], $user);
        $dataHandler->process_datamap();
        self::assertSame('Unrelated write', $this->header(self::OUTSIDE_ELEMENT), 'no stale scope refused it');
    }

    /**
     * The flush a call asked for runs as the call did: a hook of the flush finds
     * the acting user in a command-line worker, which has none of its own.
     */
    #[Test]
    public function aDeferredFlushSeesTheActingUserInAWorker(): void
    {
        $this->writeEnvironmentOverride = new WorkerWriteEnvironment($this->getService(LanguageServiceFactory::class), true);
        $acting                         = $this->setUpBackendUser(1);
        unset($GLOBALS['BE_USER']);
        $seen = 'not run';

        $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context) use (&$seen): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Flushing', $context);
                AssignmentScopeTransaction::defer(static function () use (&$seen): void {
                    $seen = $GLOBALS['BE_USER'] ?? null;
                });
            },
            user: $acting,
        );

        self::assertSame($acting, $seen);
        self::assertArrayNotHasKey('BE_USER', $GLOBALS, 'the worker has no user of its own again afterwards');
    }

    #[Test]
    public function aCallInsideACallKeepsItsFlushesForTheOutermostAndDropsThemOnRollback(): void
    {
        $order = [];

        $outer = AssignmentScopeTransaction::begin();
        AssignmentScopeTransaction::defer(static function () use (&$order): void {
            $order[] = 'outer';
        });
        $kept = AssignmentScopeTransaction::begin();
        AssignmentScopeTransaction::defer(static function () use (&$order): void {
            $order[] = 'inner kept';
        });
        $kept->commit();
        self::assertSame([], $kept->deferred(), 'a nested call hands its flushes up');

        $dropped = AssignmentScopeTransaction::begin();
        AssignmentScopeTransaction::defer(static function () use (&$order): void {
            $order[] = 'inner dropped';
        });
        $dropped->rollBack();
        self::assertSame([], $dropped->deferred());

        self::assertTrue($this->transactionIsOpen(), 'the outer call is still open');
        $outer->commit();
        foreach ($outer->deferred() as $flush) {
            $flush();
        }

        self::assertSame(['outer', 'inner kept'], $order, 'in the order they were asked for, none of a rolled-back call');
        self::assertFalse($this->transactionIsOpen());
        self::assertFalse(AssignmentScopeTransaction::defer(static function (): void {}), 'no call is open any more');
    }

    private function recordingLogger(): RecordingLogger
    {
        return new RecordingLogger();
    }

    /**
     * A call that went well is ended once. A transaction ended twice makes the
     * second end fail, which the loop reports at error level.
     */
    #[Test]
    public function aCallThatWentWellReportsNothingToTheLog(): void
    {
        $logger               = $this->recordingLogger();
        $this->loggerOverride = $logger;

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Quietly committed', $context);
            },
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame([], $logger->records, 'neither an error nor a warning');
        self::assertSame('Quietly committed', $this->header(self::IN_SCOPE_ELEMENT));
    }

    #[Test]
    public function aFlushThatFailsAfterTheCommitDoesNotUndoOrFailTheCall(): void
    {
        $logger               = $this->recordingLogger();
        $this->loggerOverride = $logger;

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Committed, flush fails', $context);
                AssignmentScopeTransaction::defer(static function (): never {
                    throw new RuntimeException('the cache is gone', 1799990330);
                });
            },
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame('Committed, flush fails', $this->header(self::IN_SCOPE_ELEMENT));
        self::assertSame(['warning: A cache flush a scoped tool call asked for failed.'], $logger->records);
        $this->assertNothingLeftArmed();
    }

    #[Test]
    public function aPendingCallCommittedByTheDatabaseIsSuspended(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): never {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Saved before the hand-over', $context);
                $this->commitBehindTheBack();

                throw new ToolDependencyPendingException(new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'call_1', 'a2a_agent_skill', 7, 3, 'remote-1', 4, 0, 1000, 4600, 1, 512), 'waiting');
            },
            dependencyTool: true,
        );

        self::assertTrue($outcome['suspendedForDependency'], 'the changes are stored, so the run waits for the hand-over');
        self::assertSame('Saved before the hand-over', $this->header(self::IN_SCOPE_ELEMENT));
        self::assertFalse($this->transactionIsOpen());
        $this->assertNothingLeftArmed();
    }

    #[Test]
    public function aPendingCallWhoseCommitCannotBeConfirmedIsNotSuspended(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (): never {
                $this->connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['header' => 'Rolled back before the hand-over'], ['uid' => self::IN_SCOPE_ELEMENT]);
                $this->rollBackBehindTheBack();

                throw new ToolDependencyPendingException(new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'call_1', 'a2a_agent_skill', 7, 3, 'remote-1', 4, 0, 1000, 4600, 1, 512), 'waiting');
            },
            dependencyTool: true,
        );

        self::assertFalse($outcome['suspendedForDependency'], 'the run is not suspended on changes that may not be saved');
        self::assertStringContainsString('NOT committed as such', $outcome['toolResult']);
        self::assertFalse($this->transactionIsOpen());
        $this->assertNothingLeftArmed();
    }

    #[Test]
    public function aPendingCallThatCannotBeRolledBackIsNotReportedAsRolledBack(): void
    {
        $this->unregisterTheDataHandlerLayer();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): never {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);
                $this->commitBehindTheBack();

                throw new ToolDependencyPendingException(new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'call_1', 'a2a_agent_skill', 7, 3, 'remote-1', 4, 0, 1000, 4600, 1, 512), 'waiting');
            },
            dependencyTool: true,
        );

        self::assertStringContainsString('were NOT rolled back', $outcome['toolResult']);
        self::assertStringNotContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertSame('Outside', $this->header(self::OUTSIDE_ELEMENT));
        $this->assertNothingLeftArmed();
    }

    #[Test]
    public function aThrowingCallThatCannotBeRolledBackIsNotReportedAsRolledBack(): void
    {
        $this->unregisterTheDataHandlerLayer();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): never {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);
                $this->commitBehindTheBack();

                throw new RuntimeException('boom', 1799990331);
            },
        );

        self::assertStringContainsString('were NOT rolled back', $outcome['toolResult']);
        self::assertStringNotContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertSame('Outside', $this->header(self::OUTSIDE_ELEMENT));
        $this->assertNothingLeftArmed();
    }

    // --- the DataHandler's own cache clearing waits for the commit --------------

    /**
     * The page of the scope gets the clear-cache command, and the page cache of
     * the installation (a null backend in the tests) is swapped for one in a
     * database table, so that a flush is a TRUNCATE where the database commits on
     * it. The swap is undone by {@see self::restorePageCache()}.
     *
     * @return array{FrontendInterface, FrontendInterface|null} the database cache and the one it replaced
     */
    private function givePageTheClearCacheCommand(): array
    {
        $this->connectionPool->getConnectionForTable('pages')->update('pages', ['TSconfig' => 'TCEMAIN.clearCacheCmd = pages'], ['uid' => self::SCOPED]);

        $manager = $this->getContainer()->get(CacheManager::class);
        self::assertInstanceOf(CacheManager::class, $manager);
        $database = $this->databaseCacheManager(['pages'])->getCache('pages');
        $property = new ReflectionProperty(CacheManager::class, 'caches');
        $manager->getCache('pages');
        $caches = $property->getValue($manager);
        self::assertIsArray($caches);
        $replaced        = $caches['pages'];
        $caches['pages'] = $database;
        $property->setValue($manager, $caches);

        return [$database, $replaced instanceof FrontendInterface ? $replaced : null];
    }

    private function restorePageCache(?FrontendInterface $original): void
    {
        $manager  = $this->getContainer()->get(CacheManager::class);
        $property = new ReflectionProperty(CacheManager::class, 'caches');
        $caches   = $property->getValue($manager);
        if (is_array($caches) && $original instanceof FrontendInterface) {
            $caches['pages'] = $original;
            $property->setValue($manager, $caches);
        }
    }

    /**
     * With `TCEMAIN.clearCacheCmd = pages` on the scoped page, core's DataHandler
     * flushes the page cache group after every write: a truncate, which MySQL and
     * MariaDB commit on their own. It waits for the call's commit instead, so the
     * call commits and is reported as a success, and the flush then happens.
     */
    #[Test]
    public function aClearCacheCommandOfThePageRunsAfterTheCommitOfAnInScopeCall(): void
    {
        $seenWhileOpen      = null;
        [$cache, $original] = $this->givePageTheClearCacheCommand();
        $cache->set('nrllm_scope_probe', 'cached', ['nrllm_scope_probe']);
        $logger               = $this->recordingLogger();
        $this->loggerOverride = $logger;

        try {
            $outcome = $this->call(
                $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
                function (ToolExecutionContext $context) use ($cache, &$seenWhileOpen): void {
                    $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Page flush after commit', $context);
                    $seenWhileOpen = $cache->get('nrllm_scope_probe');
                },
            );
        } finally {
            $this->restorePageCache($original);
        }

        self::assertSame('cached', $seenWhileOpen, 'not flushed while the call is open');
        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame('ok', $outcome['toolResult'], 'reported as a success');
        self::assertSame([], $logger->records);
        self::assertSame('Page flush after commit', $this->header(self::IN_SCOPE_ELEMENT));
        self::assertFalse($cache->has('nrllm_scope_probe'), 'the page cache was flushed, after the commit');
        self::assertFalse($this->transactionIsOpen());
    }

    #[Test]
    public function aClearCacheCommandOfThePageIsDroppedWhenTheCallIsRolledBack(): void
    {
        $this->unregisterTheDataHandlerLayer();
        [$cache, $original] = $this->givePageTheClearCacheCommand();
        $cache->set('nrllm_scope_probe', 'cached', ['nrllm_scope_probe']);
        $before = $this->fingerprint();

        try {
            $outcome = $this->call(
                $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
                function (ToolExecutionContext $context): void {
                    $this->updateHeader(self::IN_SCOPE_ELEMENT, 'In scope', $context);
                    $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);
                },
            );
        } finally {
            $this->restorePageCache($original);
        }

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertSame($before, $this->fingerprint(), 'the database is as before');
        self::assertSame('cached', $cache->get('nrllm_scope_probe'), 'a rolled-back call flushed nothing');
        self::assertFalse($this->transactionIsOpen());
    }

    /**
     * A check that cannot read what the call wrote has not cleared it: the call
     * is rolled back, and the transaction is not left open.
     */
    #[Test]
    public function anExceptionInTheEffectCheckRollsTheCallBackAndLeavesNoTransactionOpen(): void
    {
        $this->unregisterTheDataHandlerLayer();
        $this->checkerOverride = new AssignmentScopeChecker(new class implements RecordTopologyInterface {
            public function row(string $table, int $uid, int $workspace): ?RecordFacts
            {
                throw new RuntimeException('the database went away', 1799990312);
            }

            public function languagesOnPage(int $pageUid, int $workspace): array
            {
                return [];
            }

            public function storedValue(string $table, int $uid, string $field): ?string
            {
                return null;
            }

            public function translationLanguages(string $table, int $uid, int $workspace): array
            {
                return [];
            }

            public function sourcedTranslationLanguages(string $table, int $uid, int $workspace): array
            {
                return [];
            }

            public function descendantPages(int $pageUid, int $workspace, int $limit): ?array
            {
                return null;
            }
        });

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Not judged', $context);
            },
        );

        self::assertFalse($this->transactionIsOpen(), 'the transaction ended');
        self::assertSame('Original 21', $this->header(self::IN_SCOPE_ELEMENT), 'what could not be judged was rolled back');
        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
    }

    // --- a row the effect check cannot read (#1434) ---------------------------

    /**
     * @return iterable<string, array{int, array<string, int>, int, int, int}>
     */
    public static function unreadableCommands(): iterable
    {
        // the element, its command, its `deleted` before the call, the
        // workspace, the request for tt_content that fails (1 the row, 2 the
        // version of a draft)
        yield 'delete, live' => [self::OUTSIDE_ELEMENT, ['delete' => 1], 0, 0, 1];
        yield 'undelete, live' => [self::OUTSIDE_ELEMENT, ['undelete' => 1], 1, 0, 1];
        yield 'delete in a draft, the version cannot be read' => [self::OUTSIDE_ELEMENT, ['delete' => 1], 0, self::DRAFT, 2];
    }

    /**
     * A delete or undelete outside the scope whose row, or whose draft version,
     * the effect check cannot read is not cleared by it: the call is rolled
     * back. The
     * history, which would name the record otherwise, is gone (pruned), so the
     * reads are all the check has. Only the hook layer is taken away, so the
     * command reaches the DataHandler.
     *
     * @param array<string, int> $command
     */
    #[Test]
    #[DataProvider('unreadableCommands')]
    public function aCommandWhoseRowTheEffectCheckCannotReadIsRolledBack(int $element, array $command, int $deletedBefore, int $workspace, int $failingRead): void
    {
        $this->unregisterTheDataHandlerLayer();
        $this->connectionPool->getConnectionForTable('tt_content')->update('tt_content', ['deleted' => $deletedBefore], ['uid' => $element]);
        $user = $this->setUpBackendUser(1);
        if ($workspace > 0) {
            self::assertTrue($user->setTemporaryWorkspace($workspace));
        }

        $before = $this->fingerprint();
        $pool   = FailsOneReadConnectionPool::failing('tt_content', $failingRead, $failingRead);

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($element, $command, $pool): void {
                $this->command(['tt_content' => [$element => $command]], $context);
                $this->forgetTheHistory();
                // The pool the effect check takes next reads everything but tt_content.
                GeneralUtility::addInstance(ConnectionPool::class, $pool);
            },
            user: $user,
        );

        self::assertSame(1, $pool->failed, 'the effect check read the element through the failing pool');
        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertSame($before, $this->fingerprint(), 'the command is gone with the rollback');
    }

    /**
     * The control of the test above: with the same failing read, a move out of
     * the scope is rolled back too, and a command for a uid that no row bears,
     * read with a working pool, is still no change: it does not roll back the
     * call's write inside the scope.
     */
    #[Test]
    public function aMoveWhoseRowCannotBeReadIsRolledBackAndADeleteOfNoRowIsNot(): void
    {
        $this->unregisterTheDataHandlerLayer();
        $before = $this->fingerprint();
        $pool   = FailsOneReadConnectionPool::failing('tt_content', 1, 1);

        $moved = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($pool): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['move' => self::OTHER]]], $context);
                $this->forgetTheHistory();
                GeneralUtility::addInstance(ConnectionPool::class, $pool);
            },
        );

        self::assertSame(1, $pool->failed, 'the effect check read the element through the failing pool');
        self::assertStringContainsString(self::ROLLED_BACK, $moved['toolResult']);
        self::assertSame($before, $this->fingerprint(), 'the move is gone with the rollback');

        $kept = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Kept', $context);
                $this->command(['tt_content' => [9999 => ['delete' => 1]]], $context);
            },
        );

        self::assertSame('ok', $kept['toolResult']);
        self::assertSame('Kept', $this->header(self::IN_SCOPE_ELEMENT), 'a delete of no row is not a write outside the scope');
    }

    /**
     * A move that core makes live from a draft workspace, on a table without
     * versioning, inside the scope: the effect check looks for a version only
     * where the table keeps versions. A query for the version columns of such a
     * table fails, and on PostgreSQL a failed statement ends the call's open
     * transaction, so every later read of the check failed and the move was
     * rolled back although it lay in the scope.
     */
    #[Test]
    public function aMoveOfATableWithoutVersioningInsideTheScopeIsKeptInADraftWorkspace(): void
    {
        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => 3, 'pid' => 0, 'title' => 'Live editing', 'members' => '', 'live_edit' => 1]);
        // Website users are allowed in a folder.
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', ['uid' => 90, 'pid' => self::SCOPED, 'title' => 'Users', 'doktype' => 254, 'perms_userid' => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL]);
        $this->connectionPool->getConnectionForTable('fe_users')->insert('fe_users', ['uid' => 1, 'pid' => self::SCOPED, 'username' => 'visitor']);
        $user = $this->setUpBackendUser(1);
        self::assertTrue($user->setTemporaryWorkspace(3));

        $outcome = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('move', [new RecordReference('fe_users', 1)], [new ScopedDestination('fe_users', 90)]),
            function (ToolExecutionContext $context): void {
                $this->command(['fe_users' => [1 => ['move' => 90]]], $context);
            },
            user: $user,
        );

        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame(90, $this->pidOf('fe_users', 1), 'the move was made live and kept');
    }

    /**
     * A delete that core makes live from a draft workspace, on a table without
     * versioning, outside the scope: no version is written, so the effect check
     * judges the live row. It took the missing version as a delete that did not
     * happen and committed the call. Only the hook layer is taken away, so the
     * command reaches the DataHandler.
     */
    #[Test]
    public function aDeleteOfATableWithoutVersioningOutsideTheScopeIsRolledBackInADraftWorkspace(): void
    {
        $this->unregisterTheDataHandlerLayer();
        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => 3, 'pid' => 0, 'title' => 'Live editing', 'members' => '', 'live_edit' => 1]);
        $this->connectionPool->getConnectionForTable('fe_users')->insert('fe_users', ['uid' => 2, 'pid' => self::OTHER, 'username' => 'elsewhere']);
        $user = $this->setUpBackendUser(1);
        self::assertTrue($user->setTemporaryWorkspace(3));

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context): void {
                $this->command(['fe_users' => [2 => ['delete' => 1]]], $context);
            },
            user: $user,
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        $deleted = $this->connectionPool->getConnectionForTable('fe_users')->select(['deleted'], 'fe_users', ['uid' => 2])->fetchOne();
        self::assertSame(0, (int) $deleted, 'the delete is gone with the rollback');
    }

    /**
     * A delete from a draft workspace of a live row outside the scope that has
     * a move draft there: core discards the draft and writes no version
     * (`DataHandler::deleteAction()`). The effect check found no version and
     * took the delete as one that did not happen, so the draft outside the
     * scope was discarded. Only the hook layer is taken away, so the command
     * reaches the DataHandler.
     */
    #[Test]
    public function aDeleteThatDiscardsADraftOutsideTheScopeIsRolledBack(): void
    {
        $this->unregisterTheDataHandlerLayer();
        $user = $this->setUpBackendUser(1);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], ['tt_content' => [self::OUTSIDE_ELEMENT => ['move' => self::OTHER]]], $user);
        $dataHandler->process_cmdmap();
        self::assertSame(1, $this->versionsOf(self::OUTSIDE_ELEMENT), 'positive control: the move draft exists');
        $before = $this->fingerprint();

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context): void {
                $this->command(['tt_content' => [self::OUTSIDE_ELEMENT => ['delete' => 1]]], $context);
            },
            user: $user,
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertSame($before, $this->fingerprint(), 'the move draft survives the rollback');
    }

    /**
     * A call whose history cannot be read from its start is not cleared: the
     * check does not know what the history would have named, so the call is
     * rolled back, a write inside the scope included.
     */
    #[Test]
    public function aCallWhoseHistoryStartCannotBeReadIsNotCleared(): void
    {
        $scope = new AssignmentScope([new ScopedPage(self::SCOPED, 2)]);

        // A frame as a call's first write leaves it: the history start taken
        // (or not) and one write inside the scope.
        $violations = static function (?ConnectionPool $pool) use ($scope): array {
            ToolChangeCollector::arm();
            try {
                if ($pool instanceof ConnectionPool) {
                    GeneralUtility::addInstance(ConnectionPool::class, $pool);
                }

                ToolChangeCollector::beforeWrite();
                ToolChangeCollector::record('tt_content', self::IN_SCOPE_ELEMENT, ChangeAction::UPDATED, 0, null);

                return array_map(static fn (array $found): string => $found['refusal']->reason, AssignmentScopeEffect::violations($scope, new AssignmentScopeChecker(), 0));
            } finally {
                ToolChangeCollector::take();
            }
        };

        $pool = FailsOneReadConnectionPool::failing('sys_history');
        self::assertSame([ScopeRefusal::UNRESOLVABLE], $violations($pool), 'not judgeable, so rolled back');
        self::assertSame(1, $pool->failed, 'the read of the history start failed');
        self::assertSame([], $violations(null), 'positive control: the same write with its history start read is cleared');
    }

    /**
     * Takes away the history rows of the call, inside its transaction: the
     * effect check then has only the hooks' records and the rows.
     */
    private function forgetTheHistory(): void
    {
        $this->connectionPool->getConnectionForTable('sys_history')->executeStatement('DELETE FROM sys_history');
    }

    // --- discard, version and publish (#1442) --------------------------------

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function discardsOfAMovedDraft(): iterable
    {
        // the command, and whether it names the draft (true) or the live row
        yield 'discard command' => [['discard' => 1], false];
        yield 'version clearWSID' => [['version' => ['action' => 'clearWSID']], false];
        yield 'version flush' => [['version' => ['action' => 'flush']], false];
        yield 'delete of the draft' => [['delete' => 1], true];
    }

    /**
     * The case of the issue: a draft that moved a record outside the scope onto
     * a page inside it. Both layers before the effect check read the draft's
     * position and let the discard through; the discard puts the record back at
     * its live row, outside the scope, and leaves nothing behind to read. The
     * call is rolled back, and the draft survives.
     *
     * @param array<string, mixed> $command
     */
    #[Test]
    #[DataProvider('discardsOfAMovedDraft')]
    public function aDiscardOfADraftWhoseLiveRowIsOutsideTheScopeIsRolledBack(array $command, bool $namesTheDraft): void
    {
        $this->skipTheDiscardCommandBeforeTypo3Fourteen($command);
        $user  = $this->userInTheDraft();
        $draft = $this->moveAsADraft(self::OUTSIDE_ELEMENT, self::SCOPED, $user);
        self::assertSame(self::SCOPED, $this->pidOf('tt_content', $draft), 'positive control: the draft lies inside the scope');
        $before = $this->fingerprint();

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($command, $namesTheDraft, $draft): void {
                $this->command(['tt_content' => [$namesTheDraft ? $draft : self::OUTSIDE_ELEMENT => $command]], $context);
            },
            user: $user,
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertContains('effect_discard tt_content:' . self::OUTSIDE_ELEMENT . ' outside_scope', $this->refusals($outcome['trace']), 'judged by its live row');
        self::assertSame([], array_values(array_filter($this->refusals($outcome['trace']), static fn (string $line): bool => !str_starts_with($line, 'effect_'))), 'the layers before the effect check let the discard through');
        self::assertSame($before, $this->fingerprint(), 'the draft survives the rollback');
        self::assertSame(1, $this->versionsOf(self::OUTSIDE_ELEMENT));
    }

    /**
     * The control: the same discards of a draft whose live row lies inside the
     * scope are kept.
     *
     * @param array<string, mixed> $command
     */
    #[Test]
    #[DataProvider('discardsOfAMovedDraft')]
    public function aDiscardOfADraftWhoseLiveRowIsInsideTheScopeIsKept(array $command, bool $namesTheDraft): void
    {
        $this->skipTheDiscardCommandBeforeTypo3Fourteen($command);
        $user  = $this->userInTheDraft();
        $draft = $this->moveAsADraft(self::IN_SCOPE_ELEMENT, self::BELOW, $user);

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($command, $namesTheDraft, $draft): void {
                $this->command(['tt_content' => [$namesTheDraft ? $draft : self::IN_SCOPE_ELEMENT => $command]], $context);
            },
            user: $user,
        );

        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame(0, $this->versionsOf(self::IN_SCOPE_ELEMENT), 'the draft is discarded');
    }

    /**
     * A discard of a new page in a draft takes the new pages below it along,
     * which no command names and which are gone afterwards: the page two
     * levels below the scoped one lies beyond a scope of depth 1. The discard
     * is judged where core carries it out, before the rows are gone.
     */
    #[Test]
    public function aDiscardThatTakesANewSubpageBeyondTheScopeAlongIsRolledBack(): void
    {
        $user               = $this->userInTheDraft();
        [$parent, $subpage] = $this->newPageWithASubpageAsADraft($user);
        $before             = $this->fingerprint();

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($parent): void {
                $this->command(['pages' => [$parent => ['version' => ['action' => 'clearWSID']]]], $context);
            },
            user: $user,
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertContains('effect_discard pages:' . $subpage . ' outside_scope', $this->refusals($outcome['trace']));
        self::assertSame($before, $this->fingerprint(), 'both drafts survive the rollback');

        // The control: a scope that reaches the subpage keeps the discard.
        $kept = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($parent): void {
                $this->command(['pages' => [$parent => ['version' => ['action' => 'clearWSID']]]], $context);
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 2)]),
            user: $user,
        );

        self::assertSame('ok', $kept['toolResult']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pages WHERE uid IN (?, ?)', [$parent, $subpage]), 'both drafts are discarded');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publishActions(): iterable
    {
        yield 'publish' => ['publish'];
        yield 'swap' => ['swap'];
    }

    /**
     * A publish puts the draft where the live row is. A draft that moved a
     * record from outside the scope onto a page inside it takes the record away
     * from its live position outside the scope, which no layer before the
     * effect check reads. The call is rolled back; the control, a draft moved
     * inside the scope, is published.
     */
    #[Test]
    #[DataProvider('publishActions')]
    public function aPublishOfADraftWhoseLiveRowIsOutsideTheScopeIsRolledBack(string $action): void
    {
        $user   = $this->userInTheDraft();
        $draft  = $this->moveAsADraft(self::OUTSIDE_ELEMENT, self::SCOPED, $user);
        $before = $this->fingerprint();

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($draft, $action): void {
                $this->command(['tt_content' => [self::OUTSIDE_ELEMENT => ['version' => ['action' => $action, 'swapWith' => $draft]]]], $context);
            },
            user: $user,
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertContains('effect_publish tt_content:' . self::OUTSIDE_ELEMENT . ' outside_scope', $this->refusals($outcome['trace']));
        self::assertSame($before, $this->fingerprint(), 'the record stays where it is live, and the draft survives');

        $inside = $this->moveAsADraft(self::IN_SCOPE_ELEMENT, self::BELOW, $user);
        $kept   = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($inside, $action): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['version' => ['action' => $action, 'swapWith' => $inside]]]], $context);
            },
            user: $user,
        );

        self::assertSame('ok', $kept['toolResult']);
        self::assertSame([], $this->refusals($kept['trace']));
        self::assertSame(self::BELOW, $this->pidOf('tt_content', self::IN_SCOPE_ELEMENT), 'the move is published');
        self::assertContains('tt_content:' . self::IN_SCOPE_ELEMENT . ' updated', $this->listedChanges($kept['trace']), 'the publish is listed as an update of the live row');
    }

    /**
     * A stage change writes the draft. With the DataHandler layer taken away,
     * one of a draft outside the scope is found by the effect check, through
     * the record the change list keeps of the command, and rolled back.
     */
    #[Test]
    public function aStageChangeOfADraftOutsideTheScopeIsRolledBackByTheEffectCheck(): void
    {
        $user  = $this->userInTheDraft();
        $draft = $this->moveAsADraft(self::OUTSIDE_ELEMENT, self::OTHER, $user);

        $this->assertTheEffectCheckRollsBack(
            function (ToolExecutionContext $context) use ($draft): void {
                $this->command(['tt_content' => [$draft => ['version' => ['action' => 'setStage', 'stageId' => 1]]]], $context);
            },
            true,
        );
    }

    /**
     * A `version` action neither core nor the workspaces extension knows is
     * handled, if at all, by code nobody judged: the call is rolled back.
     */
    #[Test]
    public function aVersionActionThatIsNotKnownIsRolledBack(): void
    {
        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Changed in scope', $context);
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['version' => ['action' => 'promote']]]], $context);
            },
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertContains('effect_version tt_content:' . self::IN_SCOPE_ELEMENT . ' unresolvable', $this->refusals($outcome['trace']));
        self::assertSame('Original ' . self::IN_SCOPE_ELEMENT, $this->header(self::IN_SCOPE_ELEMENT));
    }

    /**
     * Core makes a version with a `version` command of its own for the first
     * edit of a record in a draft. A record on a page that the draft moved
     * into the scope lies in the scope there, and its first edit is kept,
     * although its live row lies outside: a new version writes the draft only.
     */
    #[Test]
    public function theFirstEditOfARecordOnAPageMovedIntoTheScopeIsKept(): void
    {
        $user        = $this->userInTheDraft();
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], ['pages' => [self::OTHER => ['move' => self::SCOPED]]], $user);
        $dataHandler->process_cmdmap();
        self::assertSame(0, $this->versionsOf(self::OUTSIDE_ELEMENT), 'positive control: the element has no draft yet');

        $outcome = $this->call(
            $this->claimingRecords([self::OUTSIDE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Edited in the draft', $context);
            },
            user: $user,
        );

        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame(1, $this->versionsOf(self::OUTSIDE_ELEMENT), 'the edit made a draft of the element');
    }

    /**
     * A publish core refuses, here of a draft that belongs to another record,
     * changes nothing, and is not listed as an update.
     */
    #[Test]
    public function aPublishCoreRefusesIsNotListed(): void
    {
        $user  = $this->userInTheDraft();
        $other = $this->moveAsADraft(self::BELOW_ELEMENT, self::SCOPED, $user);

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($other): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['version' => ['action' => 'publish', 'swapWith' => $other]]]], $context);
            },
            user: $user,
        );

        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame(1, $this->versionsOf(self::BELOW_ELEMENT), 'positive control: the draft was not published');
        self::assertSame([], $this->listedChanges($outcome['trace']), 'nothing is listed');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function waysToAKeyThatIsAList(): iterable
    {
        yield 'map written after start()' => [false];
        yield 'map replaced by a later hook' => [true];
    }

    /**
     * `DataHandler::start()` takes integer keys only, and the workspaces
     * extension's `processCmdmap_beforeStart` fails on any other, also when
     * code writes the public map after `start()`; but a hook
     * registered after it can replace the public command map. TYPO3 13.4 hands
     * a key that is a list of uids on as it is, and a stage change reads every
     * uid from it, so the stage of a draft outside the scope changed beside one
     * inside. TYPO3 14.3 reads the first uid only.
     */
    #[Test]
    #[DataProvider('waysToAKeyThatIsAList')]
    public function aCommandForAListOfUidsChangesNothingOutsideTheScope(bool $byALaterHook): void
    {
        $user    = $this->userInTheDraft();
        $inside  = $this->moveAsADraft(self::IN_SCOPE_ELEMENT, self::BELOW, $user);
        $outside = $this->moveAsADraft(self::OUTSIDE_ELEMENT, self::OTHER, $user);
        $cmdmap  = ['tt_content' => [$inside . ',' . $outside => ['version' => ['action' => 'setStage', 'stageId' => 1]]]];
        if ($byALaterHook) {
            $this->registerHook('processCmdmapClass', ReplacesTheCommandMapHook::class);
        }

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($inside, $cmdmap, $byALaterHook): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start([], ['tt_content' => [$inside => ['version' => ['action' => 'setStage', 'stageId' => 1]]]], $context->actingBackendUser());
                if ($byALaterHook) {
                    ReplacesTheCommandMapHook::$cmdmap = $cmdmap;
                } else {
                    // The map is public: written after `start()` checked it.
                    $dataHandler->cmdmap = $cmdmap;
                }

                $dataHandler->process_cmdmap();
            },
            user: $user,
        );

        self::assertSame(0, (int) $this->scalar('SELECT t3ver_stage FROM tt_content WHERE uid = ?', [$outside]), 'the stage outside the scope did not change');
        // A key written after `start()` fails in the workspaces extension's
        // own `processCmdmap_beforeStart` (13.4.40 and 14.3.11), so the call
        // fails before core runs a command; a later hook's key reaches core.
        if ($byALaterHook && (new Typo3Version())->getMajorVersion() < 14) {
            self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
            self::assertContains('effect_command tt_content (on page 0) unresolvable', $this->refusals($outcome['trace']));
        }
    }

    /**
     * A publish of a page the draft moved deeper takes its subpages along. The
     * page lies in the scope where it is live and where the draft put it, but
     * its subpage lies beyond the scope's depth there: the call is rolled
     * back. The control: a scope deep enough keeps it.
     */
    #[Test]
    public function aPublishOfAPageMoveThatTakesASubpageBeyondTheScopeIsRolledBack(): void
    {
        $pages = $this->connectionPool->getConnectionForTable('pages');
        foreach ([[70, self::SCOPED], [71, 70]] as [$uid, $pid]) {
            $pages->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'doktype' => 1, 'slug' => '/' . $uid, 'sorting' => 256, 'perms_userid' => 1, 'perms_user' => Permission::ALL, 'perms_everybody' => Permission::ALL]);
        }

        $user        = $this->userInTheDraft();
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], ['pages' => [70 => ['move' => self::BELOW]]], $user);
        $dataHandler->process_cmdmap();

        $draft = (int) $this->scalar('SELECT uid FROM pages WHERE t3ver_oid = ? AND t3ver_wsid = ?', [70, self::DRAFT]);
        self::assertGreaterThan(0, $draft, 'positive control: the move draft exists');
        $publish = function (ToolExecutionContext $context) use ($draft): void {
            $this->command(['pages' => [70 => ['version' => ['action' => 'publish', 'swapWith' => $draft]]]], $context);
        };
        $before = $this->fingerprint();

        $outcome = $this->call(static fn (): ScopedWrite => ScopedWrite::nothing(), $publish, scope: new AssignmentScope([new ScopedPage(self::SCOPED, 2)]), user: $user);

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertContains('effect_publish pages:71 subtree_outside_scope', $this->refusals($outcome['trace']));
        self::assertSame($before, $this->fingerprint());

        $kept = $this->call(static fn (): ScopedWrite => ScopedWrite::nothing(), $publish, scope: new AssignmentScope([new ScopedPage(self::SCOPED, 3)]), user: $user);

        self::assertSame('ok', $kept['toolResult']);
        self::assertSame(self::BELOW, $this->pidOf('pages', 70), 'the move is published');
    }

    /**
     * A publish of a record new in the draft makes the draft its live row: it
     * is listed as an update and kept when it lies in the scope.
     */
    #[Test]
    public function aPublishOfANewRecordInsideTheScopeIsKeptAndListed(): void
    {
        $user        = $this->userInTheDraft();
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['tt_content' => ['NEW1' => ['pid' => self::SCOPED, 'header' => 'New in the draft', 'CType' => 'text']]], [], $user);
        $dataHandler->process_datamap();

        $new = (int) ($dataHandler->substNEWwithIDs['NEW1'] ?? 0);
        self::assertSame(self::DRAFT, (int) $this->scalar('SELECT t3ver_wsid FROM tt_content WHERE uid = ?', [$new]), 'positive control: a draft');

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($new): void {
                $this->command(['tt_content' => [$new => ['version' => ['action' => 'publish', 'swapWith' => $new]]]], $context);
            },
            user: $user,
        );

        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame(0, (int) $this->scalar('SELECT t3ver_wsid FROM tt_content WHERE uid = ?', [$new]), 'published');
        self::assertContains('tt_content:' . $new . ' updated', $this->listedChanges($outcome['trace']));
    }

    /**
     * A record new in the draft, on a page the draft moved into the scope,
     * lands where that page is live when it is published: outside the scope.
     * The call is rolled back.
     */
    #[Test]
    public function aPublishOfANewRecordOnAPageMovedIntoTheScopeIsRolledBack(): void
    {
        $user        = $this->userInTheDraft();
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], ['pages' => [self::OTHER => ['move' => self::SCOPED]]], $user);
        $dataHandler->process_cmdmap();

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['tt_content' => ['NEW1' => ['pid' => self::OTHER, 'header' => 'New on the moved page', 'CType' => 'text']]], [], $user);
        $dataHandler->process_datamap();

        $new    = (int) ($dataHandler->substNEWwithIDs['NEW1'] ?? 0);
        $before = $this->fingerprint();

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($new): void {
                $this->command(['tt_content' => [$new => ['version' => ['action' => 'publish', 'swapWith' => $new]]]], $context);
            },
            user: $user,
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertContains('effect_publish tt_content:' . $new . ' outside_scope', $this->refusals($outcome['trace']));
        self::assertSame($before, $this->fingerprint());
    }

    /**
     * A publish of a page's delete draft deletes the pages below it live. The
     * page lies in the scope; the page below it lies beyond the scope's
     * depth, and its delete is found in the history: the call is rolled back.
     */
    #[Test]
    public function aPublishOfAPageDeleteThatTakesAPageBeyondTheScopeAlongIsRolledBack(): void
    {
        $user        = $this->userInTheDraft();
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], ['pages' => [self::BELOW => ['delete' => 1]]], $user);
        $dataHandler->process_cmdmap();

        $draft = (int) $this->scalar('SELECT uid FROM pages WHERE t3ver_oid = ? AND t3ver_wsid = ?', [self::BELOW, self::DRAFT]);
        self::assertGreaterThan(0, $draft, 'positive control: the delete draft exists');
        $before = $this->fingerprint();

        $publish = function (ToolExecutionContext $context) use ($draft): void {
            $this->command(['pages' => [self::BELOW => ['version' => ['action' => 'publish', 'swapWith' => $draft]]]], $context);
        };

        $outcome = $this->call(static fn (): ScopedWrite => ScopedWrite::nothing(), $publish, user: $user);

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertContains('effect_deleted pages:' . self::TOO_DEEP . ' outside_scope', $this->refusals($outcome['trace']), 'the delete of the page below, from the history');
        self::assertSame($before, $this->fingerprint(), 'the pages stand');

        // The control: a scope that reaches the page below keeps the publish.
        $kept = $this->call(static fn (): ScopedWrite => ScopedWrite::nothing(), $publish, scope: new AssignmentScope([new ScopedPage(self::SCOPED, 2)]), user: $user);

        self::assertSame('ok', $kept['toolResult']);
        self::assertSame(2, $this->goneCount('pages', [self::BELOW, self::TOO_DEEP]), 'both pages are deleted live');
    }

    /**
     * A record the draft moved onto a page that the draft moved into the
     * scope lands, when only the record is published, on that page where it
     * is live: outside the scope. The call is rolled back.
     */
    #[Test]
    public function aPublishOfAMoveOntoAPageMovedIntoTheScopeIsRolledBack(): void
    {
        $user        = $this->userInTheDraft();
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], ['pages' => [self::OTHER => ['move' => self::SCOPED]]], $user);
        $dataHandler->process_cmdmap();

        $draft  = $this->moveAsADraft(self::IN_SCOPE_ELEMENT, self::OTHER, $user);
        $before = $this->fingerprint();

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($draft): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['version' => ['action' => 'publish', 'swapWith' => $draft]]]], $context);
            },
            user: $user,
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertContains('effect_publish tt_content (on page ' . self::OTHER . ') outside_scope', $this->refusals($outcome['trace']));
        self::assertSame($before, $this->fingerprint());
    }

    /**
     * The workspaces extension reads `swapWith` as an integer, so `'Nabc'`
     * publishes draft N. A call in the live workspace that publishes a draft
     * moving a record out of the scope that way is rolled back: the draft is
     * judged as core reads it.
     */
    #[Test]
    public function aPublishWhoseDraftIsNamedAsCoreReadsItIsJudged(): void
    {
        $draft  = $this->moveAsADraft(self::IN_SCOPE_ELEMENT, self::OTHER, $this->userInTheDraft());
        $before = $this->fingerprint();

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context) use ($draft): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['version' => ['action' => 'publish', 'swapWith' => $draft . 'abc']]]], $context);
            },
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertSame($before, $this->fingerprint(), 'the element stays where it is live');
    }

    /**
     * A publish without a draft to swap with, and a stage change of a row that
     * is no draft, are refused by core and change nothing: neither is listed.
     */
    #[Test]
    public function aPublishOrStageChangeCoreRefusesIsNotListed(): void
    {
        $user = $this->userInTheDraft();

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context): void {
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['version' => ['action' => 'publish', 'swapWith' => 'invalid']]]], $context);
                $this->command(['tt_content' => [self::BELOW_ELEMENT => ['version' => ['action' => 'setStage', 'stageId' => 1]]]], $context);
            },
            user: $user,
        );

        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame([], $this->listedChanges($outcome['trace']), 'nothing is listed');
    }

    /**
     * What the hook noted before a tool threw is judged on that way out of
     * the call too: the discard is rolled back.
     */
    #[Test]
    public function aDiscardOutsideTheScopeBeforeTheToolThrowsIsRolledBack(): void
    {
        $user = $this->userInTheDraft();
        $this->moveAsADraft(self::OUTSIDE_ELEMENT, self::SCOPED, $user);
        $before = $this->fingerprint();

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context): never {
                $this->command(['tt_content' => [self::OUTSIDE_ELEMENT => ['version' => ['action' => 'clearWSID']]]], $context);

                throw new RuntimeException('after the discard', 1799990452);
            },
            user: $user,
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertSame($before, $this->fingerprint(), 'the draft survives');
    }

    /**
     * A row no uid bears has no stored value: the hook reads that as "no
     * change" and not as a row it could not judge.
     */
    #[Test]
    public function aStoredValueOfARowThatIsGoneIsNull(): void
    {
        $topology = new DatabaseRecordTopology($this->connectionPool);

        self::assertNull($topology->storedValue('tt_content', 99999, 't3ver_wsid'));
        self::assertSame('0', $topology->storedValue('tt_content', self::IN_SCOPE_ELEMENT, 't3ver_wsid'), 'positive control');
    }

    /**
     * A new version a later hook put into the command map is judged where the
     * draft lies, as the command check would: one of a record outside the
     * scope is rolled back.
     */
    #[Test]
    public function aNewVersionAHookAddsOutsideTheScopeIsRolledBack(): void
    {
        $user = $this->userInTheDraft();
        $this->registerHook('processCmdmapClass', ReplacesTheCommandMapHook::class);

        $outcome = $this->call(
            static fn (): ScopedWrite => ScopedWrite::nothing(),
            function (ToolExecutionContext $context): void {
                ReplacesTheCommandMapHook::$cmdmap = ['tt_content' => [self::OUTSIDE_ELEMENT => ['version' => ['action' => 'new']]]];
                $this->command(['tt_content' => [self::IN_SCOPE_ELEMENT => ['version' => ['action' => 'setStage', 'stageId' => 1]]]], $context);
            },
            user: $user,
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertContains('effect_version tt_content:' . self::OUTSIDE_ELEMENT . ' outside_scope', $this->refusals($outcome['trace']));
        self::assertSame(0, $this->versionsOf(self::OUTSIDE_ELEMENT));
    }

    /**
     * The records the run's changes steps list, with their action.
     *
     * @return list<string>
     */
    private function listedChanges(RunTrace $trace): array
    {
        $listed = [];
        foreach ($trace->getSteps() as $step) {
            foreach ($step->kind === RunStep::KIND_CHANGES && $step->observedChanges instanceof ObservedChangeSet ? $step->observedChanges->changes : [] as $change) {
                $listed[] = $change->record . ' ' . $change->action->value;
            }
        }

        return $listed;
    }

    /**
     * @param array<string, mixed> $command
     */
    private function skipTheDiscardCommandBeforeTypo3Fourteen(array $command): void
    {
        if (array_key_exists('discard', $command) && (new Typo3Version())->getMajorVersion() < 14) {
            self::markTestSkipped('TYPO3 13.4 has no discard command.');
        }
    }

    private function userInTheDraft(): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser(1);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));

        return $user;
    }

    /**
     * Moves an element in the draft workspace, outside any call, and returns
     * the uid of the draft.
     */
    private function moveAsADraft(int $element, int $page, BackendUserAuthentication $user): int
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], ['tt_content' => [$element => ['move' => $page]]], $user);
        $dataHandler->process_cmdmap();

        $draft = (int) $this->scalar('SELECT uid FROM tt_content WHERE t3ver_oid = ? AND t3ver_wsid = ?', [$element, self::DRAFT]);
        self::assertGreaterThan(0, $draft, 'positive control: the move draft exists');

        return $draft;
    }

    /**
     * A new page under the scoped page and a new page below it, both in the
     * draft workspace, made outside any call.
     *
     * @return array{int, int} the two pages
     */
    private function newPageWithASubpageAsADraft(BackendUserAuthentication $user): array
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['pages' => [
            'NEW1' => ['pid' => self::SCOPED, 'title' => 'New in the draft', 'doktype' => 1],
            'NEW2' => ['pid' => 'NEW1', 'title' => 'New below it', 'doktype' => 1],
        ]], [], $user);
        $dataHandler->process_datamap();

        $parent  = (int) ($dataHandler->substNEWwithIDs['NEW1'] ?? 0);
        $subpage = (int) ($dataHandler->substNEWwithIDs['NEW2'] ?? 0);
        self::assertGreaterThan(0, $parent);
        self::assertSame($parent, $this->pidOf('pages', $subpage), 'positive control: the subpage lies below the new page');
        self::assertSame(self::DRAFT, (int) $this->scalar('SELECT t3ver_wsid FROM pages WHERE uid = ?', [$subpage]), 'positive control: both are drafts');

        return [$parent, $subpage];
    }

    // --- the listeners of a write run under the scope (#1438) ---------------

    /**
     * @return iterable<string, array{bool}>
     */
    public static function withAndWithoutTheDataHandlerLayer(): iterable
    {
        yield 'DataHandler layer registered' => [true];
        yield 'effect check alone' => [false];
    }

    /**
     * A listener of the call's write event stores a record outside the scope
     * with a DataHandler run of its own, as AI Label stores its metadata. The
     * listener ran after the commit and outside the scope, so its write stood.
     * It now runs under the scope, inside the call's transaction: the whole call
     * is rolled back, and the model is told which record and which rule.
     */
    #[Test]
    #[DataProvider('withAndWithoutTheDataHandlerLayer')]
    public function aListenerWriteOutsideTheScopeRollsTheCallBack(bool $dataHandlerLayer): void
    {
        if (!$dataHandlerLayer) {
            $this->unregisterTheDataHandlerLayer();
        }

        $user = $this->useASessionlessAmbientUser(1);
        $this->listenToTheWrite(function () use ($user): void {
            $this->updateHeaderAs(self::OUTSIDE_ELEMENT, 'By a listener', $user);
        });
        $before = $this->fingerprint();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Changed in scope', $context);
            },
            user: $user,
            wrote: new RecordReference('tt_content', self::IN_SCOPE_ELEMENT),
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertStringContainsString('code of this TYPO3 installation that runs after such a write wrote outside it: tt_content:' . self::OUTSIDE_ELEMENT . ' (outside_scope)', $outcome['toolResult']);
        self::assertSame($before, $this->fingerprint(), "the call's own write in scope is gone with the listener's");
        self::assertSame('Original ' . self::OUTSIDE_ELEMENT, $this->header(self::OUTSIDE_ELEMENT));
    }

    /**
     * The control: a listener write inside the scope is kept and listed with
     * the call's changes.
     */
    #[Test]
    public function aListenerWriteInsideTheScopeIsKeptAndListed(): void
    {
        $user = $this->useASessionlessAmbientUser(1);
        $this->listenToTheWrite(function () use ($user): void {
            $this->updateHeaderAs(self::BELOW_ELEMENT, 'By a listener', $user);
        });

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Changed in scope', $context);
            },
            user: $user,
            wrote: new RecordReference('tt_content', self::IN_SCOPE_ELEMENT),
        );

        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame('By a listener', $this->header(self::BELOW_ELEMENT));
        self::assertContains('tt_content:' . self::BELOW_ELEMENT, $this->listed($outcome['trace']));
    }

    /**
     * An integrator allows one listener write outside the scope explicitly. It
     * is kept, listed and not judged. The same allowance in the tool's own
     * execution changes nothing: the write is refused there as any other.
     */
    #[Test]
    public function aListenerWriteAllowedOutsideTheScopeIsKeptAndTheAllowanceHoldsForListenersOnly(): void
    {
        $user = $this->useASessionlessAmbientUser(1);
        $this->listenToTheWrite(function () use ($user): void {
            WriteOutsideTheAssignmentScope::run('test log outside the assignment', function () use ($user): void {
                $this->updateHeaderAs(self::OUTSIDE_ELEMENT, 'Allowed by the installation', $user);
            });
        });

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Changed in scope', $context);
            },
            user: $user,
            wrote: new RecordReference('tt_content', self::IN_SCOPE_ELEMENT),
        );

        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame('Allowed by the installation', $this->header(self::OUTSIDE_ELEMENT));
        self::assertSame('Changed in scope', $this->header(self::IN_SCOPE_ELEMENT));
        self::assertContains('tt_content:' . self::OUTSIDE_ELEMENT, $this->listed($outcome['trace']), 'the allowed write is listed');

        $this->eventDispatcherOverride = null;
        $inTheTool                     = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                WriteOutsideTheAssignmentScope::run('not a listener', function () use ($context): void {
                    $this->updateHeader(self::OUTSIDE_ELEMENT, 'From the tool', $context);
                });
            },
            user: $user,
        );

        self::assertContains('update tt_content:' . self::OUTSIDE_ELEMENT . ' outside_scope', $this->refusals($inTheTool['trace']));
        self::assertSame('Allowed by the installation', $this->header(self::OUTSIDE_ELEMENT));
    }

    /**
     * What AI Label does: a listener stores a field on the draft the call
     * wrote, naming the draft's uid, in a scope of the default language on a
     * page with a German translation. TYPO3 14 adds an entry for the
     * translation to that write; it is left out as for the call's own write,
     * and the call is kept.
     */
    #[Test]
    public function aListenerWriteToTheDraftTheCallWroteIsKept(): void
    {
        $this->insertAGermanOverlayOfTheScopedPage();
        $user = $this->setUpBackendUser(1);
        self::assertTrue($user->setTemporaryWorkspace(self::DRAFT));
        $this->listenToTheWrite(function () use ($user): void {
            $draft       = (int) $this->scalar('SELECT uid FROM pages WHERE t3ver_oid = ? AND t3ver_wsid = ?', [self::SCOPED, self::DRAFT]);
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start(['pages' => [$draft => ['abstract' => 'Stored by a listener']]], [], $user);
            $dataHandler->process_datamap();
        });

        $outcome = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('update', [new RecordReference('pages', self::SCOPED)]),
            function (ToolExecutionContext $context): void {
                $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
                $dataHandler->start(['pages' => [self::SCOPED => ['description' => 'Written by the call']]], [], $context->actingBackendUser());
                $dataHandler->process_datamap();
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 0)], [], ['pages'], [0]),
            user: $user,
            wrote: new RecordReference('pages', self::SCOPED),
        );

        self::assertSame('ok', $outcome['toolResult']);
        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame('Stored by a listener', $this->scalar('SELECT abstract FROM pages WHERE t3ver_oid = ? AND t3ver_wsid = ?', [self::SCOPED, self::DRAFT]));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pages WHERE t3ver_oid = ?', [self::OVERLAY]), 'the translation got no version');
    }

    /**
     * A dispatcher that hands the call's write event to $listener.
     *
     * @param Closure(AfterAiRecordWrittenEvent): void $listener
     */
    private function listenToTheWrite(Closure $listener): void
    {
        $this->eventDispatcherOverride = new class ($listener) implements EventDispatcherInterface {
            /**
             * @param Closure(AfterAiRecordWrittenEvent): void $listener
             */
            public function __construct(private readonly Closure $listener) {}

            public function dispatch(object $event): object
            {
                if ($event instanceof AfterAiRecordWrittenEvent) {
                    ($this->listener)($event);
                }

                return $event;
            }
        };
    }

    private function updateHeaderAs(int $uid, string $header, BackendUserAuthentication $user): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [$uid => ['header' => $header]]], [], $user);
        $dataHandler->process_datamap();
    }

    /**
     * The records the run's changes steps list.
     *
     * @return list<string>
     */
    private function listed(RunTrace $trace): array
    {
        $listed = [];
        foreach ($trace->getSteps() as $step) {
            foreach ($step->kind === RunStep::KIND_CHANGES && $step->observedChanges instanceof ObservedChangeSet ? $step->observedChanges->changes : [] as $change) {
                $listed[] = (string) $change->record;
            }
        }

        return $listed;
    }

    // --- cache flushes wait for the commit ------------------------------------

    /**
     * @return iterable<string, array{bool}>
     */
    public static function flushes(): iterable
    {
        yield 'call in scope' => [false];
        yield 'call writes outside the scope' => [true];
    }

    /**
     * A cache group flush truncates tables, which MySQL and MariaDB commit on
     * their own, so inside a call's transaction it is deferred: run after the
     * commit, dropped on a rollback.
     */
    #[Test]
    #[DataProvider('flushes')]
    public function aCacheFlushOfACallRunsAfterTheCommitAndNeverAfterARollback(bool $writesOutside): void
    {
        $this->unregisterTheDataHandlerLayer();
        $flushedInsideTheTransaction = null;
        $flushes                     = 0;

        $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context) use ($writesOutside, &$flushes, &$flushedInsideTheTransaction): void {
                $this->updateHeader($writesOutside ? self::OUTSIDE_ELEMENT : self::IN_SCOPE_ELEMENT, 'Flushing', $context);
                self::assertTrue(AssignmentScopeTransaction::defer(function () use (&$flushes, &$flushedInsideTheTransaction): void {
                    ++$flushes;
                    $flushedInsideTheTransaction = $this->transactionIsOpen();
                }), 'a call is open');
                self::assertSame(0, $flushes, 'not run while the call is open');
            },
        );

        self::assertSame($writesOutside ? 0 : 1, $flushes);
        self::assertSame($writesOutside ? null : false, $flushedInsideTheTransaction, 'the flush found the connection outside any transaction');
        self::assertFalse(AssignmentScopeTransaction::defer(static function (): void {}), 'no call is open any more');
    }

    #[Test]
    public function theCacheToolFlushesAGroupOnlyAfterTheCommit(): void
    {
        $this->unregisterTheDataHandlerLayer();
        $user = $this->useASessionlessAmbientUser(1);

        $tool = new ClearCacheTool(
            $this->getService(CacheManager::class),
            new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
        );
        $transaction = AssignmentScopeTransaction::begin();
        $result      = $tool->execute(['group' => 'system'], new ToolExecutionContext(AiActorContext::fromBackendUser($user), $user, new AgentRunReference(1, 'run')));
        self::assertFalse($result->isError, $result->content);
        self::assertTrue($this->transactionIsOpen(), 'the flush did not end the transaction');
        $transaction->commit();
        self::assertCount(1, $transaction->deferred(), 'the flush waits for the commit');
    }

    /**
     * On MySQL and MariaDB a statement that commits implicitly ends the call's
     * transaction behind DBAL's back. A call that did that and also wrote outside
     * the scope cannot be rolled back, and must not be reported as rolled back.
     * Not run on a database where truncating a table is an ordinary statement.
     */
    #[Test]
    public function aCommitOfTheDatabaseInsideTheCallIsReportedAsNotRolledBack(): void
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        if (!str_contains(strtolower($connection->getDatabasePlatform()::class), 'mysql') && !str_contains(strtolower($connection->getDatabasePlatform()::class), 'mariadb')) {
            self::markTestSkipped('Only MySQL and MariaDB commit on TRUNCATE.');
        }

        $this->unregisterTheDataHandlerLayer();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context) use ($connection): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);
                $connection->truncate('cache_pages');
            },
        );

        self::assertStringContainsString('NOT rolled back', $outcome['toolResult']);
        self::assertStringNotContainsString('were rolled back', $outcome['toolResult']);
        self::assertFalse($this->transactionIsOpen(), 'DBAL is back in line with the server');
    }

    /**
     * The clear-cache tool flushes the page caches, which are database tables
     * here, so on MySQL and MariaDB a flush inside the call would commit it. Run
     * through the real tool next to a write outside the scope: the database is
     * unchanged, and the flush did not happen.
     */
    #[Test]
    public function aCacheFlushNextToAWriteOutsideTheScopeLeavesTheDatabaseUnchanged(): void
    {
        $this->unregisterTheDataHandlerLayer();
        $cacheManager = $this->databaseCacheManager();
        $cache        = $cacheManager->getCache('pages');
        $cache->set('nrllm_scope_probe', 'cached', ['nrllm_scope_probe']);

        $before = $this->fingerprint();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context) use ($cacheManager): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);
                $result = (new ClearCacheTool($cacheManager, new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class))))->execute(['group' => 'system'], $context);
                self::assertFalse($result->isError, $result->content);
            },
        );

        self::assertStringContainsString(self::ROLLED_BACK, $outcome['toolResult']);
        self::assertSame($before, $this->fingerprint());
        self::assertSame('cached', $cache->get('nrllm_scope_probe'), 'a rolled-back call flushed nothing');
    }

    /**
     * The test installation keeps its caches in a null backend, which flushes
     * nothing. This manager holds one cache in a database table, in the group the
     * tool flushes, so a flush is a TRUNCATE on MySQL and MariaDB.
     *
     * @param list<string> $groups
     */
    private function databaseCacheManager(array $groups = ['system']): CacheManager
    {
        $schema = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)->createSchemaManager();
        if (!$schema->tablesExist(['cache_pages'])) {
            $cache = new Table('cache_pages');
            $cache->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $cache->addColumn('identifier', Types::STRING, ['length' => 250]);
            $cache->addColumn('expires', Types::INTEGER, ['default' => 0]);
            $cache->addColumn('content', Types::BLOB, ['notnull' => false]);
            $cache->setPrimaryKey(['id']);
            $schema->createTable($cache);
            $tags = new Table('cache_pages_tags');
            $tags->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
            $tags->addColumn('identifier', Types::STRING, ['length' => 250]);
            $tags->addColumn('tag', Types::STRING, ['length' => 250]);
            $tags->setPrimaryKey(['id']);
            $schema->createTable($tags);
        }

        $manager = new CacheManager();
        $manager->setCacheConfigurations(['pages' => ['frontend' => VariableFrontend::class, 'backend' => Typo3DatabaseBackend::class, 'options' => [], 'groups' => $groups]]);

        $cache = $manager->getCache('pages');
        self::assertInstanceOf(Typo3DatabaseBackend::class, $cache->getBackend());

        return $manager;
    }

    #[Test]
    public function aCacheFlushOfACommittedCallThroughTheToolHappensAfterTheCommit(): void
    {
        $this->unregisterTheDataHandlerLayer();
        $cacheManager = $this->databaseCacheManager();
        $cache        = $cacheManager->getCache('pages');
        $cache->set('nrllm_scope_probe', 'cached', ['nrllm_scope_probe']);

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context) use ($cacheManager, $cache): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'In scope', $context);
                $result = (new ClearCacheTool($cacheManager, new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class))))->execute(['group' => 'system'], $context);
                self::assertFalse($result->isError, $result->content);
                self::assertSame('cached', $cache->get('nrllm_scope_probe'), 'not flushed while the call is open');
            },
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertFalse($cache->has('nrllm_scope_probe'), 'flushed after the commit');
        self::assertSame('In scope', $this->header(self::IN_SCOPE_ELEMENT));
        self::assertFalse($this->transactionIsOpen());
    }

    #[Test]
    public function aRecordWithAllLanguagesIsCreatedWhenTheScopeDoesNotRestrictLanguages(): void
    {
        $outcome = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('create', [], [new ScopedDestination('tt_content', self::SCOPED)]),
            function (ToolExecutionContext $context): void {
                $this->create(['pid' => self::SCOPED, 'header' => 'For all languages', 'CType' => 'text', 'sys_language_uid' => -1], $context);
            },
        );

        self::assertSame([], $this->refusals($outcome['trace']));
        self::assertSame(1, $this->countByHeader('For all languages'));
    }

    #[Test]
    public function aRecordWithAllLanguagesIsRefusedWhenTheScopeRestrictsLanguages(): void
    {
        $outcome = $this->call(
            static fn (): ScopedWrite => new ScopedWrite('create', [], [new ScopedDestination('tt_content', self::SCOPED)]),
            function (ToolExecutionContext $context): void {
                $this->create(['pid' => self::SCOPED, 'header' => 'For all languages', 'CType' => 'text', 'sys_language_uid' => -1], $context);
            },
            scope: new AssignmentScope([new ScopedPage(self::SCOPED, 1)], [], null, [0]),
        );

        self::assertNotSame([], $this->refusals($outcome['trace']));
        self::assertSame(0, $this->countByHeader('For all languages'));
    }

    // --- scaffold -----------------------------------------------------------

    private function insertAFile(): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', ['uid' => 1, 'pid' => 0, 'name' => 'fileadmin', 'driver' => 'Local', 'is_online' => 1, 'is_browsable' => 1, 'is_writable' => 1, 'configuration' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="basePath"><value index="vDEF">fileadmin/</value></field><field index="pathType"><value index="vDEF">relative</value></field></language></sheet></data></T3FlexForms>']);
        $this->connectionPool->getConnectionForTable('sys_file')->insert('sys_file', ['uid' => 1, 'pid' => 0, 'storage' => 1, 'identifier' => '/a.jpg', 'name' => 'a.jpg', 'type' => 2, 'identifier_hash' => 'a', 'folder_hash' => 'a', 'sha1' => 'a', 'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'size' => 1]);
    }

    /**
     * @param list<int> $elements content elements the claim names
     *
     * @return Closure(array<string, mixed>): ?ScopedWrite
     */
    private function claimingRecords(array $elements): Closure
    {
        return static fn (): ScopedWrite => new ScopedWrite('update', array_map(static fn (int $uid): RecordReference => new RecordReference('tt_content', $uid), $elements));
    }

    /**
     * Runs one call of a scoped write tool through the real loop. The call is
     * approved when the loop asks, unless $approve says the test expects the
     * loop to refuse it first.
     *
     * @param Closure(array<string, mixed>): ?ScopedWrite $claim
     * @param Closure(ToolExecutionContext): void         $act
     * @param RecordReference|null                        $wrote the record the tool reports as written, which the loop announces
     *
     * @return array{trace: RunTrace, toolResult: string, suspendedForDependency: bool}
     */
    private function call(Closure $claim, Closure $act, ?AssignmentScope $scope = new AssignmentScope([new ScopedPage(self::SCOPED, 1)]), bool $required = false, bool $approve = true, ?BackendUserAuthentication $user = null, bool $dependencyTool = false, ?RecordReference $wrote = null): array
    {
        $user ??= $this->useASessionlessAmbientUser(1);
        $run = static function (array $arguments, ToolExecutionContext $context) use ($act, $wrote): ToolResult {
            $act($context);

            return $wrote instanceof RecordReference ? ToolResult::text('ok')->withWriteTarget($wrote, WriteKind::UPDATED, WriteCompleteness::COMPLETE) : ToolResult::text('ok');
        };
        $tool = $dependencyTool ? new ScopedWriteFakeDependencyTool('scoped_probe', $claim, $run) : new ScopedWriteFakeTool('scoped_probe', $claim, $run);

        $configuration = $this->configuration($required);
        $context       = new ToolExecutionContext(AiActorContext::fromBackendUser($user)->withAssignmentScope($scope), $user, new AgentRunReference(1, 'run'));
        $trace         = new RunTrace();
        $call          = new ToolCall('call_1', 'scoped_probe', []);

        $result                 = null;
        $suspendedForDependency = false;
        try {
            $result = $this->service([$this->response('', [$call]), $this->response('done')], $tool)
                ->runLoop([['role' => 'user', 'content' => 'go']], $configuration, $context, null, runTrace: $trace);
        } catch (ToolDependencyRequiredException) {
            // The run was handed over to the external operation, as a dependency
            // tool asks: nothing is left to resume in the test.
            $suspendedForDependency = true;
        } catch (ToolApprovalRequiredException $suspended) {
            self::assertTrue($approve, 'the loop asked for an approval of a call the test expected it to refuse');
            try {
                $result = $this->service([$this->response('applied')], $tool)->resume($suspended->state, true, $configuration, $context, runTrace: $trace);
            } catch (ToolDependencyRequiredException) {
                $suspendedForDependency = true;
            }
        }

        if (!$suspendedForDependency) {
            self::assertInstanceOf(ToolLoopResult::class, $result);
        }

        $steps = array_values(array_filter($trace->getSteps(), static fn (RunStep $step): bool => $step->kind === RunStep::KIND_TOOL));
        self::assertNotSame([], $steps, 'the call was traced');

        return ['trace' => $trace, 'toolResult' => (string) $steps[0]->toolResult, 'suspendedForDependency' => $suspendedForDependency];
    }

    /**
     * What the run's changes steps say the scope refused, one line each.
     *
     * @return list<string>
     */
    private function refusals(RunTrace $trace): array
    {
        $lines = [];
        foreach ($trace->getSteps() as $step) {
            if ($step->kind !== RunStep::KIND_CHANGES || !$step->observedChanges instanceof ObservedChangeSet) {
                continue;
            }

            foreach ($step->observedChanges->refused as $refusal) {
                self::assertInstanceOf(ScopeRefusal::class, $refusal);
                $lines[] = $refusal->operation . ' ' . $refusal->subject() . ' ' . $refusal->reason;
            }
        }

        return $lines;
    }

    /**
     * @param list<CompletionResponse> $queue
     */
    private function service(array $queue, ToolInterface $tool): ToolLoopService
    {
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('chatWithToolsForConfiguration')->willReturnCallback(static function () use (&$queue): CompletionResponse {
            $next = array_shift($queue);
            if (!$next instanceof CompletionResponse) {
                throw new RuntimeException('Scripted response queue underflow.', 1799990302);
            }

            return $next;
        });

        $registry = new ToolRegistry([$tool]);
        $policy   = new ToolCallPolicy(
            $registry,
            new ToolAvailabilityService($registry, new ToolStateRepository($this->connectionPool), new ToolGroupStateRepository($this->connectionPool)),
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        return new ToolLoopService(
            $manager,
            $registry,
            $policy,
            $this->loggerOverride ?? new NullLogger(),
            previewTranslator: new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
            invocationPolicy: new ToolInvocationPolicy([new RequireAssignmentScopeRule($registry, new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)))]),
            writeEnvironment: $this->writeEnvironmentOverride,
            eventDispatcher: $this->eventDispatcherOverride,
            scopeChecker: $this->checkerOverride,
        );
    }

    private function configuration(bool $requireAssignmentScope): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);
        $configuration->setRequireAssignmentScope($requireAssignmentScope);

        return $configuration;
    }

    /**
     * @param list<ToolCall>|null $toolCalls
     */
    private function response(string $content, ?array $toolCalls = null): CompletionResponse
    {
        return new CompletionResponse($content, 'test-model', UsageStatistics::fromTokens(0, 0), toolCalls: $toolCalls);
    }

    private function unregisterTheDataHandlerLayer(): void
    {
        $this->unregisterHook('processDatamapClass', AssignmentScopeHook::class);
        $this->unregisterHook('processCmdmapClass', AssignmentScopeHook::class);
    }

    private function updateHeader(int $uid, string $header, ToolExecutionContext $context): void
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['tt_content' => [$uid => ['header' => $header]]], [], $context->actingBackendUser());
        $dataHandler->process_datamap();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function create(array $fields, ToolExecutionContext $context): void
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['tt_content' => ['NEW1' => $fields]], [], $context->actingBackendUser());
        $dataHandler->process_datamap();
    }

    private function createPage(int $parent, string $title, ToolExecutionContext $context): void
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start(['pages' => ['NEW1' => ['pid' => $parent, 'title' => $title, 'doktype' => 1]]], [], $context->actingBackendUser());
        $dataHandler->process_datamap();
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $cmdmap
     */
    private function command(array $cmdmap, ToolExecutionContext $context): void
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], $cmdmap, $context->actingBackendUser());
        $dataHandler->process_cmdmap();
    }

    private function header(int $uid): string
    {
        $header = $this->scalar('SELECT header FROM tt_content WHERE uid = ?', [$uid]);

        return is_string($header) ? $header : '';
    }

    private function pidOf(string $table, int $uid): int
    {
        return (int) $this->scalar('SELECT pid FROM ' . $table . ' WHERE uid = ?', [$uid]);
    }

    private function countByHeader(string $header): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM tt_content WHERE header = ?', [$header]);
    }

    private function countPages(string $title): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM pages WHERE title = ?', [$title]);
    }

    /**
     * A German overlay of the scoped page, beside it as core puts one.
     */
    /**
     * @param array<string, mixed> $fields set on the overlay besides the defaults
     */
    private function insertAGermanOverlayOfTheScopedPage(array $fields = []): void
    {
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', $fields + [
            'uid'              => self::OVERLAY, 'pid' => 1, 'title' => 'Seite 2', 'doktype' => 1, 'slug' => '/2',
            'sys_language_uid' => self::GERMAN, 'l10n_parent' => self::SCOPED, 'l10n_source' => self::SCOPED, 'sorting' => self::SCOPED * 256,
            'perms_userid'     => 1, 'perms_user' => Permission::ALL,
            'perms_groupid'    => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
    }

    /**
     * The rows of a page and its workspace versions, as they are stored.
     *
     * @return list<array<string, mixed>>
     */
    private function pageRows(int $uid): array
    {
        return $this->connectionPool->getConnectionForTable('pages')
            ->executeQuery('SELECT * FROM pages WHERE uid = ? OR t3ver_oid = ? ORDER BY uid', [$uid, $uid])
            ->fetchAllAssociative();
    }

    private function translationsOf(int $uid): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM tt_content WHERE l18n_parent = ? AND sys_language_uid = ?', [$uid, self::GERMAN]);
    }

    private function versionsOf(int $uid): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM tt_content WHERE t3ver_oid = ?', [$uid]);
    }

    /**
     * How many of the rows are marked deleted. Read with a query of its own:
     * the connection's helpers leave out deleted and hidden rows, which is what
     * a page just created or deleted is.
     *
     * @param list<int> $uids
     */
    private function goneCount(string $table, array $uids): int
    {
        $count = 0;
        foreach ($uids as $uid) {
            $count += (int) $this->scalar('SELECT COUNT(*) FROM ' . $table . ' WHERE uid = ? AND deleted = 1', [$uid]);
        }

        return $count;
    }

    /**
     * @param list<int|string> $parameters
     */
    private function scalar(string $sql, array $parameters): mixed
    {
        return $this->connectionPool->getConnectionForTable('pages')->executeQuery($sql, $parameters)->fetchOne();
    }
}

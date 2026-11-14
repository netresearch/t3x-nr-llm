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
use Netresearch\NrLlm\Domain\Enum\TrustZone;
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
use Netresearch\NrLlm\Service\Tool\Rule\RequireAssignmentScopeRule;
use Netresearch\NrLlm\Service\Tool\RunTrace;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeChecker;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeGuard;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeHook;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeTransaction;
use Netresearch\NrLlm\Service\Tool\Scope\RecordFacts;
use Netresearch\NrLlm\Service\Tool\Scope\RecordTopologyInterface;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedDestination;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWrite;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolInvocationPolicy;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheFailingHookTrait;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RunsANestedToolDataHandlerHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\ScopedWriteFakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
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

    private ConnectionPool $connectionPool;

    private ?AssignmentScopeChecker $checkerOverride = null;

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
        self::assertStringContainsString('rolled back', $outcome['toolResult']);
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
        $copy = function (int $levels): array {
            return $this->call(
                $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
                function (ToolExecutionContext $context) use ($levels): void {
                    $user = $context->actingBackendUser();
                    self::assertInstanceOf(BackendUserAuthentication::class, $user);
                    $user->uc['copyLevels'] = $levels;
                    $this->command(['pages' => [self::BELOW => ['copy' => self::SCOPED]]], $context);
                },
            );
        };

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

    // --- the effect check, with the input check taken away --------------------

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

        return sha1(json_encode($state, JSON_THROW_ON_ERROR));
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
        self::assertStringContainsString('All database changes of this call were rolled back', $outcome['toolResult']);
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
        self::assertSame($before, $this->fingerprint(), 'the editor\'s version is as the editor left it');
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
            static function (): void {
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
            function (ToolExecutionContext $context): void {
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
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);
            },
        );

        self::assertNotSame([], $this->refusals($outcome['trace']));
        self::assertFalse($this->transactionIsOpen());
    }

    #[Test]
    public function aToolThatThrowsEndsItsTransaction(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Written before the failure', $context);

                throw new RuntimeException('boom', 1799990310);
            },
        );

        self::assertStringStartsWith('Error: tool "scoped_probe" failed.', $outcome['toolResult']);
        self::assertFalse($this->transactionIsOpen());
        self::assertSame('Written before the failure', $this->header(self::IN_SCOPE_ELEMENT), 'what a failed call wrote in scope stands, as before');
    }

    #[Test]
    public function aToolThatThrowsAfterWritingOutsideTheScopeIsRolledBack(): void
    {
        $this->unregisterTheDataHandlerLayer();

        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);

                throw new RuntimeException('boom', 1799990311);
            },
        );

        self::assertFalse($this->transactionIsOpen());
        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT));
        self::assertStringContainsString('rolled back', $outcome['toolResult']);
    }

    #[Test]
    public function aCallThatHandsItsWorkToAnExternalOperationCommitsWhatItWrote(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::IN_SCOPE_ELEMENT, 'Before the hand-over', $context);

                throw new ToolDependencyPendingException(new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'call_1', 'a2a_agent_skill', 7, 3, 'remote-1', 4, 0, 1000, 4600, 1, 512), 'waiting');
            },
        );

        self::assertStringStartsWith('Error: tool "scoped_probe" failed.', $outcome['toolResult'], 'a tool that is not a dependency tool may not announce one');
        self::assertFalse($this->transactionIsOpen());
        self::assertSame('Before the hand-over', $this->header(self::IN_SCOPE_ELEMENT));
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
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);

                throw new ToolDependencyPendingException(new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'call_1', 'a2a_agent_skill', 7, 3, 'remote-1', 4, 0, 1000, 4600, 1, 512), 'waiting');
            },
        );

        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT), 'nothing outside the scope was committed');
        self::assertStringContainsString('rolled back', $outcome['toolResult']);
        self::assertNotSame([], $this->refusals($outcome['trace']));
        self::assertFalse($this->transactionIsOpen());
    }

    #[Test]
    public function aWriteTheDataHandlerLayerRefusedBeforeADependencyHandOverIsReported(): void
    {
        $outcome = $this->call(
            $this->claimingRecords([self::IN_SCOPE_ELEMENT]),
            function (ToolExecutionContext $context): void {
                $this->updateHeader(self::OUTSIDE_ELEMENT, 'Outside', $context);

                throw new ToolDependencyPendingException(new DependencyCheckpoint(DependencyCheckpoint::KIND_A2A_TASK, 'call_1', 'a2a_agent_skill', 7, 3, 'remote-1', 4, 0, 1000, 4600, 1, 512), 'waiting');
            },
        );

        self::assertSame('Original 23', $this->header(self::OUTSIDE_ELEMENT));
        self::assertSame(['update tt_content:23 outside_scope'], $this->refusals($outcome['trace']));
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
        self::assertStringContainsString('rolled back', $outcome['toolResult']);
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

        self::assertStringContainsString('All database changes of this call were rolled back', $outcome['toolResult']);
        self::assertSame($before, $this->fingerprint());
        self::assertSame('cached', $cache->get('nrllm_scope_probe'), 'a rolled-back call flushed nothing');
    }

    /**
     * The test installation keeps its caches in a null backend, which flushes
     * nothing. This manager holds one cache in a database table, in the group the
     * tool flushes, so a flush is a TRUNCATE on MySQL and MariaDB.
     */
    private function databaseCacheManager(): CacheManager
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
        $manager->setCacheConfigurations(['pages' => ['frontend' => VariableFrontend::class, 'backend' => Typo3DatabaseBackend::class, 'options' => [], 'groups' => ['system']]]);
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
     *
     * @return array{trace: RunTrace, toolResult: string}
     */
    private function call(Closure $claim, Closure $act, ?AssignmentScope $scope = new AssignmentScope([new ScopedPage(self::SCOPED, 1)]), bool $required = false, bool $approve = true, ?BackendUserAuthentication $user = null): array
    {
        $user ??= $this->useASessionlessAmbientUser(1);
        $tool = new ScopedWriteFakeTool('scoped_probe', $claim, static function (array $arguments, ToolExecutionContext $context) use ($act): ToolResult {
            $act($context);

            return ToolResult::text('ok');
        });

        $configuration = $this->configuration($required);
        $context       = new ToolExecutionContext(AiActorContext::fromBackendUser($user)->withAssignmentScope($scope), $user, new AgentRunReference(1, 'run'));
        $trace         = new RunTrace();
        $call          = new ToolCall('call_1', 'scoped_probe', []);

        $result = null;
        try {
            $result = $this->service([$this->response('', [$call]), $this->response('done')], $tool)
                ->runLoop([['role' => 'user', 'content' => 'go']], $configuration, $context, null, runTrace: $trace);
        } catch (ToolApprovalRequiredException $suspended) {
            self::assertTrue($approve, 'the loop asked for an approval of a call the test expected it to refuse');
            $result = $this->service([$this->response('applied')], $tool)->resume($suspended->state, true, $configuration, $context, runTrace: $trace);
        }

        self::assertInstanceOf(ToolLoopResult::class, $result);
        $steps = array_values(array_filter($trace->getSteps(), static fn (RunStep $step): bool => $step->kind === RunStep::KIND_TOOL));
        self::assertNotSame([], $steps, 'the call was traced');

        return ['trace' => $trace, 'toolResult' => (string) $steps[0]->toolResult];
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
    private function service(array $queue, ScopedWriteFakeTool $tool): ToolLoopService
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
            new NullLogger(),
            previewTranslator: new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)),
            invocationPolicy: new ToolInvocationPolicy([new RequireAssignmentScopeRule($registry, new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class)))]),
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

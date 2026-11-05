<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Skill\SkillComposer;
use Netresearch\NrLlm\Service\Tool\AllowedToolsResolver;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateRedirectTool;
use Netresearch\NrLlm\Service\Tool\Builtin\DisableRedirectTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateRedirectTool;
use Netresearch\NrLlm\Service\Tool\Builtin\WritesThroughDataHandlerTrait;
use Netresearch\NrLlm\Service\Tool\Exception\ToolApprovalRequiredException;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityService;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicy;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolGroupStateRepository;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\ToolStateRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * A redirect cannot be kept as a draft: core does not version `sys_redirect`.
 * In a draft workspace a redirect write is refused BEFORE the approval card —
 * the run does not suspend for it, the tool refuses it with the reason, and
 * no row is written; in live it suspends for approval as before (ADR-262).
 */
#[CoversClass(CreateRedirectTool::class)]
#[CoversClass(UpdateRedirectTool::class)]
#[CoversClass(DisableRedirectTool::class)]
#[CoversClass(ToolLoopService::class)]
final class RefusesUnversionedWritesInADraftWorkspaceTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'seo', 'workspaces', 'redirects'];

    /** @var non-empty-string[] */
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
    ];

    private const DRAFT = 1;

    private const PAGE = 1;

    private const REDIRECT = 10;

    private const NEW_REDIRECT = ['source_path' => '/summer-sale', 'target' => '/sale', 'target_statuscode' => 302];

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $this->connectionPool = $pool;

        $this->importFixture('BeUsers.csv');
        $this->connectionPool->getConnectionForTable('sys_workspace')
            ->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => '']);
        $this->connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid'           => self::PAGE, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'description' => 'Live description',
            'perms_userid'  => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);
        $this->connectionPool->getConnectionForTable('sys_redirect')->insert('sys_redirect', [
            'uid'               => self::REDIRECT, 'pid' => 0, 'source_host' => '*', 'source_path' => '/old', 'target' => '/new',
            'target_statuscode' => 301, 'disabled' => 0,
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function inADraftWorkspaceEveryRedirectWriteIsRefusedWithTheReasonAndNothingIsWritten(): void
    {
        $context = $this->contextIn(self::DRAFT);

        foreach ([
            'create_redirect'  => self::NEW_REDIRECT,
            'update_redirect'  => ['uid' => self::REDIRECT, 'target' => '/elsewhere'],
            'disable_redirect' => ['uid' => self::REDIRECT],
        ] as $name => $call) {
            $tool = $this->tool($name);
            self::assertInstanceOf(RefusesBeforeApprovalInterface::class, $tool);
            self::assertNotNull($tool->refusalBeforeApproval($call, $context), $name);

            $card = $tool->previewCall($call, $context);
            self::assertSame(
                ['Not possible in the workspace “Entwurf”: TYPO3 keeps no draft of Redirect, so the change would go live at once. Nothing will be written; this change can only be made in the live workspace.'],
                $card,
                $name,
            );

            $result = $tool->execute($call, $context);
            self::assertTrue($result->isError, $name);
            self::assertStringContainsString('draft workspace "Entwurf"', $result->content, $name);
            self::assertStringContainsString('no draft of sys_redirect', $result->content, $name);
        }

        self::assertSame(1, $this->redirectCount(), 'a redirect was created');
        self::assertSame(['target' => '/new', 'disabled' => 0], $this->redirect(self::REDIRECT));
    }

    #[Test]
    public function inLiveARedirectIsNotRefusedBeforeTheApproval(): void
    {
        $tool    = $this->tool('create_redirect');
        $context = $this->contextIn(0);
        self::assertInstanceOf(RefusesBeforeApprovalInterface::class, $tool);

        self::assertNull($tool->refusalBeforeApproval(self::NEW_REDIRECT, $context));
        self::assertFalse($tool->execute(self::NEW_REDIRECT, $context)->isError);
        self::assertSame(2, $this->redirectCount());
    }

    /**
     * The helper the redirect writers ask refuses a table core does not
     * version, in a draft workspace only; a table core versions is written
     * there as a draft and is not refused.
     */
    #[Test]
    public function onlyATableCoreDoesNotVersionIsRefusedAndOnlyInADraftWorkspace(): void
    {
        $probe = new class {
            use SafeCastTrait;
            use WritesThroughDataHandlerTrait;

            /**
             * @param non-empty-string $table
             */
            public function refusal(ToolExecutionContext $context, string $table): ?ToolResult
            {
                return $this->refuseUnversionedTableInDraftWorkspace($context, $table);
            }
        };

        $draft = $this->contextIn(self::DRAFT);
        self::assertNotNull($probe->refusal($draft, 'sys_redirect'));
        self::assertNotNull($probe->refusal($draft, 'tx_no_such_table'), 'a table without TCA counts as not versioned');
        self::assertNull($probe->refusal($draft, 'pages'));
        self::assertNull($probe->refusal($draft, 'tt_content'));
        self::assertNull($probe->refusal($this->contextIn(0), 'sys_redirect'));

        // A versioned table that is edited live in every workspace: TYPO3
        // 13.4 writes its live row there.
        $tca = $GLOBALS['TCA'] ?? null;
        self::assertIsArray($tca);
        $pages = $tca['pages'] ?? null;
        self::assertIsArray($pages);
        $ctrl = $pages['ctrl'] ?? null;
        self::assertIsArray($ctrl);
        $ctrl['versioningWS_alwaysAllowLiveEdit'] = true;
        $pages['ctrl']                            = $ctrl;
        $tca['pages']                             = $pages;
        $GLOBALS['TCA']                           = $tca;
        self::assertNotNull($probe->refusal($draft, 'pages'));
        self::assertNull($probe->refusal($this->contextIn(0), 'pages'));
    }

    #[Test]
    public function inADraftWorkspaceARedirectCallDoesNotSuspendAndWritesNothing(): void
    {
        $result = $this->loop([
            $this->response('', [new ToolCall('c1', 'create_redirect', self::NEW_REDIRECT)]),
            $this->response('done'),
        ])->runLoop([['role' => 'user', 'content' => 'add it']], $this->configuration(), $this->contextIn(self::DRAFT), null);

        self::assertSame('done', $result->finalContent);
        self::assertTrue($result->trace[0]->isError);
        self::assertStringContainsString('no draft of sys_redirect', $result->trace[0]->result);
        self::assertSame(1, $this->redirectCount());
    }

    #[Test]
    public function inLiveARedirectCallSuspendsForApproval(): void
    {
        $this->expectException(ToolApprovalRequiredException::class);

        $this->loop([$this->response('', [new ToolCall('c1', 'create_redirect', self::NEW_REDIRECT)])])
            ->runLoop([['role' => 'user', 'content' => 'add it']], $this->configuration(), $this->contextIn(0), null);
    }

    /**
     * Beside a call that needs approval the turn suspends; the redirect's card
     * says it will be refused, and approving the turn writes the page's draft
     * and no redirect. The card is the same on resume, so the approval is not
     * stale (ADR-184).
     */
    #[Test]
    public function inATurnThatSuspendsTheRedirectsCardSaysItIsRefusedAndOnlyThePageDraftIsWritten(): void
    {
        $context = $this->contextIn(self::DRAFT);
        $state   = $this->suspendOn([
            new ToolCall('c1', 'update_page_metadata', ['uid' => self::PAGE, 'description' => 'Draft description']),
            new ToolCall('c2', 'create_redirect', self::NEW_REDIRECT),
        ], $context);

        $cards = [];
        foreach ($state->callPreviews as $preview) {
            $cards[$preview['tool']] = implode("\n", $preview['lines']);
        }

        self::assertStringContainsString('Workspace: “Entwurf”', $cards['update_page_metadata'] ?? '');
        self::assertStringStartsWith('Not possible in the workspace “Entwurf”', $cards['create_redirect'] ?? '');

        $result = $this->loop([$this->response('done')])->resume($state, true, $this->configuration(), $context);

        self::assertSame('done', $result->finalContent);
        self::assertSame(1, $this->redirectCount(), 'a redirect was created');
        self::assertSame('Live description', $this->description(self::PAGE));
        self::assertSame('Draft description', $this->draftDescription(self::PAGE));
    }

    /**
     * @param list<ToolCall> $turn
     */
    private function suspendOn(array $turn, ToolExecutionContext $context): SuspendedRunState
    {
        try {
            $this->loop([$this->response('', $turn)])
                ->runLoop([['role' => 'user', 'content' => 'go']], $this->configuration(), $context, null);
        } catch (ToolApprovalRequiredException $e) {
            return $e->state;
        }

        self::fail('Expected the run to suspend for approval.');
    }

    /**
     * @param list<CompletionResponse> $queue
     */
    private function loop(array $queue): ToolLoopService
    {
        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturnCallback(
            static function () use (&$queue): CompletionResponse {
                $next = array_shift($queue);
                if (!$next instanceof CompletionResponse) {
                    throw new RuntimeException('Scripted response queue underflow.', 1799262003);
                }

                return $next;
            },
        );

        $registry     = new ToolRegistry([$this->tool('create_redirect'), $this->tool('update_page_metadata')]);
        $availability = new ToolAvailabilityService($registry, new ToolStateRepository($this->connectionPool), new ToolGroupStateRepository($this->connectionPool));
        $policy       = new ToolCallPolicy(
            $registry,
            $availability,
            new AllowedToolsResolver(new SkillComposer(), $registry),
            new ToolDataClassResolver($registry),
            new TrustZoneResolver(),
            new DataClassEnforcementResolver(),
        );

        return new ToolLoopService($mgr, $registry, $policy);
    }

    private function contextIn(int $workspace): ToolExecutionContext
    {
        $admin = $this->setUpBackendUser(1);
        self::assertTrue($admin->setTemporaryWorkspace($workspace));
        self::assertSame($workspace, $admin->workspace, 'positive control: the user is in the workspace');

        return ToolExecutionContext::fromBackendUser($admin);
    }

    /**
     * @return ToolInterface&ToolPreviewInterface
     */
    private function tool(string $name): ToolInterface
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get($name);
        self::assertInstanceOf(ToolInterface::class, $tool);
        self::assertInstanceOf(ToolPreviewInterface::class, $tool);
        (new ToolStateRepository($this->connectionPool))->setEnabled($name, true);

        return $tool;
    }

    private function configuration(): LlmConfiguration
    {
        $provider = new Provider();
        $provider->setTrustZoneEnum(TrustZone::LOCAL);

        $model = new Model();
        $model->setProvider($provider);

        $configuration = new LlmConfiguration();
        $configuration->setLlmModel($model);

        return $configuration;
    }

    /**
     * @param list<ToolCall>|null $toolCalls
     */
    private function response(string $content, ?array $toolCalls = null): CompletionResponse
    {
        return new CompletionResponse(content: $content, model: 'test-model', usage: UsageStatistics::fromTokens(0, 0), toolCalls: $toolCalls);
    }

    private function redirectCount(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder->count('uid')->from('sys_redirect')->executeQuery()->fetchOne();
    }

    /**
     * @return array{target: string, disabled: int}
     */
    private function redirect(int $uid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_redirect');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder->select('target', 'disabled')->from('sys_redirect')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()->fetchAssociative();
        self::assertIsArray($row);

        self::assertIsString($row['target']);

        return ['target' => $row['target'], 'disabled' => (int) $row['disabled']];
    }

    private function description(int $uid): string
    {
        $value = $this->connectionPool->getConnectionForTable('pages')->select(['description'], 'pages', ['uid' => $uid])->fetchOne();

        return is_string($value) ? $value : '';
    }

    private function draftDescription(int $uid): string
    {
        $value = $this->connectionPool->getConnectionForTable('pages')
            ->select(['description'], 'pages', ['t3ver_oid' => $uid, 't3ver_wsid' => self::DRAFT])->fetchOne();

        return is_string($value) ? $value : '';
    }
}

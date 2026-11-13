<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\Inbox;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\PendingLanguageInterface;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The workspace title and the language of a pending call, read where they
 * live: the title from `sys_workspace`, the language from the real translation
 * tool (ADR-292).
 */
#[CoversClass(WaitingRunViewFactory::class)]
final class PendingCallWorkspaceFieldsTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'workspaces'];

    private const DRAFT = 1;

    private const FOREIGN_DRAFT = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $workspaces = $pool->getConnectionForTable('sys_workspace');
        $workspaces->insert('sys_workspace', ['uid' => self::DRAFT, 'pid' => 0, 'title' => 'Entwurf', 'members' => 'be_users_2']);
        $workspaces->insert('sys_workspace', ['uid' => self::FOREIGN_DRAFT, 'pid' => 0, 'title' => 'Internal campaign', 'members' => '']);
    }

    #[Test]
    public function theTitleOfTheRunsWorkspaceIsOnTheCardForAViewerWhoMayWorkThere(): void
    {
        $call = $this->cardIn(self::DRAFT, $this->setUpBackendUser(1));

        self::assertSame(self::DRAFT, $call->workspaceId);
        self::assertSame('Entwurf', $call->workspaceTitle);
    }

    /**
     * A viewer who is no member of the workspace is told which one by id, not
     * by its name: the preview lines are withheld from them for the same reason.
     */
    #[Test]
    public function theTitleIsWithheldFromAViewerWhoIsNoMemberButTheIdIsNot(): void
    {
        $viewer = $this->setUpBackendUser(2);
        self::assertFalse($viewer->isAdmin());

        $call = $this->cardIn(self::FOREIGN_DRAFT, $viewer);

        self::assertSame(self::FOREIGN_DRAFT, $call->workspaceId);
        self::assertSame('', $call->workspaceTitle);
    }

    #[Test]
    public function aWorkspaceThatNoLongerExistsLeavesTheTitleEmpty(): void
    {
        $call = $this->cardIn(99, $this->setUpBackendUser(1));

        self::assertSame(99, $call->workspaceId);
        self::assertSame('', $call->workspaceTitle);
    }

    #[Test]
    #[DataProvider('languages')]
    public function theTranslationToolNamesTheLanguageItWritesIn(mixed $argument, ?int $expected): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('create_translation_draft');
        self::assertInstanceOf(PendingLanguageInterface::class, $tool);

        self::assertSame($expected, $tool->pendingLanguage(['table' => 'pages', 'uid' => 2, 'language' => $argument]));
    }

    /**
     * @return iterable<string, array{mixed, int|null}>
     */
    public static function languages(): iterable
    {
        yield 'an integer' => [3, 3];
        yield 'a numeric string' => ['3', 3];
        yield 'the default language is no translation' => [0, null];
        yield 'a negative uid' => [-1, null];
        yield 'a fraction' => [1.5, null];
        yield 'a word' => ['de', null];
        yield 'nothing' => [null, null];
    }

    private function cardIn(int $workspace, BackendUserAuthentication $viewer): PendingCallView
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $translator = new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class));

        $state = new SuspendedRunState(
            [],
            [ToolCall::function('call', 'create_translation_draft', ['table' => 'pages', 'uid' => 2, 'language' => 1])->toArray()],
            1,
            0,
            0,
            initiatingActor: AiActorContext::backendUser(1, true, workspaceId: $workspace),
        );
        $run = new AgentRun(
            uid: 1,
            uuid: 'run',
            status: 'waiting_for_approval',
            configurationUid: 0,
            configurationIdentifier: 'cfg',
            beUser: 1,
            iterations: 1,
            truncated: false,
            totalPromptTokens: 0,
            totalCompletionTokens: 0,
            totalTokens: 0,
            estimatedCost: 0.0,
            errorClass: '',
            terminationReason: '',
            startedAt: 0,
            finishedAt: 0,
            crdate: 100,
            suspendedState: json_encode($state->toArray(), JSON_THROW_ON_ERROR),
        );

        $views = (new WaitingRunViewFactory($registry, new SchemaPropertyClassifier(), new PendingTurnDigest(), $translator, $this->getService(ConnectionPool::class)))->buildWaiting([$run], $viewer);
        self::assertCount(1, $views);

        return $views[0]->pendingCalls[0];
    }
}

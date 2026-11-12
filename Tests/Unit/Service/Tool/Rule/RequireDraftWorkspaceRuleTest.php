<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Rule;

use ArrayObject;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationHistory;
use Netresearch\NrLlm\Service\Tool\PreApprovalInvocationRuleInterface;
use Netresearch\NrLlm\Service\Tool\Rule\RequireDraftWorkspaceRule;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Language\EnglishPreviewTranslatorTrait;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RefusingWriteTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequireDraftWorkspaceRule::class)]
final class RequireDraftWorkspaceRuleTest extends TestCase
{
    use EnglishPreviewTranslatorTrait;

    /**
     * Every row is one actor and user, and whether a write is let through.
     *
     * @return iterable<string, array{bool, ?int, ?int, bool, bool}>
     */
    public static function writers(): iterable
    {
        //                                     required, run workspace, user workspace, admin, allowed
        yield 'off, live' => [false, 0, 0, false, true];
        yield 'off, run recorded nothing' => [false, null, 0, false, true];
        yield 'on, draft, same workspace' => [true, 3, 3, false, true];
        yield 'on, draft, admin' => [true, 3, 3, true, true];
        yield 'on, live' => [true, 0, 0, false, false];
        yield 'on, live, admin' => [true, 0, 0, true, false];
        yield 'on, run recorded nothing' => [true, null, 0, false, false];
        yield 'on, draft, user moved' => [true, 3, 4, false, false];
        yield 'on, draft, user now in live' => [true, 3, 0, false, false];
        yield 'on, draft, no acting user' => [true, 3, null, false, false];
        yield 'on, live, no acting user' => [true, 0, null, false, false];
    }

    #[Test]
    #[DataProvider('writers')]
    public function aWriteIsLetThroughOnlyInTheWorkspaceTheRunRecorded(bool $required, ?int $workspace, ?int $userWorkspace, bool $admin, bool $allowed): void
    {
        $decision = $this->rule()->decide($this->contextFor('plain_write', $required, $workspace, $userWorkspace, $admin));

        self::assertSame($allowed, $decision->allowed);
        if (!$allowed) {
            self::assertSame($workspace !== null && $workspace > 0 ? 'workspace_unavailable' : 'live_workspace', $decision->reason);
        }
    }

    #[Test]
    public function aReadIsLetThroughWhereverTheRunWorks(): void
    {
        foreach ([[0, 0], [null, 0], [3, null]] as [$workspace, $userWorkspace]) {
            self::assertTrue($this->rule()->decide($this->contextFor('read_page', true, $workspace, $userWorkspace, false))->allowed);
        }
    }

    #[Test]
    public function theReasonTellsALiveRunFromAWorkspaceTheUserLost(): void
    {
        self::assertSame('live_workspace', $this->rule()->decide($this->contextFor('plain_write', true, 0, 0, false))->reason);
        self::assertSame('workspace_unavailable', $this->rule()->decide($this->contextFor('plain_write', true, 3, 4, false))->reason);
    }

    #[Test]
    public function theRefusalExplainsWhatToDoInTheLanguageOfTheActingUser(): void
    {
        $live = $this->rule()->decide($this->contextFor('plain_write', true, 0, 0, false));
        self::assertStringContainsString('requires a draft workspace', $live->explanation);
        self::assertStringContainsString('Switch to a draft workspace', $live->explanation);

        $german = $this->rule()->decide($this->contextFor('plain_write', true, 0, 0, false, 'de'));
        self::assertStringContainsString('Entwurfs-Arbeitsumgebung', $german->explanation);
        self::assertNotSame($live->explanation, $german->explanation);

        $gone = $this->rule()->decide($this->contextFor('plain_write', true, 3, 4, false));
        self::assertStringContainsString('no longer available', $gone->explanation);
    }

    #[Test]
    public function theRuleAnswersFromTheCallAloneSoTheLoopMayAskItBeforeTheCard(): void
    {
        $rule = $this->rule();

        self::assertInstanceOf(PreApprovalInvocationRuleInterface::class, $rule);
        self::assertFalse($rule->requiresCompleteHistory());
        self::assertSame('draft_workspace_required', $rule->identifier());
    }

    private function rule(): RequireDraftWorkspaceRule
    {
        return new RequireDraftWorkspaceRule(
            new ToolRegistry([new FakeTool('read_page', 'PAGE'), new RefusingWriteTool('plain_write', false, new ArrayObject())]),
            $this->englishTranslator(),
        );
    }

    private function contextFor(string $tool, bool $required, ?int $workspace, ?int $userWorkspace, bool $admin, string $language = 'en'): ToolInvocationContext
    {
        $user = null;
        if ($userWorkspace !== null) {
            $user            = $this->userIn($language);
            $user->user      = ['uid' => 1, 'admin' => $admin ? 1 : 0, 'lang' => $language];
            $user->workspace = $userWorkspace;
        }

        $configuration = new LlmConfiguration();
        $configuration->setRequireDraftWorkspace($required);

        return new ToolInvocationContext(
            $tool,
            [],
            null,
            $configuration,
            new ToolExecutionContext(AiActorContext::backendUser(1, $admin, [], [], $workspace), $user),
            new ToolInvocationHistory([], true),
        );
    }
}

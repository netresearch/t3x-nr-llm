<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\Inbox;

use Netresearch\NrLlm\Domain\ValueObject\AgentRun;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\SuspendedRunState;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Agent\Inbox\PendingCallView;
use Netresearch\NrLlm\Service\Agent\Inbox\WaitingRunViewFactory;
use Netresearch\NrLlm\Service\Agent\PendingTurnDigest;
use Netresearch\NrLlm\Service\Tool\PendingLanguageInterface;
use Netresearch\NrLlm\Service\Tool\SchemaPropertyClassifier;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Language\EnglishPreviewTranslatorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Where a pending call writes, stated as values on the approval card
 * (ADR-292), so a consumer stops reading it out of the preview prose.
 */
#[CoversClass(WaitingRunViewFactory::class)]
#[CoversClass(PendingCallView::class)]
final class PendingCallViewStructuredFieldsTest extends TestCase
{
    use EnglishPreviewTranslatorTrait;

    #[Test]
    public function aCardStatesTheWorkspaceTheRunWritesInAndTheLanguageTheToolNames(): void
    {
        $call = $this->cardOf(AiActorContext::backendUser(7, true, workspaceId: 3), $this->tool(language: 2));

        self::assertSame(3, $call->workspaceId);
        self::assertSame(2, $call->language);
    }

    #[Test]
    public function aLiveRunStatesWorkspaceZeroAndNoLanguageUnlessTheToolNamesOne(): void
    {
        $call = $this->cardOf(AiActorContext::backendUser(7, true, workspaceId: 0), $this->tool(language: null));

        self::assertSame(0, $call->workspaceId);
        self::assertNull($call->language, 'no language stated is not "the default language"');
        self::assertSame('', $call->workspaceTitle, 'live has no title');
    }

    #[Test]
    public function aRunThatRecordedNoWorkspaceWritesLive(): void
    {
        self::assertSame(0, $this->cardOf(AiActorContext::backendUser(7, true), $this->tool(language: null))->workspaceId);
    }

    #[Test]
    public function aToolThatCannotStateALanguageLeavesItEmptyAndATitleThatCannotBeReadIsEmpty(): void
    {
        // No database in a unit test: the title lookup fails and the card keeps the id.
        $call = $this->cardOf(AiActorContext::backendUser(7, true, workspaceId: 3), $this->tool(language: null, implementsLanguage: false));

        self::assertSame(3, $call->workspaceId);
        self::assertNull($call->language);
        self::assertSame('', $call->workspaceTitle);
    }

    #[Test]
    public function aToolThatThrowsWhileNamingItsLanguageCostsTheCardOnlyThatField(): void
    {
        $call = $this->cardOf(AiActorContext::backendUser(7, true, workspaceId: 1), $this->tool(language: null, throws: true));

        self::assertNull($call->language);
        self::assertSame(1, $call->workspaceId);
    }

    private function cardOf(AiActorContext $actor, ToolInterface $tool): PendingCallView
    {
        $viewer       = self::createStub(BackendUserAuthentication::class);
        $viewer->user = ['uid' => 7];

        $state = new SuspendedRunState(
            [],
            [ToolCall::function('call', 'probe', ['language' => 2])->toArray()],
            1,
            0,
            0,
            initiatingActor: $actor,
        );
        $run = new AgentRun(
            uid: 1,
            uuid: 'run',
            status: 'waiting_for_approval',
            configurationUid: 0,
            configurationIdentifier: 'cfg',
            beUser: 7,
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

        $views = (new WaitingRunViewFactory(new ToolRegistry([$tool]), new SchemaPropertyClassifier(), new PendingTurnDigest(), $this->englishTranslator()))->buildWaiting([$run], $viewer);

        return $views[0]->pendingCalls[0];
    }

    private function tool(?int $language, bool $implementsLanguage = true, bool $throws = false): ToolInterface
    {
        $base = new class ($language, $throws) implements ToolInterface, PendingLanguageInterface {
            public function __construct(private readonly ?int $language, private readonly bool $throws) {}

            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('probe', 'probe', ['type' => 'object', 'properties' => []]);
            }

            public function pendingLanguage(array $arguments): ?int
            {
                return $this->throws ? throw new RuntimeException('no', 1799990294) : $this->language;
            }

            public function execute(array $arguments, ToolExecutionContext $context): ToolResult
            {
                return ToolResult::text('unused');
            }

            public function isEnabledByDefault(): bool
            {
                return true;
            }

            public function requiresAdmin(): bool
            {
                return false;
            }

            public function getGroup(): string
            {
                return 'test';
            }
        };

        return $implementsLanguage ? $base : new class implements ToolInterface {
            public function getSpec(): ToolSpec
            {
                return ToolSpec::function('probe', 'probe', ['type' => 'object', 'properties' => []]);
            }

            public function execute(array $arguments, ToolExecutionContext $context): ToolResult
            {
                return ToolResult::text('unused');
            }

            public function isEnabledByDefault(): bool
            {
                return true;
            }

            public function requiresAdmin(): bool
            {
                return false;
            }

            public function getGroup(): string
            {
                return 'test';
            }
        };
    }
}

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
use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ScopedPage;
use Netresearch\NrLlm\Domain\ValueObject\ScopeRefusal;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationHistory;
use Netresearch\NrLlm\Service\Tool\PreApprovalInvocationRuleInterface;
use Netresearch\NrLlm\Service\Tool\Rule\RequireAssignmentScopeRule;
use Netresearch\NrLlm\Service\Tool\Scope\AssignmentScopeChecker;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedDestination;
use Netresearch\NrLlm\Service\Tool\Scope\ScopedWrite;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationPolicy;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Language\EnglishPreviewTranslatorTrait;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeRemoteWriteTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\PreviewingApprovalTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\RefusingWriteTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\ScopedWriteFakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Scope\ArrayRecordTopology;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The first layer of the scope of an assignment (ADR-295), on the page tree of
 * {@see ArrayRecordTopology}: page 2 and one level below it are in scope.
 */
#[CoversClass(RequireAssignmentScopeRule::class)]
final class RequireAssignmentScopeRuleTest extends TestCase
{
    use EnglishPreviewTranslatorTrait;

    #[Test]
    public function aWriteInsideTheScopeIsLetThrough(): void
    {
        $decision = $this->decide('scoped_write', ['uid' => 21]);

        self::assertTrue($decision->allowed);
    }

    #[Test]
    public function aWriteOutsideTheScopeIsRefusedAndTheModelIsToldWhatLiesOutside(): void
    {
        $decision = $this->decide('scoped_write', ['uid' => 23]);

        self::assertFalse($decision->allowed);
        self::assertSame(ScopeRefusal::OUTSIDE_SCOPE, $decision->reason);
        self::assertStringContainsString('page 2 and 1 level below it', $decision->explanation);
        self::assertStringContainsString('tt_content:23', $decision->explanation);
        self::assertStringContainsString('nothing was changed', $decision->explanation);
        self::assertSame(['update tt_content:23'], array_map(static fn (ScopeRefusal $refusal): string => $refusal->operation . ' ' . $refusal->subject(), $decision->refusals));
    }

    #[Test]
    public function aRecordCreatedUnderAPageOutsideTheScopeIsRefused(): void
    {
        self::assertTrue($this->decide('scoped_write', ['op' => 'create', 'pid' => 3])->allowed);

        $decision = $this->decide('scoped_write', ['op' => 'create', 'pid' => 5]);

        self::assertFalse($decision->allowed);
        self::assertSame('create', $decision->refusals[0]->operation);
        self::assertSame(5, $decision->refusals[0]->pid);
        self::assertStringContainsString('tt_content (on page 5)', $decision->explanation);
    }

    #[Test]
    public function aMoveIsRefusedWhenEitherEndLiesOutsideTheScope(): void
    {
        self::assertTrue($this->decide('scoped_write', ['op' => 'move', 'uid' => 21, 'pid' => 3])->allowed);
        self::assertFalse($this->decide('scoped_write', ['op' => 'move', 'uid' => 21, 'pid' => 5])->allowed, 'out of scope');
        self::assertFalse($this->decide('scoped_write', ['op' => 'move', 'uid' => 23, 'pid' => 2])->allowed, 'into scope from outside');
    }

    #[Test]
    public function aTranslationOfARecordOutsideTheScopeIsRefused(): void
    {
        self::assertTrue($this->decide('scoped_write', ['op' => 'translate', 'uid' => 21, 'language' => 1])->allowed);

        $decision = $this->decide('scoped_write', ['op' => 'translate', 'uid' => 23, 'language' => 1]);

        self::assertFalse($decision->allowed);
        self::assertSame('translate', $decision->refusals[0]->operation);
    }

    /**
     * The scope is taken from the run. What the call sends is the claim to
     * check, never an input to the scope.
     */
    #[Test]
    public function nothingTheCallSendsWidensTheScope(): void
    {
        $widening = [
            'assignmentScope' => ['pages' => [['uid' => 5, 'depth' => 99]], 'records' => [], 'tables' => null, 'languages' => null],
            'scope'           => 'all',
            'pages'           => [5],
            'depth'           => 99,
            'workspace'       => 0,
        ];

        $decision = $this->decide('scoped_write', ['uid' => 23, ...$widening]);

        self::assertFalse($decision->allowed);
        self::assertSame(ScopeRefusal::OUTSIDE_SCOPE, $decision->reason);
    }

    #[Test]
    public function aConfigurationThatRequiresAScopeRefusesEveryWriteOfARunWithoutOne(): void
    {
        $decision = $this->decide('scoped_write', ['uid' => 21], scope: null, required: true);

        self::assertFalse($decision->allowed);
        self::assertSame(RequireAssignmentScopeRule::REASON_REQUIRED, $decision->reason);
        self::assertStringContainsString('requires an assignment scope', $decision->explanation);
        self::assertSame([], $decision->refusals, 'nothing lies outside a scope that is missing');

        self::assertTrue($this->decide('read_page', [], scope: null, required: true)->allowed, 'a read needs no scope');
    }

    #[Test]
    public function aRunWithoutAScopeIsNotHeldToOneUnlessTheConfigurationRequiresIt(): void
    {
        self::assertTrue($this->decide('scoped_write', ['uid' => 23], scope: null, required: false)->allowed);
        self::assertTrue($this->decide('unscoped_write', [], scope: null, required: false)->allowed);
        self::assertTrue($this->decide('remote_write', [], scope: null, required: false)->allowed);
    }

    #[Test]
    public function aScopeTheStarterSetBindsTheRunWhetherOrNotTheConfigurationRequiresOne(): void
    {
        self::assertFalse($this->decide('scoped_write', ['uid' => 23], required: false)->allowed);
        self::assertTrue($this->decide('scoped_write', ['uid' => 21], required: false)->allowed);
    }

    #[Test]
    public function aRemoteToolIsRefusedWhileAScopeApplies(): void
    {
        $required = $this->decide('remote_write', [], scope: null, required: true);
        self::assertFalse($required->allowed);
        self::assertSame(RequireAssignmentScopeRule::REASON_REQUIRED, $required->reason, 'a missing scope is reported before the kind of tool');

        $bound = $this->decide('remote_write', [], required: true);
        self::assertFalse($bound->allowed);
        self::assertSame(RequireAssignmentScopeRule::REASON_REMOTE, $bound->reason);
        self::assertStringContainsString('outside TYPO3', $bound->explanation);

        self::assertFalse($this->decide('remote_write', [], required: false)->allowed, 'a starter that bound the run to a scope cannot have it left by a remote tool');
    }

    #[Test]
    public function aWriteToolThatCannotStateItsTargetsIsRefused(): void
    {
        $plain = $this->decide('unscoped_write', []);
        self::assertFalse($plain->allowed);
        self::assertSame(RequireAssignmentScopeRule::REASON_UNRESOLVABLE, $plain->reason);

        $approval = $this->decide('approval_only', []);
        self::assertFalse($approval->allowed, 'a tool that needs approval without declaring an effect is a write');

        $unreadable = $this->decide('scoped_write', ['op' => 'unreadable']);
        self::assertFalse($unreadable->allowed);
        self::assertSame(RequireAssignmentScopeRule::REASON_UNRESOLVABLE, $unreadable->reason);
    }

    #[Test]
    public function aReadPassesInsideAnyScope(): void
    {
        self::assertTrue($this->decide('read_page', ['uid' => 23])->allowed);
        self::assertTrue($this->decide('read_page', ['uid' => 23], scope: AssignmentScope::none())->allowed);
    }

    #[Test]
    public function theConfigurationCapsHowDeepTheScopeReaches(): void
    {
        $scope = new AssignmentScope([new ScopedPage(2, 5)]);

        self::assertTrue($this->decide('scoped_write', ['uid' => 22], scope: $scope, maxDepth: 99)->allowed);
        self::assertTrue($this->decide('scoped_write', ['uid' => 21], scope: $scope, maxDepth: 0)->allowed);
        self::assertFalse($this->decide('scoped_write', ['uid' => 22], scope: $scope, maxDepth: 0)->allowed, 'a page one level below is out under a cap of 0');
    }

    #[Test]
    public function aTableOutsideTheScopeNamesTheTableInTheExplanation(): void
    {
        $scope    = new AssignmentScope([new ScopedPage(2, 1)], [], ['pages']);
        $decision = $this->decide('scoped_write', ['uid' => 21], scope: $scope);

        self::assertFalse($decision->allowed);
        self::assertSame(ScopeRefusal::TABLE_NOT_ALLOWED, $decision->reason);
        self::assertStringContainsString('tt_content', $decision->explanation);
    }

    #[Test]
    public function theRefusalIsInTheLanguageOfTheActingUser(): void
    {
        $german  = $this->decide('scoped_write', ['uid' => 23], language: 'de');
        $english = $this->decide('scoped_write', ['uid' => 23]);

        self::assertStringContainsString('Auftrag', $german->explanation);
        self::assertStringContainsString('assignment', $english->explanation);
        self::assertNotSame($german->explanation, $english->explanation);
    }

    #[Test]
    public function theRuleAnswersFromTheCallAloneSoTheLoopMayAskItBeforeTheCard(): void
    {
        $rule = $this->rule();

        self::assertInstanceOf(PreApprovalInvocationRuleInterface::class, $rule);
        self::assertFalse($rule->requiresCompleteHistory());
        self::assertSame('assignment_scope', $rule->identifier());
    }

    /**
     * The policy names the rule on the refusal and keeps what was refused, so
     * the loop can put it on the run.
     */
    #[Test]
    public function thePolicyCarriesTheRefusalsAndTheRuleThatMadeThem(): void
    {
        $policy   = new ToolInvocationPolicy([$this->rule()]);
        $decision = $policy->decide($this->context('scoped_write', ['uid' => 23]));

        self::assertFalse($decision->allowed);
        self::assertSame('assignment_scope', $decision->ruleIdentifier);
        self::assertCount(1, $decision->refusals);
        self::assertTrue($policy->decideBeforeApproval($this->context('scoped_write', ['uid' => 23]))->allowed === false, 'asked before the card as well');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function decide(string $tool, array $arguments, ?AssignmentScope $scope = new AssignmentScope([new ScopedPage(2, 1)]), bool $required = false, int $maxDepth = 99, string $language = 'en'): ToolInvocationDecision
    {
        return $this->rule()->decide($this->context($tool, $arguments, $scope, $required, $maxDepth, $language));
    }

    private function rule(): RequireAssignmentScopeRule
    {
        $claim = static function (array $arguments): ?ScopedWrite {
            $uid = is_int($arguments['uid'] ?? null) ? $arguments['uid'] : 0;
            $pid = is_int($arguments['pid'] ?? null) ? $arguments['pid'] : 0;

            return match ($arguments['op'] ?? 'update') {
                'create'     => new ScopedWrite('create', [], [new ScopedDestination('tt_content', $pid)]),
                'move'       => new ScopedWrite('move', [new RecordReference('tt_content', $uid)], [new ScopedDestination('tt_content', $pid)]),
                'translate'  => new ScopedWrite('translate', [new RecordReference('tt_content', $uid)], [], false, is_int($arguments['language'] ?? null) ? $arguments['language'] : null),
                'unreadable' => null,
                default      => new ScopedWrite('update', [new RecordReference('tt_content', $uid)]),
            };
        };

        return new RequireAssignmentScopeRule(
            new ToolRegistry([
                new FakeTool('read_page', 'PAGE'),
                new ScopedWriteFakeTool('scoped_write', $claim),
                new RefusingWriteTool('unscoped_write', false, new ArrayObject()),
                new PreviewingApprovalTool('approval_only'),
                new FakeRemoteWriteTool('remote_write'),
            ]),
            $this->englishTranslator(),
            new AssignmentScopeChecker(new ArrayRecordTopology()),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function context(string $tool, array $arguments, ?AssignmentScope $scope = new AssignmentScope([new ScopedPage(2, 1)]), bool $required = false, int $maxDepth = 99, string $language = 'en'): ToolInvocationContext
    {
        $user            = new BackendUserAuthentication();
        $user->user      = ['uid' => 1, 'admin' => 0, 'lang' => $language];
        $user->workspace = 0;

        $configuration = new LlmConfiguration();
        $configuration->setRequireAssignmentScope($required);
        $configuration->setAssignmentScopeMaxDepth($maxDepth);

        return new ToolInvocationContext(
            $tool,
            $arguments,
            null,
            $configuration,
            new ToolExecutionContext(AiActorContext::backendUser(1, false, [], [], 0, $scope), $user),
            new ToolInvocationHistory([], true),
        );
    }
}

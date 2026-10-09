<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use LogicException;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ToolCall;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationHistory;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationTarget;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationContext;
use Netresearch\NrLlm\Service\Tool\ToolInvocationPolicy;
use Netresearch\NrLlm\Service\Tool\ToolInvocationRuleInterface;
use Netresearch\NrLlm\Service\Tool\ToolTargetResolverInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ToolInvocationPolicy::class)]
final class ToolInvocationPolicyTest extends TestCase
{
    private function context(): ToolInvocationContext
    {
        return new ToolInvocationContext(
            'lookup',
            ['uid' => 7],
            new ToolInvocationTarget('page', '7'),
            new LlmConfiguration(),
            new ToolExecutionContext(AiActorContext::anonymous()),
            new ToolInvocationHistory(),
        );
    }

    #[Test]
    public function absentRulesPreserveExistingPermission(): void
    {
        self::assertTrue(
            (new ToolInvocationPolicy())->decide($this->context())->allowed,
        );
    }

    #[Test]
    public function rulesSeeTheArgumentsAndResolvedTarget(): void
    {
        $rule = self::createStub(ToolInvocationRuleInterface::class);
        $rule->method('identifier')->willReturn('site.pages');
        $rule->method('requiresCompleteHistory')->willReturn(false);
        $rule
            ->method('decide')
            ->willReturnCallback(
                static function (
                    ToolInvocationContext $ctx,
                ): ToolInvocationDecision {
                    self::assertSame(7, $ctx->arguments['uid']);
                    self::assertSame('7', $ctx->target?->identifier);
                    return ToolInvocationDecision::deny('outside_site');
                },
            );
        $decision = (new ToolInvocationPolicy([$rule]))->decide($this->context());
        self::assertFalse($decision->allowed);
        self::assertSame('site.pages', $decision->ruleIdentifier);
        self::assertSame('outside_site', $decision->reason);
    }

    #[Test]
    public function legacyHistoryIsNotAssumedEmpty(): void
    {
        $rule = self::createMock(ToolInvocationRuleInterface::class);
        $rule->method('identifier')->willReturn('sequence');
        $rule->method('requiresCompleteHistory')->willReturn(true);
        $rule->expects(self::never())->method('decide');
        $ctx = $this->context();
        $ctx = new ToolInvocationContext(
            $ctx->toolName,
            $ctx->arguments,
            $ctx->target,
            $ctx->configuration,
            $ctx->execution,
            ToolInvocationHistory::fromStored(null),
        );
        self::assertSame(
            'history_incomplete',
            (new ToolInvocationPolicy([$rule]))->decide($ctx)->reason,
        );
    }

    #[Test]
    public function ruleFailureDoesNotEchoItsException(): void
    {
        $rule = self::createStub(ToolInvocationRuleInterface::class);
        $rule->method('identifier')->willReturn('fixture');
        $rule->method('requiresCompleteHistory')->willReturn(false);
        $rule
            ->method('decide')
            ->willThrowException(new RuntimeException('credential-value'));
        self::assertSame(
            'rule_failed',
            (new ToolInvocationPolicy([$rule]))->decide($this->context())->reason,
        );
    }

    #[Test]
    public function duplicateRulesAreNotChosenByContainerOrder(): void
    {
        $rule = self::createStub(ToolInvocationRuleInterface::class);
        $rule->method('identifier')->willReturn('duplicate');
        $this->expectException(LogicException::class);
        $policy = new ToolInvocationPolicy([$rule, $rule]);
        self::assertInstanceOf(ToolInvocationPolicy::class, $policy);
    }

    #[Test]
    public function ambiguousTargetResolversCannotChooseOneByOrder(): void
    {
        $resolver = self::createStub(ToolTargetResolverInterface::class);
        $resolver
            ->method('resolve')
            ->willReturn(new ToolInvocationTarget('pages', '7'));
        $this->expectException(LogicException::class);
        (new ToolInvocationPolicy([], [$resolver, $resolver]))->resolveTarget(
            new ToolCall('call', 'lookup', []),
            $this->context()->execution,
        );
    }

    #[Test]
    public function anUnknownRequiredTargetIsRefusedByItsRule(): void
    {
        $rule = self::createStub(ToolInvocationRuleInterface::class);
        $rule->method('identifier')->willReturn('target.required');
        $rule->method('requiresCompleteHistory')->willReturn(false);
        $rule
            ->method('decide')
            ->willReturnCallback(
                static fn(
                    ToolInvocationContext $context,
                ): ToolInvocationDecision => $context->target instanceof ToolInvocationTarget ? ToolInvocationDecision::allow() : ToolInvocationDecision::deny('target_unknown'),
            );
        $context = $this->context();
        $unknown = new ToolInvocationContext(
            $context->toolName,
            $context->arguments,
            null,
            $context->configuration,
            $context->execution,
            $context->history,
        );
        self::assertSame(
            'target_unknown',
            (new ToolInvocationPolicy([$rule]))->decide($unknown)->reason,
        );
    }
}

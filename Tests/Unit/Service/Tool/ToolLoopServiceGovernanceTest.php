<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Tool\ToolCallPolicyInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolLoopService;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Command\Fixture\InMemoryGovernanceEventRepository;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeToolAvailability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The tool gate is the one place a tool NAME is structurally available, so it is
 * where a tool denial becomes a queryable governance event.
 */
#[CoversClass(ToolLoopService::class)]
final class ToolLoopServiceGovernanceTest extends TestCase
{
    #[Test]
    public function aDeniedToolIsRecordedAsAGovernanceEvent(): void
    {
        $recorder = new InMemoryGovernanceEventRepository();
        $policy   = $this->policyDenying('fetch_logs', ToolDenialReason::TRUST_ZONE);

        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturn(
            new CompletionResponse('done', 'test-model', UsageStatistics::fromTokens(1, 1)),
        );
        $mgr->method('chatWithConfiguration')->willReturn(
            new CompletionResponse('done', 'test-model', UsageStatistics::fromTokens(1, 1)),
        );

        $registry = new ToolRegistry([new FakeTool('fetch_logs', 'LOGS')]);
        $service  = new ToolLoopService(
            $mgr,
            $registry,
            new FakeToolAvailability(['fetch_logs']),
            toolPolicy: $policy,
            governanceEvents: $recorder,
        );

        $context = ToolExecutionContext::forBackendUser(AiActorContext::backendUser(42, true), null);
        $service->runLoop([['role' => 'user', 'content' => 'show logs']], new LlmConfiguration(), $context, null);

        self::assertCount(1, $recorder->recorded);
        $event = $recorder->recorded[0];
        self::assertSame(GovernanceDecision::TOOL_DENIED->value, $event->decision);
        self::assertSame(ToolDenialReason::TRUST_ZONE->value, $event->reason);
        self::assertSame('fetch_logs', $event->toolName);
        self::assertSame(42, $event->beUser);
        self::assertSame('', $event->guardrail);
        self::assertStringContainsString('zone=', $event->detail);
        self::assertStringContainsString('observedOnly=0', $event->detail);
    }

    #[Test]
    public function anAllowedToolRecordsNothing(): void
    {
        $recorder = new InMemoryGovernanceEventRepository();
        $policy   = $this->policyAllowing('fetch_logs');

        $mgr = self::createStub(LlmServiceManagerInterface::class);
        $mgr->method('chatWithToolsForConfiguration')->willReturn(
            new CompletionResponse('done', 'test-model', UsageStatistics::fromTokens(1, 1)),
        );

        $registry = new ToolRegistry([new FakeTool('fetch_logs', 'LOGS')]);
        $service  = new ToolLoopService(
            $mgr,
            $registry,
            new FakeToolAvailability(['fetch_logs']),
            toolPolicy: $policy,
            governanceEvents: $recorder,
        );

        $service->runLoop([['role' => 'user', 'content' => 'hi']], new LlmConfiguration(), ToolExecutionContext::none(), null);

        self::assertSame([], $recorder->recorded);
    }

    private function policyDenying(string $toolName, ToolDenialReason $reason): ToolCallPolicyInterface
    {
        return new class ($toolName, $reason) implements ToolCallPolicyInterface {
            public function __construct(private readonly string $toolName, private readonly ToolDenialReason $reason) {}

            public function decide(string $toolName, LlmConfiguration $configuration, ?BackendUserAuthentication $user): ToolPolicyDecision
            {
                return $this->decision(false);
            }

            public function filterOfferable(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
            {
                return [];
            }

            public function explain(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
            {
                return [$this->decision(false)];
            }

            private function decision(bool $allowed): ToolPolicyDecision
            {
                return new ToolPolicyDecision(
                    toolName: $this->toolName,
                    allowed: $allowed,
                    dataClass: ToolDataClass::SYSTEM_DIAGNOSTICS,
                    zone: TrustZone::EXTERNAL_GLOBAL,
                    ceiling: ToolDataClass::EDITOR_CONTENT,
                    reason: $this->reason,
                );
            }
        };
    }

    private function policyAllowing(string $toolName): ToolCallPolicyInterface
    {
        return new class ($toolName) implements ToolCallPolicyInterface {
            public function __construct(private readonly string $toolName) {}

            public function decide(string $toolName, LlmConfiguration $configuration, ?BackendUserAuthentication $user): ToolPolicyDecision
            {
                return $this->decision();
            }

            public function filterOfferable(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
            {
                return [$this->toolName];
            }

            public function explain(?array $requested, LlmConfiguration $configuration, ?BackendUserAuthentication $user): array
            {
                return [$this->decision()];
            }

            private function decision(): ToolPolicyDecision
            {
                return new ToolPolicyDecision(
                    toolName: $this->toolName,
                    allowed: true,
                    dataClass: ToolDataClass::PUBLIC_CONTENT,
                    zone: TrustZone::LOCAL,
                    ceiling: ToolDataClass::SECRET_ADJACENT,
                    reason: ToolDenialReason::NONE,
                );
            }
        };
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Middleware;

use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(BudgetMiddleware::class)]
#[CoversClass(BudgetExceededException::class)]
#[AllowMockObjectsWithoutExpectations]
final class BudgetMiddlewareTest extends AbstractUnitTestCase
{
    private BudgetServiceInterface&MockObject $budgetService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->budgetService = $this->createMock(BudgetServiceInterface::class);
    }

    #[Test]
    public function callsNextWhenBudgetCheckAllows(): void
    {
        $this->budgetService->method('check')
            ->willReturn(BudgetCheckResult::allowed());

        $called = false;
        $result = $this->pipeline()->run(
            context: $this->contextFor(beUserUid: 42, plannedCost: 0.5),
            configuration: $this->configuration(),
            terminal: static function (LlmConfiguration $c) use (&$called): string {
                $called = true;

                return 'ok';
            },
        );

        self::assertSame('ok', $result);
        self::assertTrue($called, 'Terminal must run when budget check allows.');
    }

    #[Test]
    public function throwsBudgetExceededExceptionWhenDenied(): void
    {
        $denial = BudgetCheckResult::denied(
            exceededLimit: BudgetCheckResult::LIMIT_DAILY_COST,
            currentUsage: 9.5,
            limit: 10.0,
        );
        $this->budgetService->method('check')->willReturn($denial);

        $terminalWasCalled = false;

        try {
            $this->pipeline()->run(
                context: $this->contextFor(beUserUid: 42, plannedCost: 1.0),
                configuration: $this->configuration(),
                terminal: static function () use (&$terminalWasCalled): string {
                    $terminalWasCalled = true;

                    return 'should-never-run';
                },
            );
            self::fail('Expected BudgetExceededException.');
        } catch (BudgetExceededException $e) {
            self::assertSame($denial, $e->result);
            self::assertSame(BudgetCheckResult::LIMIT_DAILY_COST, $e->result->exceededLimit);
            self::assertSame($denial->reason, $e->getMessage());
            self::assertFalse($terminalWasCalled, 'Terminal must not run after denial.');
        }
    }

    #[Test]
    public function passesUidZeroWhenMetadataAbsent(): void
    {
        $this->budgetService->expects(self::once())
            ->method('check')
            ->with(0, 0.0)
            ->willReturn(BudgetCheckResult::allowed());

        $this->pipeline()->run(
            context: ProviderCallContext::for(ProviderOperation::Chat),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): string => 'ok',
        );
    }

    #[Test]
    public function coercesIntegerPlannedCostToFloat(): void
    {
        $this->budgetService->expects(self::once())
            ->method('check')
            ->with(7, 3.0)
            ->willReturn(BudgetCheckResult::allowed());

        $this->pipeline()->run(
            context: $this->contextFor(beUserUid: 7, plannedCost: 3), // int, not float
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): string => 'ok',
        );
    }

    #[Test]
    public function ignoresNonNumericMetadataSilently(): void
    {
        // Non-int for uid and non-numeric for cost must fall back to
        // 0 / 0.0 rather than cast surprisingly.
        $this->budgetService->expects(self::once())
            ->method('check')
            ->with(0, 0.0)
            ->willReturn(BudgetCheckResult::allowed());

        $context = new ProviderCallContext(
            operation: ProviderOperation::Chat,
            correlationId: 'test',
            metadata: [
                BudgetMiddleware::METADATA_BE_USER_UID  => 'not-an-int',
                BudgetMiddleware::METADATA_PLANNED_COST => 'not-a-number',
            ],
        );

        $this->pipeline()->run(
            context: $context,
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): string => 'ok',
        );
    }

    #[Test]
    public function passesNegativeUidThroughToService(): void
    {
        // Per ADR-025 rule 1 BudgetService itself treats uid <= 0 as
        // "allowed / unauthenticated". The middleware does not pre-filter;
        // it forwards whatever was on the metadata so the service keeps
        // ownership of that policy.
        $this->budgetService->expects(self::once())
            ->method('check')
            ->with(-1, 0.0)
            ->willReturn(BudgetCheckResult::allowed());

        $this->pipeline()->run(
            context: $this->contextFor(beUserUid: -1, plannedCost: 0.0),
            configuration: $this->configuration(),
            terminal: static fn(LlmConfiguration $c): string => 'ok',
        );
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    private function pipeline(): MiddlewarePipeline
    {
        return new MiddlewarePipeline([new BudgetMiddleware($this->budgetService)]);
    }

    /**
     * @param int|float|string $plannedCost accepts int to exercise the
     *                                      int->float coercion path
     */
    private function contextFor(int $beUserUid, int|float|string $plannedCost): ProviderCallContext
    {
        return new ProviderCallContext(
            operation: ProviderOperation::Chat,
            correlationId: 'test',
            metadata: [
                BudgetMiddleware::METADATA_BE_USER_UID  => $beUserUid,
                BudgetMiddleware::METADATA_PLANNED_COST => $plannedCost,
            ],
        );
    }

    private function configuration(): LlmConfiguration
    {
        $config = new LlmConfiguration();
        $config->setIdentifier('primary');

        return $config;
    }
}

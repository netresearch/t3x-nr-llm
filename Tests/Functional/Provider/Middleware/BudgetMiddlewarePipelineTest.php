<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Provider\Middleware;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Provider\Middleware\BudgetMiddleware;
use Netresearch\NrLlm\Provider\Middleware\MiddlewarePipeline;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetService;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * End-to-end budget gate: a real {@see LlmConfiguration} dispatched through the
 * REAL {@see MiddlewarePipeline} + REAL {@see BudgetMiddleware} + REAL
 * {@see BudgetService}, with the per-user ceiling and existing usage read from
 * the actual database (`tx_nrllm_user_budget` + `tx_nrllm_service_usage`).
 *
 * The unit-level {@see \Netresearch\NrLlm\Tests\Unit\Provider\Middleware\BudgetMiddlewareTest}
 * stubs BudgetServiceInterface, so the wiring from a DB budget through the real
 * service's aggregation and the typed denial was never exercised. Here only the
 * terminal provider call is a no-op closure (no network); everything between the
 * pipeline entry and the budget decision is production code resolved from the DI
 * container.
 */
#[CoversClass(BudgetMiddleware::class)]
#[CoversClass(BudgetExceededException::class)]
final class BudgetMiddlewarePipelineTest extends AbstractFunctionalTestCase
{
    private const TABLE_BUDGET = 'tx_nrllm_user_budget';
    private const TABLE_USAGE  = 'tx_nrllm_service_usage';

    private const BE_USER_UID = 42;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;
    }

    #[Test]
    public function withinBudgetRequestReachesTerminalAndReturnsResult(): void
    {
        // Monthly ceiling of 10.0; only 1.0 spent so far. A 0.5 planned cost on
        // the next call (10.0 - 1.0 - 0.5 still positive) must be allowed.
        $this->insertBudget(maxCostPerMonth: 10.0);
        $this->insertUsageRow(estimatedCost: 1.0);

        $terminalRan = false;
        $result = $this->pipeline()->run(
            context: $this->contextFor(plannedCost: 0.5),
            configuration: $this->configuration(),
            terminal: static function (LlmConfiguration $config) use (&$terminalRan): string {
                $terminalRan = true;

                // The middleware must forward the SAME configuration untouched.
                return $config->getIdentifier();
            },
        );

        self::assertTrue($terminalRan, 'A within-budget request must reach the terminal provider call.');
        self::assertSame('primary', $result, 'The terminal receives the dispatched configuration.');
    }

    #[Test]
    public function overBudgetRequestIsBlockedWithTypedException(): void
    {
        // Monthly ceiling of 10.0 already fully consumed (10.0 spent). The next
        // call's projected total (10.0 + 0.5) overflows the monthly-cost bucket.
        $this->insertBudget(maxCostPerMonth: 10.0);
        $this->insertUsageRow(estimatedCost: 10.0);

        $terminalRan = false;

        try {
            $this->pipeline()->run(
                context: $this->contextFor(plannedCost: 0.5),
                configuration: $this->configuration(),
                terminal: static function () use (&$terminalRan): string {
                    $terminalRan = true;

                    return 'should-never-run';
                },
            );
            self::fail('Expected BudgetExceededException for an over-budget request.');
        } catch (BudgetExceededException $e) {
            self::assertFalse($terminalRan, 'An over-budget request must NOT reach the terminal call.');
            // The real BudgetService named the monthly-cost bucket as the one
            // that tripped, with the genuine current usage read from the DB.
            self::assertFalse($e->result->allowed);
            self::assertSame(BudgetCheckResult::LIMIT_MONTHLY_COST, $e->result->exceededLimit);
            self::assertEqualsWithDelta(10.0, $e->result->currentUsage, 0.0001);
            self::assertEqualsWithDelta(10.0, $e->result->limit, 0.0001);
        }
    }

    #[Test]
    public function requestForUserWithoutBudgetRecordProceeds(): void
    {
        // No budget row at all for this user: the real service returns allowed()
        // (BudgetService::check short-circuits on a missing record), so the call
        // must reach the terminal even with a non-trivial planned cost.
        $terminalRan = false;
        $this->pipeline()->run(
            context: $this->contextFor(plannedCost: 99.0),
            configuration: $this->configuration(),
            terminal: static function () use (&$terminalRan): string {
                $terminalRan = true;

                return 'ok';
            },
        );

        self::assertTrue($terminalRan, 'A user with no budget record is unconstrained and proceeds.');
    }

    /**
     * Build a real pipeline containing ONLY the container-resolved
     * BudgetMiddleware (which itself wraps the real BudgetService + DB-backed
     * usage windows). Isolating the single middleware keeps the assertions about
     * the budget gate's behaviour, not the full default stack ordering (covered
     * by MiddlewarePipelineOrderTest).
     */
    private function pipeline(): MiddlewarePipeline
    {
        $budgetService = $this->get(BudgetService::class);
        self::assertInstanceOf(BudgetService::class, $budgetService);

        $middleware = $this->get(BudgetMiddleware::class);
        self::assertInstanceOf(BudgetMiddleware::class, $middleware);

        return new MiddlewarePipeline([$middleware]);
    }

    private function contextFor(float $plannedCost): ProviderCallContext
    {
        return new ProviderCallContext(
            operation: ProviderOperation::Chat,
            correlationId: 'functional-budget-test',
            metadata: [
                BudgetMiddleware::METADATA_BE_USER_UID  => self::BE_USER_UID,
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

    private function insertBudget(float $maxCostPerMonth): void
    {
        $now = (new DateTimeImmutable('now'))->getTimestamp();
        $this->connectionPool->getConnectionForTable(self::TABLE_BUDGET)->insert(self::TABLE_BUDGET, [
            'pid'                     => 0,
            'be_user'                 => self::BE_USER_UID,
            'max_requests_per_day'    => 0,
            'max_tokens_per_day'      => 0,
            'max_cost_per_day'        => 0.0,
            'max_requests_per_month'  => 0,
            'max_tokens_per_month'    => 0,
            'max_cost_per_month'      => $maxCostPerMonth,
            'is_active'               => 1,
            'tstamp'                  => $now,
            'crdate'                  => $now,
            'deleted'                 => 0,
            'hidden'                  => 0,
        ]);
    }

    /**
     * Insert a usage row dated to "now" so it falls inside the current monthly
     * window the BudgetService aggregates over.
     */
    private function insertUsageRow(float $estimatedCost): void
    {
        $now = (new DateTimeImmutable('now'))->getTimestamp();
        $this->connectionPool->getConnectionForTable(self::TABLE_USAGE)->insert(self::TABLE_USAGE, [
            'pid'                => 0,
            'service_type'       => 'chat',
            'service_provider'   => 'openai',
            'configuration_uid'  => 0,
            'model_uid'          => 1,
            'model_id'           => 'gpt-4o',
            'be_user'            => self::BE_USER_UID,
            'request_count'      => 1,
            'tokens_used'        => 100,
            'prompt_tokens'      => 60,
            'completion_tokens'  => 40,
            'characters_used'    => 0,
            'audio_seconds_used' => 0,
            'images_generated'   => 0,
            'estimated_cost'     => $estimatedCost,
            'request_date'       => $now,
            'tstamp'             => $now,
            'crdate'             => $now,
        ]);
    }
}

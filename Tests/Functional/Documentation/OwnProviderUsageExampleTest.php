<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Documentation;

use Closure;
use DateTimeImmutable;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\BudgetService;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\UsageAnalyticsServiceInterface;
use Netresearch\NrLlm\Service\UsageTrackerService;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Runs the PHP example of Documentation/Developer/OwnProviderUsage.rst
 * against the real budget and usage services and the database.
 *
 * The code is read from the page itself, so the manual and this test cannot
 * drift apart: every `.. code-block:: php` on that page is executed here.
 */
#[CoversClass(BudgetService::class)]
#[CoversClass(UsageTrackerService::class)]
final class OwnProviderUsageExampleTest extends AbstractFunctionalTestCase
{
    private const MANUAL = '/Documentation/Developer/OwnProviderUsage.rst';

    private const BE_USER_UID = 42;

    private const USAGE_TABLE = 'tx_nrllm_service_usage';

    private const BUDGET_TABLE = 'tx_nrllm_user_budget';

    /** 1000 prompt tokens at 2.50 USD and 1500 completion tokens at 10.00 USD per million. */
    private const EXPECTED_COST = 0.0175;

    private static ?string $exampleClass = null;

    private int $providerCalls = 0;

    #[Test]
    public function documentedCallIsRecordedForTheEditorAndTheExtension(): void
    {
        $this->insertBudget(maxCostPerDay: 0.03);

        self::assertSame('A short summary.', $this->summarize('A long text.'));
        self::assertSame(1, $this->providerCalls);

        $rows = $this->connectionPool()
            ->getConnectionForTable(self::USAGE_TABLE)
            ->select(['*'], self::USAGE_TABLE)
            ->fetchAllAssociative();
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('chat', $row['service_type']);
        self::assertSame('example', $row['service_provider']);
        self::assertSame('my_extension', $row['source_extension']);
        self::assertSame('example-model', $row['model_id']);
        self::assertSame(self::BE_USER_UID, (int) $row['be_user']);
        self::assertSame(1, (int) $row['request_count']);
        self::assertSame(2500, (int) $row['tokens_used']);
        self::assertSame(1000, (int) $row['prompt_tokens']);
        self::assertSame(1500, (int) $row['completion_tokens']);
        self::assertIsNumeric($row['estimated_cost']);
        self::assertEqualsWithDelta(self::EXPECTED_COST, (float) $row['estimated_cost'], 0.000001);

        // What the administrator sees: the Analytics per-extension table.
        $today     = new DateTimeImmutable('today');
        $breakdown = $this->getService(UsageAnalyticsServiceInterface::class)
            ->getBreakdownBySourceExtension($today, $today);
        self::assertCount(1, $breakdown);
        self::assertSame('my_extension', $breakdown[0]['label']);
        self::assertSame(1, $breakdown[0]['requests']);
        self::assertEqualsWithDelta(self::EXPECTED_COST, $breakdown[0]['cost'], 0.000001);
    }

    #[Test]
    public function documentedCheckRefusesTheCallOnceTheDailyCostLimitIsReached(): void
    {
        // The first call is planned at 0.02 and costs 0.0175; the second is
        // planned at 0.02 again, and 0.0175 + 0.02 exceeds the 0.03 limit.
        $this->insertBudget(maxCostPerDay: 0.03);
        $this->summarize('First text.');

        try {
            $this->summarize('Second text.');
            self::fail('The documented check must refuse a call over the daily cost limit.');
        } catch (BudgetExceededException $exception) {
            self::assertSame(BudgetCheckResult::LIMIT_DAILY_COST, $exception->result->exceededLimit);
            self::assertEqualsWithDelta(self::EXPECTED_COST, $exception->result->currentUsage, 0.000001);
            self::assertEqualsWithDelta(0.03, $exception->result->limit, 0.000001);
        }

        self::assertSame(1, $this->providerCalls, 'A refused call must not reach the provider.');
        $requests = $this->connectionPool()
            ->getConnectionForTable(self::USAGE_TABLE)
            ->executeQuery('SELECT SUM(request_count) FROM ' . self::USAGE_TABLE)
            ->fetchOne();
        self::assertSame(1, (int) $requests, 'A refused call must not be recorded.');
    }

    private function summarize(string $text): string
    {
        $factory  = new HttpFactory();
        $class    = $this->exampleClass();
        $instance = new $class(
            $this->getService(BudgetServiceInterface::class),
            $this->getService(UsageTrackerServiceInterface::class),
            $this->providerClient(),
            $factory,
            $factory,
        );
        $summarize = [$instance, 'summarize'];
        self::assertIsCallable($summarize);
        $summary = $summarize(self::BE_USER_UID, $text);
        self::assertIsString($summary);

        return $summary;
    }

    /**
     * Loads the page's PHP code once per process and returns the name of the
     * class it declares.
     */
    private function exampleClass(): string
    {
        if (self::$exampleClass !== null) {
            return self::$exampleClass;
        }

        $manual = file_get_contents(dirname(__DIR__, 3) . self::MANUAL);
        self::assertIsString($manual);
        preg_match_all(
            '/^\.\. code-block:: php\R(?:   :[^\r\n]*\R)*\R((?:   [^\r\n]*\R|\R)+)/m',
            $manual,
            $matches,
        );
        self::assertCount(1, $matches[1], 'The page must contain exactly one PHP example, the one this test runs.');
        $code = preg_replace('/^ {3}/m', '', $matches[1][0]) ?? '';
        self::assertSame(1, preg_match('/^namespace ([\w\\\\]+);$/m', $code, $namespace));
        self::assertSame(1, preg_match('/^final readonly class (\w+)$/m', $code, $class));

        $file = tempnam(sys_get_temp_dir(), 'nrllm-doc-example-');
        self::assertIsString($file);
        try {
            file_put_contents($file, $code);
            require $file;
        } finally {
            unlink($file);
        }

        self::$exampleClass = $namespace[1] . '\\' . $class[1];
        self::assertTrue(class_exists(self::$exampleClass, false));

        return self::$exampleClass;
    }

    /**
     * Stands in for the provider: answers every request with an
     * OpenAI-style completion that used 1000 prompt and 1500 completion tokens.
     */
    private function providerClient(): ClientInterface
    {
        return new class (function (): void {
            ++$this->providerCalls;
        }) implements ClientInterface {
            /**
             * @param Closure(): void $onRequest
             */
            public function __construct(private readonly Closure $onRequest) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ($this->onRequest)();

                return new Response(200, ['Content-Type' => 'application/json'], json_encode([
                    'model'   => 'example-model',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'A short summary.']]],
                    'usage'   => ['prompt_tokens' => 1000, 'completion_tokens' => 1500],
                ], JSON_THROW_ON_ERROR));
            }
        };
    }

    private function insertBudget(float $maxCostPerDay): void
    {
        $now = time();
        $this->connectionPool()->getConnectionForTable(self::BUDGET_TABLE)->insert(self::BUDGET_TABLE, [
            'pid'                    => 0,
            'be_user'                => self::BE_USER_UID,
            'max_requests_per_day'   => 0,
            'max_tokens_per_day'     => 0,
            'max_cost_per_day'       => $maxCostPerDay,
            'max_requests_per_month' => 0,
            'max_tokens_per_month'   => 0,
            'max_cost_per_month'     => 0.0,
            'is_active'              => 1,
            'tstamp'                 => $now,
            'crdate'                 => $now,
            'deleted'                => 0,
            'hidden'                 => 0,
        ]);
    }

    private function connectionPool(): ConnectionPool
    {
        return $this->getService(ConnectionPool::class);
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Telemetry;

use Netresearch\NrLlm\Domain\Enum\RequestShape;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ProviderCallUsage;
use Netresearch\NrLlm\Domain\ValueObject\RequestFacts;
use Netresearch\NrLlm\Service\Complexity\RequestFactsCollector;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRecord;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The per-call cost and the pre-routing fact set, written and read back
 * (ADR-174; issues #770 and #771).
 *
 * The assertions that matter are the NULL ones. Every figure here has a zero
 * that means something else — 0 tokens, a cost of 0, 0 messages — and the whole
 * value of the columns depends on those zeros never being written where nothing
 * was measured. A round trip through the real schema is the only place that can
 * be proven: the column defaults, not the PHP, are what would turn a null into
 * a zero.
 */
#[CoversClass(TelemetryRepository::class)]
final class CallCostTelemetryTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_telemetry';

    private TelemetryRepository $repository;

    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        // Private in the container by design; instantiate it directly with the
        // real ConnectionPool, as the sibling telemetry tests do.
        $this->repository = new TelemetryRepository($this->connectionPool);
    }

    #[Test]
    public function aPricedCallStoresItsRealTokensCostAndServingModel(): void
    {
        $this->repository->record($this->record(
            'corr-priced',
            new ProviderCallUsage(1000, 500, 0.00750000, 'gpt-4o-2024-08-06'),
            2,
        ));

        $row = $this->row('corr-priced');

        self::assertSame(1000, (int)$row['actual_input_tokens']);
        self::assertSame(500, (int)$row['actual_output_tokens']);
        self::assertIsNumeric($row['actual_cost']);
        self::assertSame(0.0075, (float)$row['actual_cost']);
        self::assertSame('gpt-4o-2024-08-06', $row['response_model']);
        self::assertSame(2, (int)$row['provider_retries']);
    }

    /**
     * The defect issue #770 names. A model priced at zero produces real tokens
     * and NO cost — and the row has to keep those apart, because "this arm was
     * free" and "nobody priced this arm" are the two readings a cheap-model
     * experiment has to distinguish.
     */
    #[Test]
    public function anUnpricedModelStoresRealTokensAndANullCost(): void
    {
        $this->repository->record($this->record(
            'corr-unpriced',
            new ProviderCallUsage(1200, 340, null, 'qwen3:4b'),
            0,
        ));

        $row = $this->row('corr-unpriced');

        self::assertSame(1200, (int)$row['actual_input_tokens']);
        self::assertSame(340, (int)$row['actual_output_tokens']);
        self::assertNull($row['actual_cost'], 'A model with no pricing has no cost; 0 would read as free.');
        self::assertSame(0, (int)$row['provider_retries'], 'Zero retries is a measurement, not an absence.');
    }

    #[Test]
    public function aProviderThatReportedNoUsageStoresNullTokens(): void
    {
        $this->repository->record($this->record(
            'corr-no-usage',
            new ProviderCallUsage(null, null, null, ''),
            0,
        ));

        $row = $this->row('corr-no-usage');

        self::assertNull($row['actual_input_tokens']);
        self::assertNull($row['actual_output_tokens']);
        self::assertNull($row['actual_cost']);
        self::assertSame('', $row['response_model']);
    }

    /**
     * A cache hit, a failed run and a streamed run all reach the writer with no
     * usage at all. None of them spent a token, and none of them measured zero.
     *
     * The `provider_retries` assertion below is about the COLUMN, not about any
     * writer: no production caller omits the count, so the NULL asserted here is
     * the state rows written before the column existed carry. That both write
     * sites really do always pass one is asserted where it can be —
     * {@see \Netresearch\NrLlm\Tests\Unit\Provider\Middleware\TelemetryMiddlewareTest::everyRowCarriesAMeasuredRetryCountAndNeverNull()}
     * for the pipeline and the retry tests in
     * {@see \Netresearch\NrLlm\Tests\Unit\Service\Streaming\StreamingDispatcherTest}
     * for the stream.
     */
    #[Test]
    public function aRunWithNoProviderCallStoresNullsAcrossTheCostGroup(): void
    {
        $this->repository->record($this->record('corr-nothing', null, null));

        $row = $this->row('corr-nothing');

        self::assertNull($row['actual_input_tokens']);
        self::assertNull($row['actual_output_tokens']);
        self::assertNull($row['actual_cost']);
        self::assertSame('', $row['response_model']);
        self::assertNull($row['provider_retries'], 'A record built without a count writes NULL, as rows older than the column carry.');
    }

    #[Test]
    public function theRequestFactsRoundTripAsMeasured(): void
    {
        $this->repository->record($this->record(
            'corr-facts',
            null,
            null,
            new RequestFacts(4, 3, 2, 4096, 1180, RequestShape::TOOL_ASSISTED->value),
        ));

        $row = $this->row('corr-facts');

        self::assertSame(4, (int)$row['facts_messages']);
        self::assertSame(3, (int)$row['facts_turns']);
        self::assertSame(2, (int)$row['facts_tools']);
        self::assertSame(4096, (int)$row['facts_payload_bytes']);
        self::assertSame(1180, (int)$row['facts_token_estimate']);
        self::assertSame('toolAssisted', $row['facts_shape']);
    }

    /**
     * The empty shape is the "nothing was measured" flag, exactly as
     * complexity_shape is (ADR-156) — and the numbers beside it are NULL rather
     * than 0, so a reader that averages them cannot silently include the runs
     * that measured nothing.
     */
    #[Test]
    public function anUnmeasuredRequestStoresNullsAndAnEmptyShape(): void
    {
        $this->repository->record($this->record('corr-no-facts', null, null));

        $row = $this->row('corr-no-facts');

        self::assertNull($row['facts_messages']);
        self::assertNull($row['facts_turns']);
        self::assertNull($row['facts_tools']);
        self::assertNull($row['facts_payload_bytes']);
        self::assertNull($row['facts_token_estimate']);
        self::assertSame('', $row['facts_shape']);
    }

    /**
     * The row stays prompt-free: everything written is a count, a size, a price
     * or a model id.
     *
     * The fact set here is built by the PRODUCTION collector from a transcript
     * whose words would be recognisable if any of them survived, so a field
     * that started carrying content would carry it into the row. And every
     * column of the written row is checked rather than a list of names, so a
     * column added later is covered by the same assertion.
     */
    #[Test]
    public function noColumnOfTheWrittenRowCarriesAFragmentOfTheRequest(): void
    {
        $facts = (new RequestFactsCollector())->collect(
            [
                ChatMessage::system('You are Wilkins, and you answer tersely.'),
                ChatMessage::user('Ask Wilkins about the quarterly figures for Sandhurst.'),
            ],
            [['type' => 'function', 'function' => ['name' => 'read_sandhurst_ledger', 'parameters' => []]]],
        );

        $this->repository->record($this->record(
            'corr-privacy',
            new ProviderCallUsage(10, 5, 0.1, 'gpt-4o'),
            0,
            $facts,
        ));

        $row = $this->row('corr-privacy');

        foreach ($row as $column => $value) {
            foreach (['Wilkins', 'quarterly', 'Sandhurst', 'read_sandhurst_ledger', 'tersely'] as $fragment) {
                self::assertStringNotContainsString(
                    $fragment,
                    is_scalar($value) ? (string)$value : '',
                    sprintf('Column %s must never carry request content (%s).', $column, $fragment),
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $correlationId): array
    {
        $row = $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['correlation_id' => $correlationId])
            ->fetchAssociative();

        self::assertIsArray($row);

        return $row;
    }

    private function record(
        string $correlationId,
        ?ProviderCallUsage $usage,
        ?int $retries,
        ?RequestFacts $facts = null,
    ): TelemetryRecord {
        return new TelemetryRecord(
            correlationId: $correlationId,
            operation: 'chat',
            provider: 'openai',
            model: 'gpt-4o',
            configurationIdentifier: 'primary',
            beUser: 1,
            success: true,
            errorClass: '',
            latencyMs: 100,
            cacheHit: false,
            fallbackAttempts: 0,
            servedConfigurationIdentifier: 'primary',
            servedProvider: 'openai',
            servedModel: 'gpt-4o',
            requestFacts: $facts,
            callUsage: $usage,
            providerRetries: $retries,
        );
    }
}

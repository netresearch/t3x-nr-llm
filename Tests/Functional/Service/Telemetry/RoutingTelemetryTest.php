<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Telemetry;

use Netresearch\NrLlm\Domain\Enum\RequestShape;
use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Enum\RoutingRejectionReason;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
use Netresearch\NrLlm\Domain\ValueObject\RequestComplexity;
use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Domain\ValueObject\RoutingDecision;
use Netresearch\NrLlm\Domain\ValueObject\RoutingSummary;
use Netresearch\NrLlm\Service\Complexity\RequestComplexityEstimator;
use Netresearch\NrLlm\Service\Routing\RoutingSummaryFactory;
use Netresearch\NrLlm\Service\Telemetry\RoutedCall;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRecord;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The routing/complexity column groups, written and read back (ADR-156).
 *
 * Round-trip rather than two separate tests, because the point of the columns
 * is that the reader can answer "why model A" for a call that already happened:
 * a write nobody can read back would satisfy neither half.
 */
#[CoversClass(TelemetryRepository::class)]
#[CoversClass(RoutedCall::class)]
final class RoutingTelemetryTest extends AbstractFunctionalTestCase
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
        // real ConnectionPool, as TelemetryRepositoryTest does.
        $this->repository = new TelemetryRepository($this->connectionPool);
    }

    #[Test]
    public function aDecidedRunStoresItsSummaryAndComplexity(): void
    {
        $this->repository->record($this->record(
            'corr-decided',
            new RoutingSummary('balanced', 5, ['CAPABILITY_MISSING', 'COST_ABOVE_LIMIT'], true, false, true),
            new RequestComplexity(64, 4096, 1200, 3, 40, RequestShape::TOOL_ASSISTED->value),
        ));

        $row = $this->row('corr-decided');

        self::assertSame('balanced', $row['routing_policy_mode']);
        self::assertSame(5, (int)$row['routing_candidates']);
        self::assertSame('CAPABILITY_MISSING,COST_ABOVE_LIMIT', $row['routing_rejections']);
        self::assertSame(1, (int)$row['routing_signal_quality']);
        self::assertSame(0, (int)$row['routing_signal_health']);
        self::assertSame(1, (int)$row['routing_signal_cost']);
        self::assertSame(64, (int)$row['complexity_score']);
        self::assertSame(4096, (int)$row['complexity_payload_bytes']);
        self::assertSame(1200, (int)$row['complexity_tokens']);
        self::assertSame(3, (int)$row['complexity_tools']);
        self::assertSame(40, (int)$row['complexity_context_percent']);
        self::assertSame('toolAssisted', $row['complexity_shape']);
    }

    #[Test]
    public function aRunThatChoseNothingStoresAnEmptyModeRatherThanZeros(): void
    {
        // Fixed mode chose nothing. Writing a mode with zero candidates would
        // describe a decision that considered nothing, which is a different
        // (and alarming) claim.
        $this->repository->record($this->record('corr-fixed', null, null));

        $row = $this->row('corr-fixed');

        self::assertSame('', $row['routing_policy_mode']);
        self::assertSame(0, (int)$row['routing_candidates']);
        self::assertSame('', $row['routing_rejections']);
        self::assertSame('', $row['complexity_shape']);
    }

    #[Test]
    public function anUnmeasuredWindowIsStoredAsNullNotZero(): void
    {
        $this->repository->record($this->record(
            'corr-unmeasured',
            new RoutingSummary('providerPriority', 2, [], false, false, false),
            new RequestComplexity(5, 12, null, 0, null, RequestShape::SINGLE_TURN->value),
        ));

        $row = $this->row('corr-unmeasured');

        self::assertNull($row['complexity_tokens']);
        self::assertNull($row['complexity_context_percent']);
        self::assertSame(12, (int)$row['complexity_payload_bytes']);
    }

    #[Test]
    public function theReaderReturnsOnlyRunsThatRecordedADecision(): void
    {
        $this->repository->record($this->record('corr-fixed', null, null));
        $this->repository->record($this->record(
            'corr-routed',
            new RoutingSummary('economy', 4, ['CONTEXT_TOO_SMALL'], false, true, true),
            new RequestComplexity(22, 300, 90, 1, 9, RequestShape::MULTI_TURN->value),
        ));

        $calls = $this->repository->recentRoutedCalls(0, 20);

        self::assertCount(1, $calls);
        self::assertSame('corr-routed', $calls[0]->correlationId);
        self::assertSame('economy', $calls[0]->policyMode);
        self::assertSame(4, $calls[0]->candidateCount);
        self::assertSame(['CONTEXT_TOO_SMALL'], $calls[0]->rejectionReasons);
        self::assertFalse($calls[0]->qualitySignalUsed);
        self::assertTrue($calls[0]->healthSignalUsed);
        self::assertTrue($calls[0]->costSignalUsed);
        self::assertSame(22, $calls[0]->complexityScore);
        self::assertSame(300, $calls[0]->payloadBytes);
        self::assertSame(90, $calls[0]->complexityTokens);
        self::assertSame(9, $calls[0]->contextPercent);
        self::assertSame('multiTurn', $calls[0]->shape);
        self::assertSame('served-model', $calls[0]->servedModel);
    }

    #[Test]
    public function undecidedRunsDoNotConsumeTheRowLimit(): void
    {
        // The reason the narrowing lives in the query: an installation whose
        // traffic is mostly fixed-mode would otherwise fill the window with
        // rows the page drops and report its real decisions as none.
        for ($i = 0; $i < 5; ++$i) {
            $this->repository->record($this->record('corr-fixed-' . $i, null, null));
        }

        $this->repository->record($this->record(
            'corr-routed',
            new RoutingSummary('quality', 3, [], true, false, false),
            null,
        ));

        $calls = $this->repository->recentRoutedCalls(0, 2);

        self::assertCount(1, $calls);
        self::assertSame('corr-routed', $calls[0]->correlationId);
    }

    #[Test]
    public function theWindowExcludesRowsOlderThanTheCutoff(): void
    {
        $this->repository->record($this->record(
            'corr-old',
            new RoutingSummary('balanced', 1, [], false, false, false),
            null,
        ));

        $this->connectionPool
            ->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, ['crdate' => 1], ['correlation_id' => 'corr-old']);

        self::assertSame([], $this->repository->recentRoutedCalls(time() - 3600, 20));
    }

    #[Test]
    public function proseInTheRequestCannotReachTheRoutingOrComplexityColumns(): void
    {
        // Driven through the REAL producers with prose on every input they can
        // see — the model records the decision ranked, and the message contents
        // the estimator measured. Asserting a regex over values the test itself
        // wrote would only prove what the fixture contains; the guarantee is
        // that the producers emit enum cases and numbers, so no string from the
        // request has a path into a column.
        $prose = 'Terminate Wilkins effective Monday; severance is 40k EUR.';

        $ranked  = $this->model('a', $prose);
        $refused = $this->model('b', $prose);

        $summary = (new RoutingSummaryFactory())->fromDecision(new RoutingDecision(
            $ranked,
            [
                RoutingCandidate::eligible($ranked, 0.7, ['quality' => 0.7, 'health' => 0.5]),
                RoutingCandidate::rejected($refused, RoutingRejectionReason::CAPABILITY_MISSING),
            ],
            RoutingPolicyMode::BALANCED,
        ));

        $complexity = (new RequestComplexityEstimator())->estimate(
            [
                ['role' => 'system', 'content' => $prose],
                ['role' => 'user', 'content' => $prose],
            ],
            1,
            null,
        );

        $this->repository->record($this->record('corr-privacy', $summary, $complexity));

        $row = $this->row('corr-privacy');

        // What the producers DID emit: enum cases, verbatim.
        self::assertSame(RoutingPolicyMode::BALANCED->value, $row['routing_policy_mode']);
        self::assertSame(RoutingRejectionReason::CAPABILITY_MISSING->name, $row['routing_rejections']);
        self::assertSame(RequestShape::TOOL_ASSISTED->value, $row['complexity_shape']);

        // The prose reached the estimator — it is counted — and got no further.
        self::assertSame(strlen($prose) * 2, (int)$row['complexity_payload_bytes']);
        foreach ($row as $column => $value) {
            self::assertTrue($value === null || is_scalar($value), $column . ' is not a scalar column');
            self::assertStringNotContainsString(
                'Wilkins',
                is_scalar($value) ? (string)$value : '',
                $column . ' carries a fragment of the request; the row must stay prompt-free.',
            );
        }
    }

    private function model(string $suffix, string $prose): Model
    {
        // The names are the prose the row must not pick up: a model record is
        // operator-authored free text, and it is the only string the summary's
        // producer ever sees.
        $provider = new Provider();
        $provider->setIdentifier('openai');
        $provider->setAdapterType('openai');
        $provider->setName($prose);

        $model = new Model();
        $model->setIdentifier('model-' . $suffix);
        $model->setModelId('model-' . $suffix);
        $model->setName($prose);
        $model->setProvider($provider);

        return $model;
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
        ?RoutingSummary $routing,
        ?RequestComplexity $complexity,
    ): TelemetryRecord {
        return new TelemetryRecord(
            correlationId: $correlationId,
            operation: 'chat',
            provider: 'openai',
            model: '',
            configurationIdentifier: 'primary',
            beUser: 1,
            success: true,
            errorClass: '',
            latencyMs: 100,
            cacheHit: false,
            fallbackAttempts: 0,
            servedConfigurationIdentifier: 'primary',
            servedProvider: 'openai',
            servedModel: 'served-model',
            routingSummary: $routing,
            complexity: $complexity,
        );
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Analytics;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Analytics\FallbackRescueReport;
use Netresearch\NrLlm\Service\Telemetry\FallbackHop;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The analytics list shows exactly the runs another configuration answered.
 *
 * The repository hands over every recent run that dispatched a fallback; three
 * kinds of row look alike there and only the served identifier tells them
 * apart. This pins that all three are classified correctly — the whole reason
 * the `served_*` columns exist, since `fallback_attempts > 0` is true for all
 * of them.
 */
#[CoversClass(FallbackRescueReport::class)]
final class FallbackRescueReportTest extends AbstractUnitTestCase
{
    #[Test]
    public function listsOnlyTheHopsAnotherConfigurationActuallyServed(): void
    {
        $repository               = new InMemoryTelemetryRepository();
        $repository->fallbackHops = [
            // A rescue: the sibling answered.
            $this->hop('rescued', 'primary', 'sibling'),
            // Chain exhausted: hops happened, nobody served, the row still
            // names the requested configuration on both sides.
            $this->hop('exhausted', 'primary', 'primary', success: false),
            // Written before the served_* columns existed: unknown, not a swap.
            $this->hop('legacy', 'primary', ''),
        ];

        $rescues = (new FallbackRescueReport($repository))->rescuesSince(new DateTimeImmutable('-30 days'));

        self::assertCount(1, $rescues);
        self::assertSame('rescued', $rescues[0]['operation']);
        self::assertSame('primary', $rescues[0]['requestedConfiguration']);
        self::assertSame('sibling', $rescues[0]['servedConfiguration']);
    }

    #[Test]
    public function carriesBothConfigurationsProviderAndModelIntoTheRow(): void
    {
        $repository               = new InMemoryTelemetryRepository();
        $repository->fallbackHops = [
            new FallbackHop(
                correlationId: 'corr-1',
                operation: 'chat',
                configurationIdentifier: 'primary',
                provider: 'openai',
                model: 'gpt-5',
                servedConfigurationIdentifier: 'sibling',
                servedProvider: 'ollama',
                servedModel: 'llama3.3:70b',
                success: true,
                fallbackAttempts: 2,
                latencyMs: 1234,
                crdate: 1_770_000_000,
            ),
        ];

        $rescues = (new FallbackRescueReport($repository))->rescuesSince(new DateTimeImmutable('-30 days'));

        self::assertSame([
            'when'                   => 1_770_000_000,
            'operation'              => 'chat',
            'requestedConfiguration' => 'primary',
            'requestedProvider'      => 'openai',
            'requestedModel'         => 'gpt-5',
            'servedConfiguration'    => 'sibling',
            'servedProvider'         => 'ollama',
            'servedModel'            => 'llama3.3:70b',
            'fallbackAttempts'       => 2,
            'latencyMs'              => 1234,
            'success'                => true,
        ], $rescues[0]);
    }

    #[Test]
    public function keepsASwapWhoseRunLaterFailed(): void
    {
        // The streaming path can open a sibling's stream and still break while
        // draining it. The sibling stepped in, so the swap is worth showing —
        // marked as the failure it ended in.
        $repository               = new InMemoryTelemetryRepository();
        $repository->fallbackHops = [$this->hop('stream', 'primary', 'sibling', success: false)];

        $rescues = (new FallbackRescueReport($repository))->rescuesSince(new DateTimeImmutable('-30 days'));

        self::assertCount(1, $rescues);
        self::assertFalse($rescues[0]['success']);
    }

    #[Test]
    public function asksTheRepositoryOnlyForRunsInsideTheSelectedPeriod(): void
    {
        $repository = new InMemoryTelemetryRepository();
        $from       = new DateTimeImmutable('2026-07-01 00:00:00');

        (new FallbackRescueReport($repository))->rescuesSince($from);

        self::assertSame($from->getTimestamp(), $repository->hopsSince);
        self::assertNotNull($repository->hopsLimit);
        self::assertGreaterThan(0, $repository->hopsLimit);
    }

    private function hop(string $operation, string $requested, string $served, bool $success = true): FallbackHop
    {
        return new FallbackHop(
            correlationId: 'corr-' . $operation,
            operation: $operation,
            configurationIdentifier: $requested,
            provider: 'openai',
            model: 'gpt-5',
            servedConfigurationIdentifier: $served,
            servedProvider: $served === '' ? '' : 'ollama',
            servedModel: $served === '' ? '' : 'llama3.3:70b',
            success: $success,
            fallbackAttempts: 1,
            latencyMs: 100,
            crdate: 1_770_000_000,
        );
    }
}

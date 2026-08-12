<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\ValueObject\AgentRunReference;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Service\CallMetadataFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The agent-run producer (ADR-153): the metadata key GuardrailMiddleware reads
 * to attribute a governance row to the run that caused it.
 */
#[CoversClass(CallMetadataFactory::class)]
final class CallMetadataFactoryAgentRunTest extends TestCase
{
    #[Test]
    public function aPersistedRunBecomesTheAgentRunUidKey(): void
    {
        $metadata = (new CallMetadataFactory())->agentRun(new AgentRunReference(91, 'c0ffee00-0000-4000-8000-000000000042'));

        self::assertSame([GuardrailMiddleware::METADATA_AGENT_RUN_UID => 91], $metadata);
    }

    #[Test]
    public function noRunProducesNoEntrySoTheMiddlewaresZeroMeansOutsideARun(): void
    {
        self::assertSame([], (new CallMetadataFactory())->agentRun(null));
    }

    #[Test]
    public function anUnpersistedRunProducesNoEntryEither(): void
    {
        // uid 0 = the row could not be stored; there is nothing for a governance
        // event to point at, so writing 0 explicitly would claim more than it can.
        self::assertSame([], (new CallMetadataFactory())->agentRun(new AgentRunReference(0, '')));
    }

    #[Test]
    public function theKeySetIsDisjointFromTheOtherProducers(): void
    {
        $factory = new CallMetadataFactory();

        $merged = $factory->budget(5, 0.25)
            + $factory->idempotency('key-1')
            + $factory->agentRun(new AgentRunReference(91, 'c0ffee00-0000-4000-8000-000000000042'));

        // Disjointness is load-bearing: every call site merges with `+`, which
        // keeps the FIRST value on a collision.
        self::assertCount(4, $merged);
        self::assertSame(91, $merged[GuardrailMiddleware::METADATA_AGENT_RUN_UID]);
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Tool\EditorActionBatchEntry;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The invariant that keeps a batch honest (ADR-162): a record is either in it
 * with a run, or out of it with a reason a human reads. A third state is how a
 * record disappears silently.
 */
#[CoversClass(EditorActionBatchEntry::class)]
final class EditorActionBatchEntryTest extends AbstractUnitTestCase
{
    #[Test]
    public function aRunnableEntryCarriesTheRunAndNoReason(): void
    {
        $entry = new EditorActionBatchEntry(42, $this->request());

        self::assertTrue($entry->isRunnable());
        self::assertNull($entry->skipReasonKey);
    }

    #[Test]
    public function askippedEntryCarriesTheReasonAndNoRun(): void
    {
        $entry = new EditorActionBatchEntry(42, null, 'LLL:some.reason');

        self::assertFalse($entry->isRunnable());
        self::assertNull($entry->request);
    }

    #[Test]
    public function refusesAnEntryThatIsNeitherRunnableNorSkipped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1786700001);

        $entry = new EditorActionBatchEntry(42);

        // S1848: the instantiation above must be used, and a constructor that
        // wrongly accepted this is worth naming in the failure.
        self::fail('A batch entry with neither a run nor a reason was accepted: ' . var_export($entry->isRunnable(), true));
    }

    #[Test]
    public function refusesAnEntryThatIsBothRunnableAndSkipped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1786700001);

        $entry = new EditorActionBatchEntry(42, $this->request(), 'LLL:some.reason');

        self::fail('A batch entry carrying both a run and a reason was accepted: ' . var_export($entry->isRunnable(), true));
    }

    private function request(): AgentRunRequest
    {
        return new AgentRunRequest(
            configuration: new LlmConfiguration(),
            messages: [],
            actor: AiActorContext::backendUser(1),
        );
    }
}

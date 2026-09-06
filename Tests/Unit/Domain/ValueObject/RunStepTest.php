<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ArtifactType;
use Netresearch\NrLlm\Domain\Enum\ToolOutcome;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Domain\ValueObject\ToolArtifact;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunStep::class)]
final class RunStepTest extends TestCase
{
    #[Test]
    public function toArrayAlwaysCarriesKindRoundAndRoundedDuration(): void
    {
        $step = new RunStep(kind: RunStep::KIND_LLM, round: 2, durationMs: 12.3456);
        $array = $step->toArray();

        self::assertSame('llm', $array['kind']);
        self::assertSame(2, $array['round']);
        self::assertSame(12.35, $array['durationMs']);
    }

    #[Test]
    public function toArrayDropsNullFieldsSoEachKindOnlyEmitsItsOwnKeys(): void
    {
        $step = new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 5.0, toolName: 'fetch', toolResult: 'ok', toolIsError: false);
        $array = $step->toArray();

        self::assertSame('fetch', $array['toolName']);
        self::assertSame('ok', $array['toolResult']);
        self::assertFalse($array['toolIsError']);
        // LLM-only keys must be absent for a tool step.
        self::assertArrayNotHasKey('content', $array);
        self::assertArrayNotHasKey('promptTokens', $array);
        self::assertArrayNotHasKey('messagesSent', $array);
        // No artifacts on this step: the key is dropped entirely (ADR-108).
        self::assertArrayNotHasKey('toolArtifacts', $array);
    }

    #[Test]
    public function toArraySerialisesToolArtifactsAsPlainArrayList(): void
    {
        $step = new RunStep(
            kind: RunStep::KIND_TOOL,
            round: 1,
            durationMs: 5.0,
            toolName: 'read_records',
            toolResult: 'ok',
            toolIsError: false,
            toolArtifacts: [
                new ToolArtifact(ArtifactType::TABLE, 'pages', ['columns' => ['uid'], 'rows' => [['1']]]),
            ],
        );

        $array = $step->toArray();

        self::assertSame(
            [['type' => 'table', 'label' => 'pages', 'data' => ['columns' => ['uid'], 'rows' => [['1']]]]],
            $array['toolArtifacts'],
        );
    }

    #[Test]
    public function toArrayKeepsZeroAndFalseButNotNull(): void
    {
        $step = new RunStep(
            kind: RunStep::KIND_LLM,
            round: 1,
            durationMs: 0.0,
            promptTokens: 0,
            toolIsError: false,
        );
        $array = $step->toArray();

        // Zero and false are meaningful and must survive the null filter.
        self::assertSame(0, $array['promptTokens']);
        self::assertArrayHasKey('toolIsError', $array);
        self::assertArrayNotHasKey('thinking', $array);
    }

    /**
     * The pair is one statement in two fields (ADR-191). A step carrying
     * `false` beside CANCELLED would render as cancelled in the run inspector
     * while every consumer reading the boolean called it a success -- one row
     * telling two stories -- so it is refused where the pair is serialised
     * rather than at each writer.
     */
    #[Test]
    #[DataProvider('inconsistentPairs')]
    public function aStepCannotDisagreeWithItselfAboutTheOutcome(?bool $isError, ToolOutcome $outcome): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1788500001);

        $step = new RunStep(
            kind: RunStep::KIND_TOOL,
            round: 1,
            durationMs: 1.0,
            toolIsError: $isError,
            toolOutcome: $outcome,
        );

        self::fail('A step was built stating ' . var_export($step->toolIsError, true) . ' beside ' . $step->toolOutcome?->value . '.');
    }

    /**
     * @return iterable<string, array{?bool, ToolOutcome}>
     */
    public static function inconsistentPairs(): iterable
    {
        yield 'no error, yet cancelled' => [false, ToolOutcome::CANCELLED];
        yield 'no error, yet failed'    => [false, ToolOutcome::FAILED];
        yield 'an error, yet ok'        => [true, ToolOutcome::OK];
        yield 'an outcome without the flag every tool step carries' => [null, ToolOutcome::CANCELLED];
    }

    /**
     * An outcome belongs to a tool step and to no other kind. An LLM step
     * carrying one would serialise a tool outcome onto a row the timeline reads
     * as a tool step's.
     */
    #[Test]
    public function aNonToolStepStatesNoToolOutcome(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1788500002);

        $step = new RunStep(
            kind: RunStep::KIND_LLM,
            round: 1,
            durationMs: 1.0,
            toolIsError: false,
            toolOutcome: ToolOutcome::OK,
        );

        self::fail('An ' . $step->kind . ' step was built stating ' . $step->toolOutcome?->value . '.');
    }

    #[Test]
    #[DataProvider('consistentPairs')]
    public function aConsistentPairIsAccepted(bool $isError, ToolOutcome $outcome): void
    {
        $step = new RunStep(
            kind: RunStep::KIND_TOOL,
            round: 1,
            durationMs: 1.0,
            toolIsError: $isError,
            toolOutcome: $outcome,
        );

        self::assertSame($outcome->value, $step->toArray()['toolOutcome']);
    }

    /**
     * @return iterable<string, array{bool, ToolOutcome}>
     */
    public static function consistentPairs(): iterable
    {
        yield 'ok'        => [false, ToolOutcome::OK];
        yield 'failed'    => [true, ToolOutcome::FAILED];
        yield 'cancelled' => [true, ToolOutcome::CANCELLED];
    }

    /**
     * A step written before ADR-191 carries no outcome at all, and every step
     * that is not a tool step carries neither field. Both stay constructible.
     */
    #[Test]
    public function aStepWithoutAnOutcomeIsUnaffected(): void
    {
        self::assertNull((new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 1.0, toolIsError: true))->toolOutcome);
        self::assertNull((new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 1.0))->toolOutcome);
    }
}

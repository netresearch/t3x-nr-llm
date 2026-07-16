<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend\Response;

use Netresearch\NrLlm\Controller\Backend\Response\PlaygroundRunResponse;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The playground run payload must expose the final answer, the ordered
 * inspector steps (each serialised via RunStep::toArray()) and the summed
 * usage block exactly as Playground.js consumes them.
 */
#[CoversClass(PlaygroundRunResponse::class)]
final class PlaygroundRunResponseTest extends AbstractUnitTestCase
{
    #[Test]
    public function toArrayExposesAnswerStepsAndUsage(): void
    {
        $llmStep  = new RunStep(kind: RunStep::KIND_LLM, round: 1, durationMs: 12.345, content: 'thinking done');
        $toolStep = new RunStep(kind: RunStep::KIND_TOOL, round: 1, durationMs: 3.0, toolName: 'fetch_logs');

        $response = new PlaygroundRunResponse(
            finalContent: 'The answer.',
            iterations: 2,
            truncated: false,
            dryRun: false,
            steps: [$llmStep, $toolStep],
            promptTokens: 10,
            completionTokens: 20,
            totalTokens: 30,
            estimatedCost: 0.0042,
        );

        $payload = $response->toArray();

        self::assertTrue($payload['success']);
        self::assertSame('The answer.', $payload['finalContent']);
        self::assertSame(2, $payload['iterations']);
        self::assertFalse($payload['truncated']);
        self::assertFalse($payload['dryRun']);
        self::assertSame(
            [$llmStep->toArray(), $toolStep->toArray()],
            $payload['steps'],
        );
        self::assertSame(
            [
                'promptTokens'     => 10,
                'completionTokens' => 20,
                'totalTokens'      => 30,
                'estimatedCost'    => 0.0042,
            ],
            $payload['usage'],
        );
    }

    #[Test]
    public function toArrayKeepsDryRunFlagAndNullCost(): void
    {
        $response = new PlaygroundRunResponse(
            finalContent: '',
            iterations: 0,
            truncated: true,
            dryRun: true,
            steps: [],
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
            estimatedCost: null,
        );

        $payload = $response->toArray();

        self::assertTrue($payload['dryRun']);
        self::assertTrue($payload['truncated']);
        self::assertSame([], $payload['steps']);
        self::assertIsArray($payload['usage']);
        self::assertNull($payload['usage']['estimatedCost']);
    }
}

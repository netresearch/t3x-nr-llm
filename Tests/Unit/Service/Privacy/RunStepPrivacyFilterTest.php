<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Privacy;

use Netresearch\NrLlm\Domain\Enum\PrivacyLevel;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use Netresearch\NrLlm\Service\Privacy\RunStepPrivacyFilter;
use Netresearch\NrLlm\Tests\Fixture\FixedPrivacyPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunStepPrivacyFilter::class)]
final class RunStepPrivacyFilterTest extends TestCase
{
    #[Test]
    public function metadataLevelDropsEveryContentBearingValue(): void
    {
        $filtered = FixedPrivacyPolicy::filterAt(PrivacyLevel::METADATA)->filter($this->llmStep()->toArray());

        foreach (['messagesSent', 'content', 'thinking', 'requestedToolCalls', 'raw'] as $key) {
            self::assertArrayNotHasKey($key, $filtered, sprintf('%s must not be persisted at METADATA level.', $key));
        }

        // Structural metadata survives, so the trace stays usable for cost and
        // failure analysis.
        self::assertSame('llm', $filtered['kind']);
        self::assertSame(2, $filtered['round']);
        self::assertSame(12, $filtered['promptTokens']);
        self::assertSame('stop', $filtered['finishReason']);
        self::assertTrue($filtered['contentRedacted']);

        // …augmented with sizes and names instead of the payloads themselves.
        self::assertSame(1, $filtered['messagesSentCount']);
        self::assertSame(mb_strlen('the answer is 42'), $filtered['contentLength']);
        self::assertSame(['nrllm_get_page'], $filtered['requestedToolNames']);
    }

    #[Test]
    public function metadataLevelKeepsToolIdentityButDropsArgumentsAndResult(): void
    {
        $step = new RunStep(
            kind: RunStep::KIND_TOOL,
            round: 1,
            durationMs: 4.0,
            toolName: 'nrllm_get_page',
            toolArguments: ['uid' => 17, 'secret' => 'sk-abcdefghijklmnopqrstuvwxyz'],
            toolResult: 'page title: Impressum',
            toolIsError: false,
        );

        $filtered = FixedPrivacyPolicy::filterAt(PrivacyLevel::METADATA)->filter($step->toArray());

        self::assertSame('nrllm_get_page', $filtered['toolName']);
        self::assertFalse($filtered['toolIsError']);
        self::assertArrayNotHasKey('toolArguments', $filtered);
        self::assertArrayNotHasKey('toolResult', $filtered);
        self::assertSame(mb_strlen('page title: Impressum'), $filtered['toolResultLength']);
    }

    #[Test]
    public function redactedLevelKeepsTheShapeAndMasksSecrets(): void
    {
        $step = new RunStep(
            kind: RunStep::KIND_TOOL,
            round: 1,
            durationMs: 4.0,
            toolName: 'nrllm_probe_url',
            toolArguments: ['url' => 'https://example.org/?token=supersecretvalue'],
            toolResult: 'contact sk-abcdefghijklmnopqrstuvwxyz or editor@example.org',
        );

        $filtered = FixedPrivacyPolicy::filterAt(PrivacyLevel::REDACTED)->filter($step->toArray());

        $arguments = $filtered['toolArguments'];
        self::assertIsArray($arguments);
        $url = $arguments['url'];
        self::assertIsString($url);
        self::assertStringNotContainsString('supersecretvalue', $url);

        $toolResult = $filtered['toolResult'];
        self::assertIsString($toolResult);
        self::assertStringNotContainsString('sk-abcdefghijklmnopqrstuvwxyz', $toolResult);
        self::assertStringNotContainsString('editor@example.org', $toolResult);
        self::assertArrayNotHasKey('contentRedacted', $filtered, 'REDACTED keeps the wire shape.');
    }

    #[Test]
    public function fullLevelStoresThePayloadVerbatim(): void
    {
        $payload  = $this->llmStep()->toArray();
        $filtered = FixedPrivacyPolicy::filterAt(PrivacyLevel::FULL)->filter($payload);

        self::assertSame($payload, $filtered);
    }

    #[Test]
    public function noneLevelBehavesLikeMetadata(): void
    {
        $filtered = FixedPrivacyPolicy::filterAt(PrivacyLevel::NONE)->filter($this->llmStep()->toArray());

        self::assertArrayNotHasKey('content', $filtered);
        self::assertTrue($filtered['contentRedacted']);
    }

    private function llmStep(): RunStep
    {
        return new RunStep(
            kind: RunStep::KIND_LLM,
            round: 2,
            durationMs: 123.456,
            messagesSent: [['role' => 'user', 'content' => 'what is the answer?']],
            content: 'the answer is 42',
            thinking: 'considering the question',
            finishReason: 'stop',
            promptTokens: 12,
            completionTokens: 5,
            totalTokens: 17,
            requestedToolCalls: [['id' => 'c1', 'name' => 'nrllm_get_page', 'arguments' => ['uid' => 1]]],
            raw: ['choices' => [['message' => ['content' => 'the answer is 42']]]],
        );
    }
}

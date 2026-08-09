<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service;

use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\OperationCapabilityMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The operation → capability map (ADR-138).
 *
 * Pinned case by case rather than asserted as a rule, because the map is a set
 * of individual judgements: which capability a `Model` record must declare
 * before it may serve an operation, and — for the majority of operations —
 * why nothing may be required at all. A new `ProviderOperation` case makes the
 * `match` non-exhaustive and the coverage test below fail, so neither half can
 * be added silently.
 */
#[CoversClass(OperationCapabilityMap::class)]
final class OperationCapabilityMapTest extends TestCase
{
    /**
     * @return iterable<string, array{ProviderOperation, ?ModelCapability}>
     */
    public static function operationCapabilityProvider(): iterable
    {
        // Enforced: every discoverer writes `chat`, and writes `vision` /
        // `tools` when the model has them, so a record without one of these
        // states an absence rather than leaving a gap.
        yield 'chat requires chat'     => [ProviderOperation::Chat, ModelCapability::CHAT];
        yield 'vision requires vision' => [ProviderOperation::Vision, ModelCapability::VISION];
        yield 'tools requires tools'   => [ProviderOperation::Tools, ModelCapability::TOOLS];

        // Not enforced: no discoverer writes `completion` or `embeddings`, and
        // only four of seven write `streaming`. Requiring them would refuse
        // models that work, for a fact nobody stated.
        yield 'completion requires nothing' => [ProviderOperation::Completion, null];
        yield 'embedding requires nothing'  => [ProviderOperation::Embedding, null];
        yield 'stream requires nothing'     => [ProviderOperation::Stream, null];

        // Not enforced: specialized services never reach criteria-mode
        // selection, so a mapping would be inert (the ADR-138 boundary).
        yield 'image generation requires nothing' => [ProviderOperation::ImageGeneration, null];
        yield 'image edit requires nothing'       => [ProviderOperation::ImageEdit, null];
        yield 'image variation requires nothing'  => [ProviderOperation::ImageVariation, null];
        yield 'transcription requires nothing'    => [ProviderOperation::Transcription, null];
        yield 'speech synthesis requires nothing' => [ProviderOperation::SpeechSynthesis, null];

        // Not enforced: no ModelCapability describes translation, and metadata
        // is not an AI generation at all.
        yield 'translation requires nothing' => [ProviderOperation::Translation, null];
        yield 'metadata requires nothing'    => [ProviderOperation::Metadata, null];
    }

    #[Test]
    #[DataProvider('operationCapabilityProvider')]
    public function mapsOperationToTheCapabilityItRequires(
        ProviderOperation $operation,
        ?ModelCapability $expected,
    ): void {
        self::assertSame($expected, OperationCapabilityMap::capabilityFor($operation));
    }

    #[Test]
    public function everyProviderOperationIsAccountedFor(): void
    {
        $covered = [];
        foreach (self::operationCapabilityProvider() as $case) {
            $covered[] = $case[0];
        }

        self::assertEqualsCanonicalizing(
            ProviderOperation::cases(),
            $covered,
            'A ProviderOperation case is missing from the map coverage above. '
            . 'Decide explicitly whether it requires a capability — silence is the bug this ADR fixed.',
        );
    }
}

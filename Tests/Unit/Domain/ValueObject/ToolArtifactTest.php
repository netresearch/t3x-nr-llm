<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\ArtifactType;
use Netresearch\NrLlm\Domain\ValueObject\ToolArtifact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolArtifact::class)]
final class ToolArtifactTest extends TestCase
{
    #[Test]
    public function toArrayEmitsTypeValueLabelAndData(): void
    {
        $artifact = new ToolArtifact(ArtifactType::TABLE, 'pages records', [
            'columns' => ['uid', 'title'],
            'rows'    => [['1', 'Home'], ['2', 'About']],
        ]);

        self::assertSame(
            [
                'type'  => 'table',
                'label' => 'pages records',
                'data'  => [
                    'columns' => ['uid', 'title'],
                    'rows'    => [['1', 'Home'], ['2', 'About']],
                ],
            ],
            $artifact->toArray(),
        );
    }

    #[Test]
    public function toArrayMapsTheTextTypeToItsEnumValue(): void
    {
        $artifact = new ToolArtifact(ArtifactType::TEXT, 'Artifacts omitted', ['text' => 'too big']);

        $array = $artifact->toArray();

        self::assertSame('text', $array['type']);
        self::assertSame('Artifacts omitted', $array['label']);
        self::assertSame(['text' => 'too big'], $array['data']);
    }
}

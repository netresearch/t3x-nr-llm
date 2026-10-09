<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Domain;

use Eris\Generators;
use InvalidArgumentException;
use Netresearch\NrLlm\Domain\ValueObject\ToolInvocationDecision;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * The metadata-code bounds refuse truncation and arbitrary payload text.
 */
#[CoversNothing]
final class ToolInvocationCodesFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function reasonCodesAreNonemptyAndAtMostSixtyFourAsciiCharacters(): void
    {
        $this->forAll(Generators::choose(0, 128))
            ->then(
                function (int $length): void {
                    $code = str_repeat('a', $length);
                    if ($length >= 1 && $length <= 64) {
                        $decision = ToolInvocationDecision::deny($code)->forRule($code);
                        self::assertSame($code, $decision->reason);
                        self::assertSame($code, $decision->ruleIdentifier);
                        return;
                    }
                    try {
                        ToolInvocationDecision::deny($code);
                        self::fail('Out-of-bounds code accepted.');
                    } catch (InvalidArgumentException) {
                        self::addToAssertionCount(1);
                    }
                },
            );
    }
}

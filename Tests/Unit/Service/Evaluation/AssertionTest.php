<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Domain\Enum\AssertionType;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Evaluation\Assertion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Assertion::class)]
final class AssertionTest extends TestCase
{
    #[Test]
    public function factoriesSetTheMatchingType(): void
    {
        self::assertSame(AssertionType::EXACT, Assertion::exact('a')->type);
        self::assertSame(AssertionType::CONTAINS, Assertion::contains('a')->type);
        self::assertSame(AssertionType::REGEX, Assertion::regex('/a/')->type);
        self::assertSame(AssertionType::JSON_SCHEMA, Assertion::jsonSchema('{"type":"object"}')->type);
    }

    #[Test]
    public function emptyValueIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000001);
        Assertion::contains('');
    }

    #[Test]
    public function invalidRegexIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1794000002);
        Assertion::regex('/(unclosed');
    }

    #[Test]
    public function validRegexIsAccepted(): void
    {
        $assertion = Assertion::regex('/\d+/');
        self::assertSame('/\d+/', $assertion->value);
    }
}

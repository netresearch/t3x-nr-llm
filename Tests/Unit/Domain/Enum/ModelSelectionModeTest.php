<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\ModelSelectionMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModelSelectionMode::class)]
final class ModelSelectionModeTest extends TestCase
{
    #[Test]
    public function valuesReturnsAllModeValues(): void
    {
        $values = ModelSelectionMode::values();

        self::assertCount(2, $values);
        self::assertContains('fixed', $values);
        self::assertContains('criteria', $values);
    }

    #[Test]
    public function isValidReturnsTrueForValidValues(): void
    {
        self::assertTrue(ModelSelectionMode::isValid('fixed'));
        self::assertTrue(ModelSelectionMode::isValid('criteria'));
    }

    #[Test]
    public function isValidReturnsFalseForInvalidValues(): void
    {
        self::assertFalse(ModelSelectionMode::isValid('invalid'));
        self::assertFalse(ModelSelectionMode::isValid(''));
        self::assertFalse(ModelSelectionMode::isValid('FIXED'));
    }

    #[Test]
    public function tryFromStringReturnsEnumForValidValues(): void
    {
        $fixed = ModelSelectionMode::tryFromString('fixed');
        $criteria = ModelSelectionMode::tryFromString('criteria');

        self::assertSame(ModelSelectionMode::FIXED, $fixed);
        self::assertSame(ModelSelectionMode::CRITERIA, $criteria);
    }

    #[Test]
    public function tryFromStringReturnsNullForInvalidValues(): void
    {
        self::assertNull(ModelSelectionMode::tryFromString('invalid'));
        self::assertNull(ModelSelectionMode::tryFromString(''));
    }

    #[Test]
    public function getDescriptionReturnsCorrectDescriptions(): void
    {
        $fixedDescription = ModelSelectionMode::FIXED->getDescription();
        $criteriaDescription = ModelSelectionMode::CRITERIA->getDescription();

        self::assertStringContainsString('specific', $fixedDescription);
        self::assertStringContainsString('pre-configured', $fixedDescription);
        self::assertStringContainsString('capabilities', $criteriaDescription);
        self::assertStringContainsString('requirements', $criteriaDescription);
    }

    #[Test]
    public function enumCasesHaveCorrectValues(): void
    {
        self::assertSame('fixed', ModelSelectionMode::FIXED->value);
        self::assertSame('criteria', ModelSelectionMode::CRITERIA->value);
    }
}

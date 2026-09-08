<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\ModelIdentifier;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModelIdentifier::class)]
final class ModelIdentifierTest extends TestCase
{
    #[Test]
    public function carriesTheRecordIdentifier(): void
    {
        $identifier = new ModelIdentifier('gpt-image-2-a3f7c2');

        self::assertSame('gpt-image-2-a3f7c2', $identifier->value);
        self::assertSame('gpt-image-2-a3f7c2', (string)$identifier);
    }

    #[Test]
    #[DataProvider('blankValues')]
    public function refusesABlankIdentifier(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $identifier = new ModelIdentifier($value);

        self::fail('An identifier was built as "' . $identifier . '", which names no model record.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankValues(): iterable
    {
        yield 'empty'      => [''];
        yield 'space'      => [' '];
        yield 'whitespace' => ["\t\n "];
    }

    #[Test]
    #[DataProvider('paddedValues')]
    public function normalizesSurroundingWhitespace(string $value): void
    {
        $identifier = new ModelIdentifier($value);

        self::assertSame('gpt-image-2-a3f7c2', $identifier->value);
        self::assertSame('gpt-image-2-a3f7c2', (string)$identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paddedValues(): iterable
    {
        yield 'trailing space' => ['gpt-image-2-a3f7c2 '];
        yield 'leading space'  => [' gpt-image-2-a3f7c2'];
        yield 'both'           => ["  gpt-image-2-a3f7c2\t"];
        yield 'newline'        => ["gpt-image-2-a3f7c2\n"];
    }

    #[Test]
    public function aPaddedIdentifierEqualsItsCleanForm(): void
    {
        self::assertTrue(
            (new ModelIdentifier(' gpt-image-2-a3f7c2 '))
                ->equals(new ModelIdentifier('gpt-image-2-a3f7c2')),
        );
    }

    #[Test]
    public function twoValuesAreEqualOnlyWhenTheyNameTheSameThing(): void
    {
        $row = new ModelIdentifier('gpt-image-2-a3f7c2');

        self::assertTrue($row->equals(new ModelIdentifier('gpt-image-2-a3f7c2')));
        self::assertFalse($row->equals(new ModelIdentifier('gpt-image-2-7f31a2')));
    }

    /**
     * The row identifier is not the provider-side model name it starts with.
     *
     * On a wizard-created row those differ by construction: `generateIdentifier()`
     * appends a six-character random suffix, so `gpt-image-2-a3f7c2` names the
     * row and `gpt-image-2` names the model at the provider. #932 was the cost
     * calculator confusing exactly these two.
     *
     * @see IdentifierSeparationTest for the type-level half of the property
     */
    #[Test]
    public function theRowIdentifierIsNotTheProviderSideModelName(): void
    {
        $row = new ModelIdentifier('gpt-image-2-a3f7c2');

        self::assertNotSame('gpt-image-2', $row->value);
        self::assertFalse($row->equals(new ModelIdentifier('gpt-image-2')));
    }
}

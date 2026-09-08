<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\ProviderModelName;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderModelName::class)]
final class ProviderModelNameTest extends TestCase
{
    #[Test]
    public function carriesTheModelName(): void
    {
        $name = new ProviderModelName('gpt-image-2');

        self::assertSame('gpt-image-2', $name->value);
        self::assertSame('gpt-image-2', (string)$name);
    }

    #[Test]
    #[DataProvider('blankValues')]
    public function refusesABlankName(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $name = new ProviderModelName($value);

        self::fail('A name was built as "' . $name . '", which names no model at any provider.');
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
        $name = new ProviderModelName($value);

        self::assertSame('gpt-image-2', $name->value);
        self::assertSame('gpt-image-2', (string)$name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paddedValues(): iterable
    {
        yield 'trailing space' => ['gpt-image-2 '];
        yield 'leading space'  => [' gpt-image-2'];
        yield 'both'           => ["  gpt-image-2\t"];
        yield 'newline'        => ["gpt-image-2\n"];
    }

    #[Test]
    public function aPaddedNameEqualsItsCleanForm(): void
    {
        self::assertTrue(
            (new ProviderModelName(' gpt-image-2 '))
                ->equals(new ProviderModelName('gpt-image-2')),
        );
    }

    #[Test]
    public function twoValuesAreEqualOnlyWhenTheyNameTheSameThing(): void
    {
        $image = new ProviderModelName('gpt-image-2');

        self::assertTrue($image->equals(new ProviderModelName('gpt-image-2')));
        self::assertFalse($image->equals(new ProviderModelName('dall-e-3')));
    }

    /**
     * A provider-side model name is not unique, and this type does not pretend
     * otherwise.
     *
     * The same API model offered through two providers is two rows carrying
     * this same value; `model_id` has no `unique` eval. Two names that read
     * alike are equal here because they ARE the same name -- which row a
     * lookup then returns is a question for the repository, not for this type
     * (#935).
     *
     * @see IdentifierSeparationTest for the type-level half of the property
     */
    #[Test]
    public function theSameNameIsEqualEvenWhenTwoProvidersOfferIt(): void
    {
        self::assertTrue(
            (new ProviderModelName('gpt-image-2'))->equals(new ProviderModelName('gpt-image-2')),
        );
    }
}

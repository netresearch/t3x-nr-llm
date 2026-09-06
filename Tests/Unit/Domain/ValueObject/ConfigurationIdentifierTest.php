<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\ConfigurationIdentifier;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * @see IdentifierSeparationTest for the cross-type property #893 asks for
 */
#[CoversClass(ConfigurationIdentifier::class)]
final class ConfigurationIdentifierTest extends TestCase
{
    #[Test]
    public function carriesTheConfigurationName(): void
    {
        $identifier = new ConfigurationIdentifier('blog-summarizer');

        self::assertSame('blog-summarizer', $identifier->value);
        self::assertSame('blog-summarizer', (string)$identifier);
    }

    #[Test]
    #[DataProvider('blankValues')]
    public function refusesABlankIdentifier(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $identifier = new ConfigurationIdentifier($value);

        self::fail('An identifier was built as "' . $identifier . '", which names no configuration.');
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
        $identifier = new ConfigurationIdentifier($value);

        self::assertSame('blog-summarizer', $identifier->value);
        self::assertSame('blog-summarizer', (string)$identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paddedValues(): iterable
    {
        yield 'trailing space' => ['blog-summarizer '];
        yield 'leading space'  => [' blog-summarizer'];
        yield 'both'           => ["  blog-summarizer\t"];
        yield 'newline'        => ["blog-summarizer\n"];
    }

    #[Test]
    public function aPaddedIdentifierEqualsItsCleanForm(): void
    {
        self::assertTrue(
            (new ConfigurationIdentifier(' blog-summarizer '))
                ->equals(new ConfigurationIdentifier('blog-summarizer')),
        );
    }

    #[Test]
    public function twoIdentifiersAreEqualOnlyWhenTheyNameTheSameConfiguration(): void
    {
        $summarizer = new ConfigurationIdentifier('blog-summarizer');

        self::assertTrue($summarizer->equals(new ConfigurationIdentifier('blog-summarizer')));
        self::assertFalse($summarizer->equals(new ConfigurationIdentifier('support-agent')));
    }
}

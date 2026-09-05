<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\ConfigurationIdentifier;
use Netresearch\NrLlm\Domain\ValueObject\ProviderAdapterKey;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stringable;

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

    /**
     * The property #893 asks for, in the only form that can actually fail.
     *
     * Passing a {@see ProviderAdapterKey} where a configuration identifier is
     * required is a `TypeError` -- asserting that would test PHP, not this
     * code. What can be undone by a later edit is the SEPARATION: extract a
     * shared abstract base or a common `IdentifierInterface` to spare the
     * duplicated `trim()`, and every parameter typed against that base accepts
     * both again, silently, exactly as `string` did before #893. So the durable
     * guard is that the two types have no common ancestor and share nothing but
     * `Stringable`, which carries no identity of its own.
     */
    #[Test]
    public function anIdentifierFromAnotherNamespaceIsNotSubstitutable(): void
    {
        $configuration = new ReflectionClass(ConfigurationIdentifier::class);
        $adapter       = new ReflectionClass(ProviderAdapterKey::class);

        self::assertFalse($configuration->getParentClass(), 'A shared base class makes both identifiers assignable to it again.');
        self::assertFalse($adapter->getParentClass(), 'A shared base class makes both identifiers assignable to it again.');
        self::assertSame([Stringable::class], array_values($configuration->getInterfaceNames()));
        self::assertSame([Stringable::class], array_values($adapter->getInterfaceNames()));
    }
}

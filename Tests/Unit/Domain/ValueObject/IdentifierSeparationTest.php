<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\ConfigurationIdentifier;
use Netresearch\NrLlm\Domain\ValueObject\ProviderAdapterKey;
use Netresearch\NrLlm\Domain\ValueObject\ProviderIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Stringable;

/**
 * The property #893 asks for, in the only form that can actually fail.
 *
 * Passing a {@see ProviderAdapterKey} where a {@see ProviderIdentifier} is
 * required is a `TypeError` -- asserting that would test PHP, not this code.
 * What a later edit can undo is the SEPARATION: extract a shared abstract base
 * or a common `IdentifierInterface` to spare the duplicated `trim()`, and every
 * parameter typed against that base accepts all of them again, silently,
 * exactly as `string` did before #893.
 *
 * So the durable guard is that no two identifier types share an ancestor, and
 * that each carries nothing but `Stringable`, which has no identity of its own.
 * A new identifier type joins {@see self::identifierTypes()} and inherits the
 * whole property.
 */
#[CoversClass(ConfigurationIdentifier::class)]
#[CoversClass(ProviderAdapterKey::class)]
#[CoversClass(ProviderIdentifier::class)]
final class IdentifierSeparationTest extends TestCase
{
    /**
     * Every identifier value object that must stay unsubstitutable for the others.
     *
     * @return iterable<string, array{class-string}>
     */
    public static function identifierTypes(): iterable
    {
        yield 'configuration' => [ConfigurationIdentifier::class];
        yield 'adapter key'   => [ProviderAdapterKey::class];
        yield 'provider'      => [ProviderIdentifier::class];
    }

    /**
     * @return iterable<string, array{class-string, class-string}>
     */
    public static function identifierPairs(): iterable
    {
        $types = array_map(static fn(array $row): string => $row[0], iterator_to_array(self::identifierTypes()));
        foreach ($types as $left) {
            foreach ($types as $right) {
                if ($left !== $right) {
                    yield $left . ' vs ' . $right => [$left, $right];
                }
            }
        }
    }

    /**
     * @param class-string $left
     * @param class-string $right
     */
    #[Test]
    #[DataProvider('identifierPairs')]
    public function noTwoIdentifiersShareAnAncestor(string $left, string $right): void
    {
        $leftAncestors  = $this->ancestorsOf($left);
        $rightAncestors = $this->ancestorsOf($right);

        self::assertSame(
            [Stringable::class],
            array_values(array_intersect($leftAncestors, $rightAncestors)),
            sprintf(
                '%s and %s share more than Stringable, so a parameter typed against the shared type accepts both.',
                $left,
                $right,
            ),
        );
    }

    /**
     * @param class-string $type
     */
    #[Test]
    #[DataProvider('identifierTypes')]
    public function everyIdentifierIsFinalReadonlyAndStandsAlone(string $type): void
    {
        $reflection = new ReflectionClass($type);

        self::assertTrue($reflection->isFinal(), $type . ' must not be extendable.');
        self::assertTrue($reflection->isReadOnly(), $type . ' must not be mutable after construction.');
        self::assertFalse($reflection->getParentClass(), 'A shared base class makes the identifiers assignable to it again.');
        self::assertSame([Stringable::class], array_values($reflection->getInterfaceNames()));
    }

    /**
     * Everything a value of this type is assignable to: the class itself, its
     * parents and every interface, transitively.
     *
     * @param class-string $type
     *
     * @return list<string>
     */
    private function ancestorsOf(string $type): array
    {
        $names = [$type, ...array_values(class_parents($type) ?: []), ...array_values(class_implements($type) ?: [])];
        sort($names);

        return $names;
    }
}

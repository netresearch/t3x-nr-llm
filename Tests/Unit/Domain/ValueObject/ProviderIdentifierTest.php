<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\ProviderIdentifier;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderIdentifier::class)]
final class ProviderIdentifierTest extends TestCase
{
    #[Test]
    public function carriesTheRecordIdentifier(): void
    {
        $identifier = new ProviderIdentifier('openai-dcbd8f');

        self::assertSame('openai-dcbd8f', $identifier->value);
        self::assertSame('openai-dcbd8f', (string)$identifier);
    }

    #[Test]
    #[DataProvider('blankValues')]
    public function refusesABlankIdentifier(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $identifier = new ProviderIdentifier($value);

        self::fail('An identifier was built as "' . $identifier . '", which names no provider record.');
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
        $identifier = new ProviderIdentifier($value);

        self::assertSame('openai-dcbd8f', $identifier->value);
        self::assertSame('openai-dcbd8f', (string)$identifier);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paddedValues(): iterable
    {
        yield 'trailing space' => ['openai-dcbd8f '];
        yield 'leading space'  => [' openai-dcbd8f'];
        yield 'both'           => ["  openai-dcbd8f\t"];
        yield 'newline'        => ["openai-dcbd8f\n"];
    }

    #[Test]
    public function aPaddedIdentifierEqualsItsCleanForm(): void
    {
        self::assertTrue(
            (new ProviderIdentifier(' openai-dcbd8f '))
                ->equals(new ProviderIdentifier('openai-dcbd8f')),
        );
    }

    #[Test]
    public function twoIdentifiersAreEqualOnlyWhenTheyNameTheSameRecord(): void
    {
        $openAi = new ProviderIdentifier('openai-dcbd8f');

        self::assertTrue($openAi->equals(new ProviderIdentifier('openai-dcbd8f')));
        self::assertFalse($openAi->equals(new ProviderIdentifier('openai-7f31a2')));
    }

    /**
     * The two identifiers #893 exists to keep apart, spelled out.
     *
     * A record identifier and the adapter key it starts with are different
     * values on any installation set up through the wizard: `openai-dcbd8f`
     * names the row, `openai` names the adapter that serves it. Neither
     * `equals()` nor anything else may treat the prefix as the same value.
     *
     * @see IdentifierSeparationTest for the type-level half of the property
     */
    #[Test]
    public function theRecordIdentifierIsNotTheAdapterKeyItStartsWith(): void
    {
        $record = new ProviderIdentifier('openai-dcbd8f');

        self::assertNotSame('openai', $record->value);
        self::assertFalse($record->equals(new ProviderIdentifier('openai')));
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\ProviderAdapterKey;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProviderAdapterKey::class)]
final class ProviderAdapterKeyTest extends TestCase
{
    #[Test]
    public function carriesTheAdapterName(): void
    {
        $key = new ProviderAdapterKey('openai');

        self::assertSame('openai', $key->value);
        self::assertSame('openai', (string)$key);
    }

    #[Test]
    #[DataProvider('blankValues')]
    public function refusesABlankKey(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $key = new ProviderAdapterKey($value);

        self::fail('A key was built as "' . $key . '", which names no adapter.');
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

    /**
     * The distinction the type exists for (#873). The adapter key and a
     * `tx_nrllm_provider` row identifier are both non-empty strings and both
     * come from a method called `getIdentifier()`; only one of them is what the
     * registry is keyed by.
     *
     * Written with the two values SPELLED DIFFERENTLY on purpose: an
     * installation configured by hand has them equal, and a test using one
     * value for both would pass while the code confused them.
     */
    #[Test]
    public function twoKeysAreEqualOnlyWhenTheyNameTheSameAdapter(): void
    {
        $adapter = new ProviderAdapterKey('openai');
        $row     = new ProviderAdapterKey('openai-dcbd8f');

        self::assertTrue($adapter->equals(new ProviderAdapterKey('openai')));
        self::assertFalse($adapter->equals($row));
    }
}

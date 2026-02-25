<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

use ArrayIterator;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Translation\TranslatorInterface;
use Netresearch\NrLlm\Specialized\Translation\TranslatorRegistry;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(TranslatorRegistry::class)]
class TranslatorRegistryTest extends AbstractUnitTestCase
{
    #[Test]
    public function getReturnsRegisteredTranslator(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('deepl');
        $translator->method('getName')->willReturn('DeepL');
        $translator->method('isAvailable')->willReturn(true);

        $registry = new TranslatorRegistry(
            new ArrayIterator([$translator]),
        );

        $result = $registry->get('deepl');

        self::assertSame($translator, $result);
    }

    #[Test]
    public function getThrowsWhenNotFound(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('llm');

        $registry = new TranslatorRegistry(
            new ArrayIterator([$translator]),
        );

        $this->expectException(ServiceUnavailableException::class);

        $registry->get('nonexistent');
    }

    #[Test]
    public function getThrowsWhenNotAvailable(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('deepl');
        $translator->method('isAvailable')->willReturn(false);

        $registry = new TranslatorRegistry(
            new ArrayIterator([$translator]),
        );

        $this->expectException(ServiceUnavailableException::class);

        $registry->get('deepl');
    }

    #[Test]
    public function hasReturnsTrueForAvailableTranslator(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('deepl');
        $translator->method('isAvailable')->willReturn(true);

        $registry = new TranslatorRegistry(
            new ArrayIterator([$translator]),
        );

        self::assertTrue($registry->has('deepl'));
    }

    #[Test]
    public function hasReturnsFalseForUnavailableTranslator(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('deepl');
        $translator->method('isAvailable')->willReturn(false);

        $registry = new TranslatorRegistry(
            new ArrayIterator([$translator]),
        );

        self::assertFalse($registry->has('deepl'));
    }

    #[Test]
    public function hasReturnsFalseForNonexistent(): void
    {
        $registry = new TranslatorRegistry(new ArrayIterator([]));

        self::assertFalse($registry->has('nonexistent'));
    }

    #[Test]
    public function getAvailableReturnsOnlyAvailable(): void
    {
        $available = self::createStub(TranslatorInterface::class);
        $available->method('getIdentifier')->willReturn('available');
        $available->method('isAvailable')->willReturn(true);

        $unavailable = self::createStub(TranslatorInterface::class);
        $unavailable->method('getIdentifier')->willReturn('unavailable');
        $unavailable->method('isAvailable')->willReturn(false);

        $registry = new TranslatorRegistry(
            new ArrayIterator([$available, $unavailable]),
        );

        $result = $registry->getAvailable();

        self::assertCount(1, $result);
        self::assertArrayHasKey('available', $result);
        self::assertArrayNotHasKey('unavailable', $result);
    }

    #[Test]
    public function getRegisteredIdentifiersReturnsAllIdentifiers(): void
    {
        $t1 = self::createStub(TranslatorInterface::class);
        $t1->method('getIdentifier')->willReturn('deepl');

        $t2 = self::createStub(TranslatorInterface::class);
        $t2->method('getIdentifier')->willReturn('llm');

        $registry = new TranslatorRegistry(
            new ArrayIterator([$t1, $t2]),
        );

        $result = $registry->getRegisteredIdentifiers();

        self::assertCount(2, $result);
        self::assertContains('deepl', $result);
        self::assertContains('llm', $result);
    }

    #[Test]
    public function getTranslatorInfoReturnsInfoArray(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('deepl');
        $translator->method('getName')->willReturn('DeepL Translation');
        $translator->method('isAvailable')->willReturn(true);

        $registry = new TranslatorRegistry(
            new ArrayIterator([$translator]),
        );

        $result = $registry->getTranslatorInfo();

        self::assertArrayHasKey('deepl', $result);
        self::assertEquals('deepl', $result['deepl']['identifier']);
        self::assertEquals('DeepL Translation', $result['deepl']['name']);
        self::assertTrue($result['deepl']['available']);
    }

    #[Test]
    public function findBestTranslatorReturnsFirstSupportingLanguagePair(): void
    {
        $notSupporting = self::createStub(TranslatorInterface::class);
        $notSupporting->method('getIdentifier')->willReturn('first');
        $notSupporting->method('isAvailable')->willReturn(true);
        $notSupporting->method('supportsLanguagePair')->willReturn(false);

        $supporting = self::createStub(TranslatorInterface::class);
        $supporting->method('getIdentifier')->willReturn('second');
        $supporting->method('isAvailable')->willReturn(true);
        $supporting->method('supportsLanguagePair')->willReturn(true);

        $registry = new TranslatorRegistry(
            new ArrayIterator([$notSupporting, $supporting]),
        );

        $result = $registry->findBestTranslator('en', 'de');

        self::assertSame($supporting, $result);
    }

    #[Test]
    public function findBestTranslatorReturnsNullWhenNoneSupports(): void
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('translator');
        $translator->method('isAvailable')->willReturn(true);
        $translator->method('supportsLanguagePair')->willReturn(false);

        $registry = new TranslatorRegistry(
            new ArrayIterator([$translator]),
        );

        $result = $registry->findBestTranslator('xx', 'yy');

        self::assertNull($result);
    }

    #[Test]
    public function findBestTranslatorSkipsUnavailable(): void
    {
        $unavailable = self::createStub(TranslatorInterface::class);
        $unavailable->method('getIdentifier')->willReturn('unavailable');
        $unavailable->method('isAvailable')->willReturn(false);
        $unavailable->method('supportsLanguagePair')->willReturn(true);

        $available = self::createStub(TranslatorInterface::class);
        $available->method('getIdentifier')->willReturn('available');
        $available->method('isAvailable')->willReturn(true);
        $available->method('supportsLanguagePair')->willReturn(true);

        $registry = new TranslatorRegistry(
            new ArrayIterator([$unavailable, $available]),
        );

        $result = $registry->findBestTranslator('en', 'de');

        self::assertSame($available, $result);
    }
}

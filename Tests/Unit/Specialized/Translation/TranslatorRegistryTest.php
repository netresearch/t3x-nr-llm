<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Translation;

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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('deepl');
        $translator->method('getName')->willReturn('DeepL');
        $translator->method('isAvailable')->willReturn(true);

        $registry = new TranslatorRegistry(
            new \ArrayIterator([$translator])
        );

        $result = $registry->get('deepl');

        $this->assertSame($translator, $result);
    }

    #[Test]
    public function getThrowsWhenNotFound(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('llm');

        $registry = new TranslatorRegistry(
            new \ArrayIterator([$translator])
        );

        $this->expectException(ServiceUnavailableException::class);

        $registry->get('nonexistent');
    }

    #[Test]
    public function getThrowsWhenNotAvailable(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('deepl');
        $translator->method('isAvailable')->willReturn(false);

        $registry = new TranslatorRegistry(
            new \ArrayIterator([$translator])
        );

        $this->expectException(ServiceUnavailableException::class);

        $registry->get('deepl');
    }

    #[Test]
    public function hasReturnsTrueForAvailableTranslator(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('deepl');
        $translator->method('isAvailable')->willReturn(true);

        $registry = new TranslatorRegistry(
            new \ArrayIterator([$translator])
        );

        $this->assertTrue($registry->has('deepl'));
    }

    #[Test]
    public function hasReturnsFalseForUnavailableTranslator(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('deepl');
        $translator->method('isAvailable')->willReturn(false);

        $registry = new TranslatorRegistry(
            new \ArrayIterator([$translator])
        );

        $this->assertFalse($registry->has('deepl'));
    }

    #[Test]
    public function hasReturnsFalseForNonexistent(): void
    {
        $registry = new TranslatorRegistry(new \ArrayIterator([]));

        $this->assertFalse($registry->has('nonexistent'));
    }

    #[Test]
    public function getAvailableReturnsOnlyAvailable(): void
    {
        $available = $this->createStub(TranslatorInterface::class);
        $available->method('getIdentifier')->willReturn('available');
        $available->method('isAvailable')->willReturn(true);

        $unavailable = $this->createStub(TranslatorInterface::class);
        $unavailable->method('getIdentifier')->willReturn('unavailable');
        $unavailable->method('isAvailable')->willReturn(false);

        $registry = new TranslatorRegistry(
            new \ArrayIterator([$available, $unavailable])
        );

        $result = $registry->getAvailable();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('available', $result);
        $this->assertArrayNotHasKey('unavailable', $result);
    }

    #[Test]
    public function getRegisteredIdentifiersReturnsAllIdentifiers(): void
    {
        $t1 = $this->createStub(TranslatorInterface::class);
        $t1->method('getIdentifier')->willReturn('deepl');

        $t2 = $this->createStub(TranslatorInterface::class);
        $t2->method('getIdentifier')->willReturn('llm');

        $registry = new TranslatorRegistry(
            new \ArrayIterator([$t1, $t2])
        );

        $result = $registry->getRegisteredIdentifiers();

        $this->assertCount(2, $result);
        $this->assertContains('deepl', $result);
        $this->assertContains('llm', $result);
    }

    #[Test]
    public function getTranslatorInfoReturnsInfoArray(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('deepl');
        $translator->method('getName')->willReturn('DeepL Translation');
        $translator->method('isAvailable')->willReturn(true);

        $registry = new TranslatorRegistry(
            new \ArrayIterator([$translator])
        );

        $result = $registry->getTranslatorInfo();

        $this->assertArrayHasKey('deepl', $result);
        $this->assertEquals('deepl', $result['deepl']['identifier']);
        $this->assertEquals('DeepL Translation', $result['deepl']['name']);
        $this->assertTrue($result['deepl']['available']);
    }

    #[Test]
    public function findBestTranslatorReturnsFirstSupportingLanguagePair(): void
    {
        $notSupporting = $this->createStub(TranslatorInterface::class);
        $notSupporting->method('getIdentifier')->willReturn('first');
        $notSupporting->method('isAvailable')->willReturn(true);
        $notSupporting->method('supportsLanguagePair')->willReturn(false);

        $supporting = $this->createStub(TranslatorInterface::class);
        $supporting->method('getIdentifier')->willReturn('second');
        $supporting->method('isAvailable')->willReturn(true);
        $supporting->method('supportsLanguagePair')->willReturn(true);

        $registry = new TranslatorRegistry(
            new \ArrayIterator([$notSupporting, $supporting])
        );

        $result = $registry->findBestTranslator('en', 'de');

        $this->assertSame($supporting, $result);
    }

    #[Test]
    public function findBestTranslatorReturnsNullWhenNoneSupports(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getIdentifier')->willReturn('translator');
        $translator->method('isAvailable')->willReturn(true);
        $translator->method('supportsLanguagePair')->willReturn(false);

        $registry = new TranslatorRegistry(
            new \ArrayIterator([$translator])
        );

        $result = $registry->findBestTranslator('xx', 'yy');

        $this->assertNull($result);
    }

    #[Test]
    public function findBestTranslatorSkipsUnavailable(): void
    {
        $unavailable = $this->createStub(TranslatorInterface::class);
        $unavailable->method('getIdentifier')->willReturn('unavailable');
        $unavailable->method('isAvailable')->willReturn(false);
        $unavailable->method('supportsLanguagePair')->willReturn(true);

        $available = $this->createStub(TranslatorInterface::class);
        $available->method('getIdentifier')->willReturn('available');
        $available->method('isAvailable')->willReturn(true);
        $available->method('supportsLanguagePair')->willReturn(true);

        $registry = new TranslatorRegistry(
            new \ArrayIterator([$unavailable, $available])
        );

        $result = $registry->findBestTranslator('en', 'de');

        $this->assertSame($available, $result);
    }
}

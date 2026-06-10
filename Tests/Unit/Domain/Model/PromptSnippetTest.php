<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for PromptSnippet domain entity.
 *
 * Note: Domain models are excluded from coverage in phpunit.xml.
 */
#[CoversNothing]
final class PromptSnippetTest extends AbstractUnitTestCase
{
    private PromptSnippet $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new PromptSnippet();
    }

    // ========================================
    // Basic getter / setter tests
    // ========================================

    #[Test]
    public function identifierGetterAndSetter(): void
    {
        $this->subject->setIdentifier('persona-friendly-expert');
        self::assertSame('persona-friendly-expert', $this->subject->getIdentifier());
    }

    #[Test]
    public function nameGetterAndSetter(): void
    {
        $this->subject->setName('Friendly Expert');
        self::assertSame('Friendly Expert', $this->subject->getName());
    }

    #[Test]
    public function descriptionGetterAndSetter(): void
    {
        $this->subject->setDescription('A friendly, approachable expert persona');
        self::assertSame('A friendly, approachable expert persona', $this->subject->getDescription());
    }

    #[Test]
    public function tagsGetterAndSetter(): void
    {
        $this->subject->setTags('persona,tone_of_voice');
        self::assertSame('persona,tone_of_voice', $this->subject->getTags());
    }

    #[Test]
    public function snippetGetterAndSetter(): void
    {
        $this->subject->setSnippet('You are a friendly expert.');
        self::assertSame('You are a friendly expert.', $this->subject->getSnippet());
    }

    #[Test]
    public function metadataGetterAndSetter(): void
    {
        $this->subject->setMetadata('{"voice":"nova"}');
        self::assertSame('{"voice":"nova"}', $this->subject->getMetadata());
    }

    #[Test]
    public function isActiveGetterAndSetter(): void
    {
        $this->subject->setIsActive(false);
        self::assertFalse($this->subject->isActive());
        self::assertFalse($this->subject->getIsActive());

        $this->subject->setIsActive(true);
        self::assertTrue($this->subject->isActive());
        self::assertTrue($this->subject->getIsActive());
    }

    #[Test]
    public function sortingGetterAndSetter(): void
    {
        $this->subject->setSorting(42);
        self::assertSame(42, $this->subject->getSorting());
    }

    #[Test]
    public function defaultsAreEmptyAndActive(): void
    {
        self::assertSame('', $this->subject->getIdentifier());
        self::assertSame('', $this->subject->getName());
        self::assertSame('', $this->subject->getDescription());
        self::assertSame('', $this->subject->getTags());
        self::assertSame('', $this->subject->getSnippet());
        self::assertSame('', $this->subject->getMetadata());
        self::assertTrue($this->subject->isActive());
        self::assertSame(0, $this->subject->getSorting());
    }

    // ========================================
    // getTagList()
    // ========================================

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function tagListProvider(): array
    {
        return [
            'empty string' => ['', []],
            'whitespace only' => ['   ', []],
            'separators only' => [' , ,, ', []],
            'single tag' => ['audience', ['audience']],
            'multiple tags' => ['audience,tone_of_voice', ['audience', 'tone_of_voice']],
            'tags are trimmed' => [' audience , tone_of_voice ', ['audience', 'tone_of_voice']],
            'tags are lowercased' => ['Audience,TONE_OF_VOICE', ['audience', 'tone_of_voice']],
            'empty entries dropped' => ['audience,,style,', ['audience', 'style']],
            'mixed normalization' => [' Persona ,, Layout , STYLE ', ['persona', 'layout', 'style']],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('tagListProvider')]
    public function getTagListNormalizesCsvTags(string $tags, array $expected): void
    {
        $this->subject->setTags($tags);

        self::assertSame($expected, $this->subject->getTagList());
    }

    // ========================================
    // getMetadataArray()
    // ========================================

    #[Test]
    public function getMetadataArrayDecodesJsonObject(): void
    {
        $this->subject->setMetadata('{"voice":"nova","temperature":0.5}');

        self::assertSame(
            ['voice' => 'nova', 'temperature' => 0.5],
            $this->subject->getMetadataArray(),
        );
    }

    #[Test]
    public function getMetadataArrayDecodesNestedJsonObject(): void
    {
        $this->subject->setMetadata('{"image":{"style":"watercolor","size":"1024x1024"}}');

        self::assertSame(
            ['image' => ['style' => 'watercolor', 'size' => '1024x1024']],
            $this->subject->getMetadataArray(),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidMetadataProvider(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ["  \n "],
            'invalid json' => ['{not json'],
            'truncated json' => ['{"voice":'],
            'json string scalar' => ['"nova"'],
            'json number scalar' => ['42'],
            'json boolean scalar' => ['true'],
            'json null' => ['null'],
            'json list' => ['["audience","style"]'],
            'empty json object' => ['{}'],
        ];
    }

    #[Test]
    #[DataProvider('invalidMetadataProvider')]
    public function getMetadataArrayReturnsEmptyArrayWithoutThrowing(string $metadata): void
    {
        $this->subject->setMetadata($metadata);

        self::assertSame([], $this->subject->getMetadataArray());
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use Netresearch\NrLlm\Domain\Enum\TaskCategory;
use Netresearch\NrLlm\Domain\Enum\TaskInputType;
use Netresearch\NrLlm\Domain\Enum\TaskOutputFormat;
use Netresearch\NrLlm\Domain\Model\Task;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit tests for Task domain entity.
 *
 * Note: Domain models are excluded from coverage in phpunit.xml.
 */
#[CoversNothing]
final class TaskTest extends AbstractUnitTestCase
{
    private Task $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new Task();
    }

    // ========================================
    // Basic getter / setter tests
    // ========================================

    #[Test]
    public function identifierGetterAndSetter(): void
    {
        $this->subject->setIdentifier('analyze-logs');
        self::assertSame('analyze-logs', $this->subject->getIdentifier());
    }

    #[Test]
    public function nameGetterAndSetter(): void
    {
        $this->subject->setName('Analyze System Logs');
        self::assertSame('Analyze System Logs', $this->subject->getName());
    }

    #[Test]
    public function descriptionGetterAndSetter(): void
    {
        $this->subject->setDescription('Analyzes TYPO3 system logs');
        self::assertSame('Analyzes TYPO3 system logs', $this->subject->getDescription());
    }

    #[Test]
    public function categoryGetterAndSetter(): void
    {
        $this->subject->setCategory('log_analysis');
        self::assertSame('log_analysis', $this->subject->getCategory());
    }

    #[Test]
    public function promptTemplateGetterAndSetter(): void
    {
        $template = 'Analyze the following log: {{input}}';
        $this->subject->setPromptTemplate($template);
        self::assertSame($template, $this->subject->getPromptTemplate());
    }

    #[Test]
    public function inputTypeGetterAndSetter(): void
    {
        $this->subject->setInputType('syslog');
        self::assertSame('syslog', $this->subject->getInputType());
    }

    #[Test]
    public function inputSourceGetterAndSetter(): void
    {
        $this->subject->setInputSource('{"table":"sys_log"}');
        self::assertSame('{"table":"sys_log"}', $this->subject->getInputSource());
    }

    #[Test]
    public function outputFormatGetterAndSetter(): void
    {
        $this->subject->setOutputFormat('json');
        self::assertSame('json', $this->subject->getOutputFormat());
    }

    #[Test]
    public function isActiveGetterAndSetter(): void
    {
        $this->subject->setIsActive(false);
        self::assertFalse($this->subject->getIsActive());
        self::assertFalse($this->subject->isActive());
    }

    #[Test]
    public function isActiveDefaultsToTrue(): void
    {
        self::assertTrue($this->subject->isActive());
    }

    #[Test]
    public function isSystemGetterAndSetter(): void
    {
        $this->subject->setIsSystem(true);
        self::assertTrue($this->subject->getIsSystem());
        self::assertTrue($this->subject->isSystem());
    }

    #[Test]
    public function isSystemDefaultsToFalse(): void
    {
        self::assertFalse($this->subject->isSystem());
    }

    #[Test]
    public function sortingGetterAndSetter(): void
    {
        $this->subject->setSorting(10);
        self::assertSame(10, $this->subject->getSorting());
    }

    // ========================================
    // Enum getter tests
    // ========================================

    #[Test]
    public function getCategoryEnumReturnsCorrectEnum(): void
    {
        $this->subject->setCategory('log_analysis');
        self::assertSame(TaskCategory::LOG_ANALYSIS, $this->subject->getCategoryEnum());
    }

    #[Test]
    public function getCategoryEnumReturnsNullForUnknownCategory(): void
    {
        $this->subject->setCategory('nonexistent_category');
        self::assertNull($this->subject->getCategoryEnum());
    }

    #[Test]
    public function getInputTypeEnumReturnsCorrectEnum(): void
    {
        $this->subject->setInputType('manual');
        self::assertSame(TaskInputType::MANUAL, $this->subject->getInputTypeEnum());
    }

    #[Test]
    public function getInputTypeEnumReturnsNullForUnknownType(): void
    {
        $this->subject->setInputType('unknown_type');
        self::assertNull($this->subject->getInputTypeEnum());
    }

    #[Test]
    public function getOutputFormatEnumReturnsCorrectEnum(): void
    {
        $this->subject->setOutputFormat('markdown');
        self::assertSame(TaskOutputFormat::MARKDOWN, $this->subject->getOutputFormatEnum());
    }

    #[Test]
    public function getOutputFormatEnumReturnsNullForUnknownFormat(): void
    {
        $this->subject->setOutputFormat('unknown_format');
        self::assertNull($this->subject->getOutputFormatEnum());
    }

    // ========================================
    // getInputSourceArray
    // ========================================

    #[Test]
    public function getInputSourceArrayReturnsEmptyArrayForEmptyString(): void
    {
        self::assertSame([], $this->subject->getInputSourceArray());
    }

    #[Test]
    public function getInputSourceArrayDecodesValidJson(): void
    {
        $this->subject->setInputSource('{"table":"sys_log","limit":100}');
        $result = $this->subject->getInputSourceArray();

        self::assertSame('sys_log', $result['table']);
        self::assertSame(100, $result['limit']);
    }

    #[Test]
    public function getInputSourceArrayReturnsEmptyArrayForInvalidJson(): void
    {
        $this->subject->setInputSource('not valid json {{');
        self::assertSame([], $this->subject->getInputSourceArray());
    }

    #[Test]
    public function getInputSourceArrayReturnsEmptyArrayForJsonScalar(): void
    {
        $this->subject->setInputSource('"just a string"');
        self::assertSame([], $this->subject->getInputSourceArray());
    }

    #[Test]
    public function setInputSourceArrayEncodesArrayToJson(): void
    {
        $source = ['table' => 'sys_log', 'limit' => 50];
        $this->subject->setInputSourceArray($source);

        $result = $this->subject->getInputSourceArray();
        self::assertSame('sys_log', $result['table']);
        self::assertSame(50, $result['limit']);
    }

    // ========================================
    // buildPrompt
    // ========================================

    #[Test]
    public function buildPromptReturnsTemplateWhenNoVariables(): void
    {
        $template = 'Analyze the following data and provide insights.';
        $this->subject->setPromptTemplate($template);

        self::assertSame($template, $this->subject->buildPrompt());
    }

    #[Test]
    public function buildPromptReplacesVariablePlaceholders(): void
    {
        $this->subject->setPromptTemplate('Translate {{text}} to {{language}}.');
        $result = $this->subject->buildPrompt([
            'text' => 'Hello world',
            'language' => 'German',
        ]);

        self::assertSame('Translate Hello world to German.', $result);
    }

    #[Test]
    public function buildPromptLeavesUnmatchedPlaceholdersIntact(): void
    {
        $this->subject->setPromptTemplate('Hello {{name}}, your task is {{task}}.');
        $result = $this->subject->buildPrompt(['name' => 'Alice']);

        self::assertSame('Hello Alice, your task is {{task}}.', $result);
    }

    #[Test]
    public function buildPromptReplacesMultipleOccurrencesOfSameVariable(): void
    {
        $this->subject->setPromptTemplate('{{item}} costs {{price}}. Buy {{item}} now for {{price}}.');
        $result = $this->subject->buildPrompt([
            'item' => 'Book',
            'price' => '$10',
        ]);

        self::assertSame('Book costs $10. Buy Book now for $10.', $result);
    }

    #[Test]
    public function buildPromptHandlesEmptyTemplate(): void
    {
        $this->subject->setPromptTemplate('');
        $result = $this->subject->buildPrompt(['key' => 'value']);

        self::assertSame('', $result);
    }

    #[Test]
    public function buildPromptHandlesEmptyVariablesArray(): void
    {
        $template = 'Static prompt with no variables.';
        $this->subject->setPromptTemplate($template);
        $result = $this->subject->buildPrompt([]);

        self::assertSame($template, $result);
    }

    // ========================================
    // requiresManualInput
    // ========================================

    #[Test]
    public function requiresManualInputReturnsTrueForManualType(): void
    {
        $this->subject->setInputType('manual');
        self::assertTrue($this->subject->requiresManualInput());
    }

    #[Test]
    public function requiresManualInputReturnsFalseForSyslogType(): void
    {
        $this->subject->setInputType('syslog');
        self::assertFalse($this->subject->requiresManualInput());
    }

    #[Test]
    public function requiresManualInputReturnsFalseForTableType(): void
    {
        $this->subject->setInputType('table');
        self::assertFalse($this->subject->requiresManualInput());
    }

    // ========================================
    // Static list methods
    // ========================================

    #[Test]
    public function getInputTypesReturnsAllExpectedTypes(): void
    {
        $types = Task::getInputTypes();

        self::assertArrayHasKey('manual', $types);
        self::assertArrayHasKey('syslog', $types);
        self::assertArrayHasKey('deprecation_log', $types);
        self::assertArrayHasKey('table', $types);
        self::assertArrayHasKey('file', $types);
    }

    #[Test]
    public function getOutputFormatsReturnsAllExpectedFormats(): void
    {
        $formats = Task::getOutputFormats();

        self::assertArrayHasKey('markdown', $formats);
        self::assertArrayHasKey('json', $formats);
        self::assertArrayHasKey('plain', $formats);
        self::assertArrayHasKey('html', $formats);
    }

    #[Test]
    public function getCategoriesReturnsAllExpectedCategories(): void
    {
        $categories = Task::getCategories();

        self::assertArrayHasKey('log_analysis', $categories);
        self::assertArrayHasKey('content', $categories);
        self::assertArrayHasKey('system', $categories);
        self::assertArrayHasKey('developer', $categories);
        self::assertArrayHasKey('general', $categories);
    }

    // ========================================
    // Default values
    // ========================================

    #[Test]
    public function categoryDefaultsToGeneral(): void
    {
        self::assertSame(Task::CATEGORY_GENERAL, $this->subject->getCategory());
    }

    #[Test]
    public function inputTypeDefaultsToManual(): void
    {
        self::assertSame('manual', $this->subject->getInputType());
    }

    #[Test]
    public function outputFormatDefaultsToMarkdown(): void
    {
        self::assertSame(Task::OUTPUT_MARKDOWN, $this->subject->getOutputFormat());
    }

    // ========================================
    // Deprecated constants still exist
    // ========================================

    #[Test]
    public function deprecatedInputConstantsExist(): void
    {
        self::assertSame('manual', Task::INPUT_MANUAL);
        self::assertSame('syslog', Task::INPUT_SYSLOG);
        self::assertSame('deprecation_log', Task::INPUT_DEPRECATION_LOG);
        self::assertSame('table', Task::INPUT_TABLE);
        self::assertSame('file', Task::INPUT_FILE);
    }

    #[Test]
    public function deprecatedOutputConstantsExist(): void
    {
        self::assertSame('markdown', Task::OUTPUT_MARKDOWN);
        self::assertSame('json', Task::OUTPUT_JSON);
        self::assertSame('plain', Task::OUTPUT_PLAIN);
        self::assertSame('html', Task::OUTPUT_HTML);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function inputTypeProvider(): array
    {
        return [
            'manual' => ['manual'],
            'syslog' => ['syslog'],
            'deprecation_log' => ['deprecation_log'],
            'table' => ['table'],
            'file' => ['file'],
        ];
    }

    #[Test]
    #[DataProvider('inputTypeProvider')]
    public function inputTypeEnumCanBeResolvedForAllTypes(string $inputType): void
    {
        $this->subject->setInputType($inputType);
        self::assertNotNull($this->subject->getInputTypeEnum());
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolGroup;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateTranslationDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\MoveContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Tests\Unit\Language\LabelCatalogue;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every shipped writer declares itself an editor action, and every part of that
 * declaration resolves (ADR-152).
 *
 * A `LLL:` key that no catalogue carries and an icon identifier nothing
 * registers both fail silently at runtime — an empty label, a missing glyph —
 * so the declaration is only worth having if something checks it. This is that
 * something.
 *
 * `getEditorAction()` returns constant data on every builtin, so instantiating
 * via `newInstanceWithoutConstructor` is safe: no collaborator is touched.
 */
#[CoversNothing]
final class EditorActionDeclarationTest extends TestCase
{
    /** @var list<string> the tables the five writers address */
    private const KNOWN_RECORD_TYPES = ['pages', 'tt_content', 'sys_file'];

    /**
     * @return array<string, array{class-string<ToolInterface>, list<string>}>
     */
    public static function writers(): array
    {
        return [
            'update_page_metadata'         => [UpdatePageMetadataTool::class, ['pages']],
            'set_file_alternative_text'    => [SetFileAlternativeTextTool::class, ['sys_file']],
            'move_content_element'         => [MoveContentElementTool::class, ['tt_content']],
            'create_content_element_draft' => [CreateContentElementDraftTool::class, ['tt_content']],
            'create_translation_draft'     => [CreateTranslationDraftTool::class, ['pages', 'tt_content']],
        ];
    }

    /**
     * The same writers without the expectation column, for the assertions that
     * do not need it — PHPUnit refuses a data set wider than the signature.
     *
     * @return array<string, array{class-string<ToolInterface>}>
     */
    public static function writerClasses(): array
    {
        return array_map(
            static fn(array $row): array => [$row[0]],
            self::writers(),
        );
    }

    /**
     * @param class-string<ToolInterface> $class
     * @param list<string>                $expectedRecordTypes
     */
    #[Test]
    #[DataProvider('writers')]
    public function everyWriterDeclaresAnEditorAction(string $class, array $expectedRecordTypes): void
    {
        $tool = $this->instantiate($class);

        self::assertInstanceOf(EditorActionInterface::class, $tool);
        self::assertSame(ToolGroup::EDITING->value, $tool->getGroup());
        self::assertSame($expectedRecordTypes, $tool->getEditorAction()->recordTypes);
    }

    /**
     * @param class-string<ToolInterface> $class
     */
    #[Test]
    #[DataProvider('writerClasses')]
    public function everyDeclaredLabelResolvesInBothCatalogues(string $class): void
    {
        $tool = $this->instantiate($class);
        self::assertInstanceOf(EditorActionInterface::class, $tool);
        $action = $tool->getEditorAction();

        foreach ([$action->labelKey, $action->descriptionKey] as $key) {
            self::assertNotNull(LabelCatalogue::source($key), 'No English text for ' . $key);
            self::assertNotNull(LabelCatalogue::target($key), 'No German text for ' . $key);
        }
    }

    /**
     * The human description must not merely repeat the model-facing one — that
     * is the whole reason the declaration exists (ADR-152).
     *
     * @param class-string<ToolInterface> $class
     */
    #[Test]
    #[DataProvider('writerClasses')]
    public function theHumanDescriptionIsNotTheModelFacingOne(string $class): void
    {
        $tool = $this->instantiate($class);
        self::assertInstanceOf(EditorActionInterface::class, $tool);

        self::assertNotSame(
            $tool->getSpec()->description,
            LabelCatalogue::source($tool->getEditorAction()->descriptionKey),
        );
    }

    /**
     * @param class-string<ToolInterface> $class
     */
    #[Test]
    #[DataProvider('writerClasses')]
    public function everyDeclaredIconIsRegisteredAndItsFileExists(string $class): void
    {
        $tool = $this->instantiate($class);
        self::assertInstanceOf(EditorActionInterface::class, $tool);
        $identifier = $tool->getEditorAction()->iconIdentifier;

        $icons = require dirname(__DIR__, 5) . '/Configuration/Icons.php';
        self::assertIsArray($icons);
        self::assertArrayHasKey($identifier, $icons, 'Icon ' . $identifier . ' is not registered in Configuration/Icons.php');

        $registration = $icons[$identifier];
        self::assertIsArray($registration);
        $source = $registration['source'] ?? null;
        self::assertIsString($source);
        self::assertStringStartsWith('EXT:nr_llm/', $source);
        self::assertFileExists(dirname(__DIR__, 5) . '/' . substr($source, strlen('EXT:nr_llm/')));
    }

    /**
     * @param class-string<ToolInterface> $class
     */
    #[Test]
    #[DataProvider('writerClasses')]
    public function theDeclaredRecordTypesAreRealTableNames(string $class): void
    {
        $tool = $this->instantiate($class);
        self::assertInstanceOf(EditorActionInterface::class, $tool);

        foreach ($tool->getEditorAction()->recordTypes as $table) {
            self::assertContains($table, self::KNOWN_RECORD_TYPES, 'Unexpected record type ' . $table);
        }
    }

    /**
     * @param class-string<ToolInterface> $class
     */
    private function instantiate(string $class): ToolInterface
    {
        $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}

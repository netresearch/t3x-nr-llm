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
    /**
     * Which required argument may carry a uid of which table.
     *
     * `recordTypes` names the table whose uid the arguments IDENTIFY
     * (ADR-152), and the catalogue hands a run nothing but that table and that
     * uid — one offered tool, no lookup tool beside it. A declaration naming a
     * table no required argument can be filled from therefore offers an action
     * that can only refuse or guess. `create_content_element_draft` declared
     * `tt_content` while requiring a `pages` uid, which is exactly that.
     *
     * @var array<string, list<string>>
     */
    private const SUBJECT_ARGUMENTS = [
        'pages'      => ['uid', 'page'],
        'tt_content' => ['uid'],
        'sys_file'   => ['uid'],
    ];

    /**
     * @return array<string, array{class-string<ToolInterface>, list<string>}>
     */
    public static function writers(): array
    {
        return [
            'update_page_metadata'         => [UpdatePageMetadataTool::class, ['pages']],
            'set_file_alternative_text'    => [SetFileAlternativeTextTool::class, ['sys_file']],
            'move_content_element'         => [MoveContentElementTool::class, ['tt_content']],
            'create_content_element_draft' => [CreateContentElementDraftTool::class, ['pages']],
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
    /**
     * Every declared record type is a table one of the tool's own REQUIRED
     * arguments can be filled from.
     *
     * The offer is the whole context the run gets: the prompt names one table
     * and one uid, and `allowedToolNames` holds exactly this tool. An action
     * whose declaration points at a table it cannot be told about is an action
     * that can only fail or write somewhere else.
     *
     * @param class-string<ToolInterface> $class
     */
    #[Test]
    #[DataProvider('writerClasses')]
    public function everyDeclaredRecordTypeCanFillARequiredArgument(string $class): void
    {
        $tool = $this->instantiate($class);
        self::assertInstanceOf(EditorActionInterface::class, $tool);

        $declared = $tool->getSpec()->parameters['required'] ?? [];
        self::assertIsArray($declared);
        $required = array_values(array_filter($declared, is_string(...)));

        foreach ($tool->getEditorAction()->recordTypes as $table) {
            self::assertNotSame(
                [],
                array_intersect(self::SUBJECT_ARGUMENTS[$table] ?? [], $required),
                sprintf('%s declares "%s" but requires no argument that carries such a uid.', $class, $table),
            );
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

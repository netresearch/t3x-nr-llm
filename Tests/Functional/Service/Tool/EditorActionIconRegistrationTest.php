<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreatePageDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateTranslationDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\MoveContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\EditorActionInterface;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Core\Imaging\IconRegistry;

/**
 * Every icon a writer declares is one TYPO3 has actually registered (ADR-152).
 *
 * This is a functional test, not a unit one, and the difference is the whole
 * point: reading `Configuration/Icons.php` from disk proves a file lists a key,
 * while asking {@see IconRegistry} proves the running system can resolve it —
 * which is what an unresolvable identifier costs at runtime, a missing glyph
 * with no error.
 *
 * `getEditorAction()` returns constant data on every builtin, so instantiating
 * via `newInstanceWithoutConstructor` is safe: no collaborator is touched.
 */
#[CoversNothing]
final class EditorActionIconRegistrationTest extends AbstractFunctionalTestCase
{
    /**
     * @return array<string, array{class-string<ToolInterface>}>
     */
    public static function writerClasses(): array
    {
        return [
            'update_page_metadata'         => [UpdatePageMetadataTool::class],
            'set_file_alternative_text'    => [SetFileAlternativeTextTool::class],
            'move_content_element'         => [MoveContentElementTool::class],
            'create_content_element_draft' => [CreateContentElementDraftTool::class],
            'create_page_draft'            => [CreatePageDraftTool::class],
            'create_translation_draft'     => [CreateTranslationDraftTool::class],
        ];
    }

    /**
     * @param class-string<ToolInterface> $class
     */
    #[Test]
    #[DataProvider('writerClasses')]
    public function everyDeclaredIconResolvesThroughTheRegistry(string $class): void
    {
        $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(EditorActionInterface::class, $tool);

        $identifier = $tool->getEditorAction()->iconIdentifier;
        $registry   = $this->getService(IconRegistry::class);

        self::assertTrue(
            $registry->isRegistered($identifier),
            sprintf('Icon "%s" is declared by %s and registered nowhere.', $identifier, $class),
        );

        $configuration = $registry->getIconConfigurationByIdentifier($identifier);
        self::assertArrayHasKey('options', $configuration);

        $options = $configuration['options'];
        self::assertIsArray($options);
        self::assertArrayHasKey('source', $options);

        $source = $options['source'];
        self::assertIsString($source);
        self::assertStringStartsWith('EXT:nr_llm/', $source);
        self::assertFileExists(dirname(__DIR__, 4) . '/' . substr($source, strlen('EXT:nr_llm/')));
    }
}

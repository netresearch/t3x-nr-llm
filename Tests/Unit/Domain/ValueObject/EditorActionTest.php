<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The declaration a catalogue reads (ADR-152) and the two things it refuses to
 * be: nameless, and unplaceable.
 */
#[CoversClass(EditorAction::class)]
final class EditorActionTest extends TestCase
{
    #[Test]
    public function carriesTheDeclaredMetadata(): void
    {
        $action = new EditorAction(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.x.label',
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.x.description',
            'nrllm-editor-action-page-metadata',
            ['pages', 'tt_content'],
        );

        self::assertSame('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.x.label', $action->labelKey);
        self::assertSame('LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:editorAction.x.description', $action->descriptionKey);
        self::assertSame('nrllm-editor-action-page-metadata', $action->iconIdentifier);
        self::assertSame(['pages', 'tt_content'], $action->recordTypes);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function incompleteNaming(): array
    {
        return [
            'no label key'       => ['', 'description', 'icon'],
            'no description key' => ['label', '', 'icon'],
            'no icon'            => ['label', 'description', ''],
        ];
    }

    #[Test]
    #[DataProvider('incompleteNaming')]
    public function refusesADeclarationThatCannotBeRendered(string $label, string $description, string $icon): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1786406401);

        new EditorAction($label, $description, $icon, ['pages']);
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function unplaceableRecordTypes(): array
    {
        return [
            'none'            => [[]],
            'an empty string' => [['pages', '']],
        ];
    }

    /**
     * @param list<string> $recordTypes
     */
    #[Test]
    #[DataProvider('unplaceableRecordTypes')]
    public function refusesADeclarationNoCatalogueCouldPlace(array $recordTypes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1786406402);

        new EditorAction('label', 'description', 'icon', $recordTypes);
    }
}

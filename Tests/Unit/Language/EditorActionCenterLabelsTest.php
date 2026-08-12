<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Language;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Editor Action Center's labels that live in PHP (ADR-158).
 *
 * Every other string of this feature sits in Fluid beside an English `default`,
 * so a missing key degrades to that default and is visible in review. These do
 * not: the context-menu item renders an empty label, and a flash message renders
 * its own key back at the user. Nothing but this test connects the PHP to the
 * two catalogues.
 */
#[CoversNothing]
final class EditorActionCenterLabelsTest extends TestCase
{
    private const PREFIX = 'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:';

    /**
     * @return array<string, array{string}>
     */
    public static function phpSideKeys(): array
    {
        $ids = [
            // EditorActionItemProvider's one item.
            'editorActions.contextMenu.label',
            // EditorActionController's flash messages, one per settled outcome.
            'editorActions.flash.notAvailable',
            'editorActions.flash.awaiting',
            'editorActions.flash.completedWithoutWrite',
            'editorActions.flash.blocked',
            'editorActions.flash.cancelled',
            'editorActions.flash.failed',
            // The bulk report (ADR-162). Same reason: these are built in PHP,
            // so a missing key renders the key at the editor.
            'editorActions.batch.flash.started',
            'editorActions.batch.flash.discarded',
            'editorActions.batch.flash.truncated',
            'editorActions.batch.flash.completedWithoutWrite',
            'editorActions.batch.flash.blocked',
            'editorActions.batch.flash.cancelled',
            'editorActions.batch.flash.failed',
            'editorActions.batch.flash.budgetStopped',
            'editorActions.batch.flash.notAttempted',
            'editorActions.batch.flash.nothingNamed',
            // EditorActionBatchPlanner's skip reasons, one per way a record
            // stays out of the batch.
            'editorActions.batch.skip.notOffered',
            'editorActions.batch.skip.overCap',
            'editorActions.batch.skip.duplicate',
        ];

        $cases = [];
        foreach ($ids as $id) {
            $cases[$id] = [self::PREFIX . $id];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('phpSideKeys')]
    public function resolvesInBothCatalogues(string $key): void
    {
        self::assertNotNull(LabelCatalogue::source($key), 'No English text for ' . $key);
        self::assertNotNull(LabelCatalogue::target($key), 'No German text for ' . $key);
    }
}

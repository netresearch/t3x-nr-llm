<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Language;

use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * The texts of the approval preview lines (ADR-213).
 *
 * A line is built in PHP from a label, so a label without a text renders as its
 * own key at the approver, and a German catalogue that lags behind brings back
 * the mixed-language card the guidelines forbid (rule 17). Nothing but this test
 * connects the enum to the two catalogues.
 */
#[CoversClass(ApprovalPreviewLabel::class)]
final class ApprovalPreviewCatalogueTest extends TestCase
{
    private const PREFIX = 'approvalPreview.';

    /**
     * @return array<string, array{ApprovalPreviewLabel}>
     */
    public static function labels(): array
    {
        $cases = [];
        foreach (ApprovalPreviewLabel::cases() as $label) {
            $cases[$label->name] = [$label];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('labels')]
    public function everyLabelHasAnEnglishAndAGermanText(ApprovalPreviewLabel $label): void
    {
        self::assertNotNull(LabelCatalogue::source($label->reference()), 'No English text for ' . $label->value);
        self::assertNotNull(LabelCatalogue::target($label->reference()), 'No German text for ' . $label->value);
        self::assertNotSame('', LabelCatalogue::source($label->reference()));
        self::assertNotSame('', LabelCatalogue::target($label->reference()));
    }

    #[Test]
    #[DataProvider('labels')]
    public function theGermanTextIsAnActualTranslation(ApprovalPreviewLabel $label): void
    {
        $english = LabelCatalogue::source($label->reference());
        $german  = LabelCatalogue::target($label->reference());

        // A German entry that equals its English source is an untranslated
        // copy. The only legitimate equal pair is a bare placeholder.
        self::assertNotSame($english, $german, $label->value . ' has the English text as its German one');
    }

    #[Test]
    #[DataProvider('labels')]
    public function bothTextsTakeTheSamePlaceholders(ApprovalPreviewLabel $label): void
    {
        $english = $this->placeholders((string)LabelCatalogue::source($label->reference()));
        $german  = $this->placeholders((string)LabelCatalogue::target($label->reference()));

        self::assertSame($english, $german, 'The placeholders of ' . $label->value . ' differ between the languages');
    }

    #[Test]
    #[DataProvider('labels')]
    public function noLineNamesAnInternalFieldOrTool(ApprovalPreviewLabel $label): void
    {
        // Rule 18: no `nav_title`, `sys_language_uid`, `create_page_draft` in
        // what the editor reads. The technical line carries identifiers by
        // design (rule 26), and its table name arrives as an argument, not here.
        foreach (['source' => LabelCatalogue::source($label->reference()), 'target' => LabelCatalogue::target($label->reference())] as $side => $text) {
            self::assertDoesNotMatchRegularExpression(
                '/\b[a-z]+(?:_[a-z]+)+\b/',
                (string)$text,
                $label->value . ' (' . $side . ') contains an internal name: ' . $text,
            );
        }
    }

    #[Test]
    public function everyCatalogueEntryOfThisFamilyIsALabel(): void
    {
        $known = array_map(static fn(ApprovalPreviewLabel $label): string => $label->value, ApprovalPreviewLabel::cases());
        sort($known);

        foreach (['locallang.xlf', 'de.locallang.xlf'] as $file) {
            $ids = $this->idsOf($file);

            self::assertSame($known, $ids, $file . ' and ApprovalPreviewLabel disagree about the approval preview texts');
        }
    }

    /**
     * The placeholder conversions of a text, positions normalised away, sorted.
     *
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/%(?:\d+\$)?[sd]/', $text, $matches);
        $conversions = array_map(static fn(string $match): string => substr($match, -1), $matches[0]);
        sort($conversions);

        return $conversions;
    }

    /**
     * @return list<string>
     */
    private function idsOf(string $file): array
    {
        $contents = file_get_contents(__DIR__ . '/../../../Resources/Private/Language/' . $file);
        self::assertIsString($contents);

        $ids = [];
        foreach ((new SimpleXMLElement($contents))->xpath('//*[local-name()="trans-unit"]') ?? [] as $unit) {
            $id = (string)($unit['id'] ?? '');
            if (str_starts_with($id, self::PREFIX)) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return $ids;
    }
}

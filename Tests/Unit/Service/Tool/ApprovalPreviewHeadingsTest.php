<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ApprovalPreviewHeadings;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Tests\Unit\Language\LabelCatalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The published heading texts (ADR-213): a consumer reuses a preview's first
 * line as card title or button text only when it is one of them, so the set
 * must hold every heading and nothing else, and each must be recognisable by
 * plain equality in both languages.
 */
#[CoversClass(ApprovalPreviewHeadings::class)]
#[CoversClass(ApprovalPreviewLabel::class)]
final class ApprovalPreviewHeadingsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../';

    #[Test]
    public function theReferencesAreExactlyTheHeadingLabels(): void
    {
        // A new heading case named like the others but not marked would never
        // reach the consumer; a marked case that is not a heading would put a
        // summary on a button.
        $named = [];
        foreach (ApprovalPreviewLabel::cases() as $label) {
            if (preg_match('/\.heading/i', $label->value) === 1) {
                $named[] = $label->reference();
            }
        }

        self::assertSame($named, ApprovalPreviewHeadings::labelReferences());
        self::assertCount(23, $named);
    }

    #[Test]
    public function aHeadingTakesNoPlaceholderAndCollidesWithNoOtherText(): void
    {
        foreach (['source', 'target'] as $side) {
            $headings = [];
            $others   = [];
            foreach (ApprovalPreviewLabel::cases() as $label) {
                $text = (string)($side === 'source' ? LabelCatalogue::source($label->reference()) : LabelCatalogue::target($label->reference()));
                if ($label->isHeading()) {
                    self::assertDoesNotMatchRegularExpression('/%/', $text, $label->value . ' (' . $side . ') takes a placeholder');
                    $headings[] = $text;
                } else {
                    $others[] = $text;
                }
            }

            self::assertSame($headings, array_values(array_unique($headings)), 'Two headings share a text (' . $side . ')');
            self::assertSame([], array_values(array_intersect($headings, $others)), "A heading text is also another line's text (" . $side . ')');
        }
    }

    #[Test]
    public function aLineIsAHeadingOnlyWhenItEqualsAHeadingText(): void
    {
        $german = self::createStub(LanguageService::class);
        $german->method('sL')->willReturnCallback(static fn(string $key): string => LabelCatalogue::target($key) ?? '');

        $heading = (string)LabelCatalogue::target(ApprovalPreviewLabel::DeletePageHeading->reference());
        self::assertTrue(ApprovalPreviewHeadings::isHeading($heading, $german));
        self::assertTrue(ApprovalPreviewHeadings::isHeading(' ' . $heading . "\n", $german));

        $object = sprintf((string)LabelCatalogue::target(ApprovalPreviewLabel::ObjectPage->reference()), '„Start“');
        self::assertFalse(ApprovalPreviewHeadings::isHeading($object, $german));
        self::assertFalse(ApprovalPreviewHeadings::isHeading('Refused: this tool attaches to content elements in the default language only.', $german));
        self::assertFalse(ApprovalPreviewHeadings::isHeading('', $german));
        // The English heading is no heading for a German reader's lines.
        self::assertFalse(ApprovalPreviewHeadings::isHeading((string)LabelCatalogue::source(ApprovalPreviewLabel::DeletePageHeading->reference()), $german));
    }

    #[Test]
    public function everyPreviewingToolHasAFunctionalTestOfItsHeading(): void
    {
        // The functional tests check the first line of each tool's successful
        // preview against the published headings (AssertsPreviewHeadingTrait);
        // a previewing tool without such a test is a tool whose first line
        // nobody has checked.
        $tools = [];
        foreach (glob(self::ROOT . 'Classes/Service/Tool/Builtin/*.php') ?: [] as $file) {
            $class = 'Netresearch\\NrLlm\\Service\\Tool\\Builtin\\' . basename($file, '.php');
            if (!class_exists($class) || !(new ReflectionClass($class))->implementsInterface(ToolPreviewInterface::class)) {
                continue;
            }

            $tools[] = basename($file, '.php');
        }

        self::assertCount(18, $tools);
        foreach ($tools as $tool) {
            $checked = false;
            foreach (glob(self::ROOT . 'Tests/Functional/Service/Tool/' . $tool . '*Test.php') ?: [] as $test) {
                $source  = (string)file_get_contents($test);
                $checked = $checked || preg_match('/(?:assertStartsWithHeading|assertGermanEditorLines)\(/', $source) === 1;
            }

            self::assertTrue($checked, $tool . ' has no functional test that checks its preview heading');
        }
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ApprovalPreviewHeadings;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\Builtin\AttachFileToContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\AttachFileToRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CopyRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreatePageDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateRecordDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateTranslationDraftTool;
use Netresearch\NrLlm\Service\Tool\Builtin\DeleteRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\FetchExternalUrlTool;
use Netresearch\NrLlm\Service\Tool\Builtin\MoveContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\MovePageTool;
use Netresearch\NrLlm\Service\Tool\Builtin\PublishRecordTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ReplaceFileReferenceTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetFileAlternativeTextTool;
use Netresearch\NrLlm\Service\Tool\Builtin\SetPageSocialImageTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateContentElementTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateFalAssetMetaTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\AttachFileToContentElementToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\AttachFileToRecordToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\CopyRecordToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\CreateContentElementDraftToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\CreatePageDraftToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\CreateRecordDraftToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\CreateTranslationDraftToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\DeleteRecordToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\FetchExternalUrlToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\MoveContentElementToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\MovePageToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\PublishRecordToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\ReplaceFileReferenceToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\SetFileAlternativeTextToolFileMountTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\SetPageSocialImageToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\UpdateContentElementToolTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\UpdateFalAssetMetaToolFileMountTest;
use Netresearch\NrLlm\Tests\Functional\Service\Tool\UpdatePageMetadataToolTest;
use Netresearch\NrLlm\Tests\Unit\Language\LabelCatalogue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * The published heading texts (ADR-213): a consumer reuses a preview's first
 * line as card title or button text only when it is one of them, so each must
 * be recognisable by plain equality in both languages, and every previewing
 * tool's first line must be checked against them.
 */
#[CoversClass(ApprovalPreviewHeadings::class)]
#[CoversClass(ApprovalPreviewLabel::class)]
final class ApprovalPreviewHeadingsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../';

    /**
     * Each previewing tool and the functional test that checks its successful
     * preview opens with a heading, through the public class
     * ({@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\AssertsPreviewHeadingTrait}). A new previewing tool
     * fails this test until it is listed here with such a test.
     */
    private const HEADING_TESTS = [
        AttachFileToContentElementTool::class => [AttachFileToContentElementToolTest::class, 'thePreviewNamesTheElementTheFieldAndTheFile'],
        AttachFileToRecordTool::class         => [AttachFileToRecordToolTest::class, 'thePreviewNamesTheRecordTheFieldAndTheFile'],
        CopyRecordTool::class                 => [CopyRecordToolTest::class, 'thePreviewNamesTheTargetAndTheHiddenCopyAndCopiesNothing'],
        CreateContentElementDraftTool::class  => [CreateContentElementDraftToolTest::class, 'thePreviewShowsTheWholeDraftAndWritesNothing'],
        CreatePageDraftTool::class            => [CreatePageDraftToolTest::class, 'thePreviewShowsTheWholeDraftAndWritesNothing'],
        CreateRecordDraftTool::class          => [CreateRecordDraftToolTest::class, 'thePreviewNamesTheColumnsWithTheirLabelsInTheActingUsersLanguageAndWritesNothing'],
        CreateTranslationDraftTool::class     => [CreateTranslationDraftToolTest::class, 'thePreviewNamesTheMachineTranslationAndTheTranslator'],
        DeleteRecordTool::class               => [DeleteRecordToolTest::class, 'thePreviewCountsWhatGoesAlongAndWhatStillPointsAtItAndDeletesNothing'],
        FetchExternalUrlTool::class           => [FetchExternalUrlToolTest::class, 'thePreviewIsInTheActingUsersLanguage'],
        MoveContentElementTool::class         => [MoveContentElementToolTest::class, 'thePreviewNamesBothSidesAndWritesNothing'],
        MovePageTool::class                   => [MovePageToolTest::class, 'thePreviewWarnsWhenThePageMovesIntoAnotherSite'],
        PublishRecordTool::class              => [PublishRecordToolTest::class, 'thePreviewNamesWhatStillRestrictsTheRecordAndWritesNothing'],
        ReplaceFileReferenceTool::class       => [ReplaceFileReferenceToolTest::class, 'thePreviewNamesBothFilesAndWhatIsNotCarriedOverAndWritesNothing'],
        SetFileAlternativeTextTool::class     => [SetFileAlternativeTextToolFileMountTest::class, 'thePreviewShowsTheStoredValueNextToTheProposedOne'],
        SetPageSocialImageTool::class         => [SetPageSocialImageToolTest::class, 'thePreviewShowsTheCurrentAndTheFutureFile'],
        UpdateContentElementTool::class       => [UpdateContentElementToolTest::class, 'thePreviewShowsEveryColumnBeforeAndAfterAndWritesNothing'],
        UpdateFalAssetMetaTool::class         => [UpdateFalAssetMetaToolFileMountTest::class, 'theApprovalCardIsInTheActingUsersLanguage'],
        UpdatePageMetadataTool::class         => [UpdatePageMetadataToolTest::class, 'thePreviewShowsTheStoredValueNextToTheProposedOne'],
    ];

    #[Test]
    public function theReferencesAreTheLabelsMarkedAsHeadings(): void
    {
        $marked = [];
        foreach (ApprovalPreviewLabel::cases() as $label) {
            if ($label->isHeading()) {
                $marked[] = $label->reference();
            }
        }

        self::assertNotSame([], $marked);
        self::assertSame($marked, ApprovalPreviewHeadings::labelReferences());
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
        $german = $this->languageService('de', static fn(string $key): string => LabelCatalogue::target($key) ?? '');

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
    public function theHeadingsAreResolvedOncePerLanguageServiceAndLanguage(): void
    {
        $calls   = 0;
        $count   = count(ApprovalPreviewHeadings::labelReferences());
        $heading = (string)LabelCatalogue::target(ApprovalPreviewLabel::MovePageHeading->reference());
        $german  = $this->languageService('de', static function (string $key) use (&$calls): string {
            ++$calls;

            return LabelCatalogue::target($key) ?? '';
        });

        self::assertTrue(ApprovalPreviewHeadings::isHeading($heading, $german));
        self::assertFalse(ApprovalPreviewHeadings::isHeading('Seite „Start“', $german));
        self::assertTrue(ApprovalPreviewHeadings::isHeading($heading, $german));
        self::assertSame($count, $calls, 'repeated checks resolve the headings again');

        // The same service switched to another language resolves again.
        $german->lang = 'en';
        ApprovalPreviewHeadings::isHeading($heading, $german);
        self::assertSame(2 * $count, $calls, 'a memo from another language answered');

        // Another service resolves for itself.
        $other = $this->languageService('de', static function (string $key) use (&$calls): string {
            ++$calls;

            return LabelCatalogue::target($key) ?? '';
        });
        self::assertTrue(ApprovalPreviewHeadings::isHeading($heading, $other));
        self::assertSame(3 * $count, $calls);
    }

    #[Test]
    public function everyPreviewingToolIsListedWithAFunctionalTestOfItsHeading(): void
    {
        $tools = [];
        foreach (glob(self::ROOT . 'Classes/Service/Tool/Builtin/*.php') ?: [] as $file) {
            $class = 'Netresearch\\NrLlm\\Service\\Tool\\Builtin\\' . basename($file, '.php');
            if (class_exists($class) && (new ReflectionClass($class))->implementsInterface(ToolPreviewInterface::class)) {
                $tools[] = $class;
            }
        }

        $listed = array_keys(self::HEADING_TESTS);
        sort($tools);
        sort($listed);
        self::assertSame($tools, $listed, 'Every previewing tool, and only those, is listed with its heading test');

        foreach (self::HEADING_TESTS as $tool => [$testClass, $testMethod]) {
            self::assertTrue(method_exists($testClass, $testMethod), $tool . ': ' . $testClass . '::' . $testMethod . ' does not exist');
            $method = new ReflectionMethod($testClass, $testMethod);
            self::assertNotSame([], $method->getAttributes(Test::class), $testClass . '::' . $testMethod . ' is not a test');

            self::assertMatchesRegularExpression(
                '/\bself::assert(?:StartsWithHeading|GermanEditorLines)\(/',
                $this->bodyOf($method),
                $testClass . '::' . $testMethod . ' does not check the heading of ' . $tool,
            );
        }
    }

    private function bodyOf(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        self::assertIsString($file);
        $lines = file($file);
        self::assertIsArray($lines);

        return implode('', array_slice($lines, (int)$method->getStartLine() - 1, (int)$method->getEndLine() - (int)$method->getStartLine() + 1));
    }

    /**
     * @param callable(string): string $sL
     */
    private function languageService(string $language, callable $sL): LanguageService
    {
        $service = self::createStub(LanguageService::class);
        $service->method('sL')->willReturnCallback($sL);
        $service->lang = $language;

        return $service;
    }
}

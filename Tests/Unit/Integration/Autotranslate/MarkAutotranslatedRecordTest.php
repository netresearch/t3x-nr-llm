<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Integration\Autotranslate;

use Netresearch\NrLlm\Integration\Autotranslate\AutotranslateSettings;
use Netresearch\NrLlm\Integration\Autotranslate\AutotranslateTranslator;
use Netresearch\NrLlm\Integration\Autotranslate\MachineTranslatedText;
use Netresearch\NrLlm\Integration\Autotranslate\MarkAutotranslatedRecord;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Event\AfterTranslateEvent;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(MarkAutotranslatedRecord::class)]
#[CoversClass(MachineTranslatedText::class)]
#[CoversClass(AutotranslateSettings::class)]
final class MarkAutotranslatedRecordTest extends AbstractUnitTestCase
{
    private mixed $tcaBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tcaBackup = $GLOBALS['TCA'] ?? null;
        $columns         = [
            'header'      => ['config' => ['type' => 'input']],
            'bodytext'    => ['config' => ['type' => 'text']],
            'pi_flexform' => ['config' => ['type' => 'flex']],
            'hidden'      => ['config' => ['type' => 'check']],
        ];
        $GLOBALS['TCA'] = [
            'tt_content'         => ['columns' => $columns],
            'pages'              => ['columns' => ['title' => ['config' => ['type' => 'input']]]],
            'sys_file_reference' => ['columns' => ['title' => ['config' => ['type' => 'input']]]],
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tcaBackup;
        parent::tearDown();
    }

    #[Test]
    public function aTranslatedContentElementIsMarked(): void
    {
        $event = $this->event(['bodytext' => '<p>Hallo</p>', 'hidden' => 0], ['bodytext' => '<p>Hello</p>', 'hidden' => 0]);

        $this->listener()($event);

        self::assertSame(1, $event->getTranslatedColumn('tx_nrllm_ai_written'));
        self::assertSame('<p>Hallo</p>', $event->getTranslatedColumn('bodytext'), 'The translation itself is left as it is');
    }

    #[Test]
    public function aTranslatedPageIsMarked(): void
    {
        $event = $this->event(['title' => 'Start'], ['title' => 'Home'], 'pages');

        $this->listener()($event);

        self::assertTrue($event->hasTranslatedColumn('tx_nrllm_ai_written'));
    }

    #[Test]
    public function aLeadingBackslashInTheSettingCounts(): void
    {
        $event = $this->event(['header' => 'Hallo'], ['header' => 'Hello']);

        $this->listener('\\' . AutotranslateTranslator::class)($event);

        self::assertTrue($event->hasTranslatedColumn('tx_nrllm_ai_written'));
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function otherTranslators(): iterable
    {
        yield 'autotranslate translates with DeepL' => [null];
        yield 'another translator class' => ['Vendor\\Site\\Translator'];
        yield 'an empty setting' => [''];
    }

    #[Test]
    #[DataProvider('otherTranslators')]
    public function aTranslationOutsideNrLlmIsNotMarked(?string $translatorClass): void
    {
        $event = $this->event(['header' => 'Hallo'], ['header' => 'Hello']);

        $this->listener($translatorClass)($event);

        self::assertFalse($event->hasTranslatedColumn('tx_nrllm_ai_written'));
    }

    #[Test]
    public function aTableWithoutTheMarkerFieldIsLeftAlone(): void
    {
        $event = $this->event(['title' => 'Bild'], ['title' => 'Picture'], 'sys_file_reference');

        $this->listener()($event);

        self::assertFalse($event->hasTranslatedColumn('tx_nrllm_ai_written'));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function noMachineTranslatedText(): iterable
    {
        yield 'only copied fields' => [
            ['hidden' => 1, 'pi_flexform' => '<T3FlexForms/>'],
            ['hidden' => 1, 'pi_flexform' => '<T3FlexForms/>'],
        ];
        yield 'a text the translator returned unchanged' => [['header' => 'Netresearch'], ['header' => 'Netresearch']];
        yield 'an emptied text' => [['bodytext' => ''], ['bodytext' => '']];
        yield 'a changed value outside the text types' => [['hidden' => 1], ['hidden' => 0]];
        yield 'a column the TCA does not know' => [['tx_unknown' => 'Hallo'], ['tx_unknown' => 'Hello']];
    }

    /**
     * @param array<string, mixed> $translated
     * @param array<string, mixed> $source
     */
    #[Test]
    #[DataProvider('noMachineTranslatedText')]
    public function aRecordWithoutMachineTranslatedTextIsNotMarked(array $translated, array $source): void
    {
        $event = $this->event($translated, $source);

        $this->listener()($event);

        self::assertFalse($event->hasTranslatedColumn('tx_nrllm_ai_written'));
    }

    #[Test]
    public function aTranslatedFlexFormCounts(): void
    {
        $event = $this->event(
            ['pi_flexform' => '<T3FlexForms><value>Hallo</value></T3FlexForms>'],
            ['pi_flexform' => '<T3FlexForms><value>Hello</value></T3FlexForms>'],
        );

        $this->listener()($event);

        self::assertTrue($event->hasTranslatedColumn('tx_nrllm_ai_written'));
    }

    private function listener(?string $translatorClass = AutotranslateTranslator::class): MarkAutotranslatedRecord
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnCallback(
            static fn (string $extension, string $path = ''): mixed => $extension . '/' . $path === 'autotranslate/translatorClass' ? $translatorClass : null,
        );

        return new MarkAutotranslatedRecord(new AutotranslateSettings($configuration));
    }

    /**
     * @param array<string, mixed> $translated
     * @param array<string, mixed> $source
     */
    private function event(array $translated, array $source, string $table = 'tt_content'): AfterTranslateEvent
    {
        return new AfterTranslateEvent($translated, $source + ['uid' => 7], 1, $table, 17, 'EN', 'DE');
    }
}

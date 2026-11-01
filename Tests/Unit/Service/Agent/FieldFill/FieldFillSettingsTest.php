<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\FieldFill;

use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillSettings;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(FieldFillSettings::class)]
final class FieldFillSettingsTest extends AbstractUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA']['pages']['columns'] = [
            'description' => ['config' => ['type' => 'text']],
            'abstract'    => ['config' => ['type' => 'text', 'max' => 120]],
            'bodytext'    => ['config' => ['type' => 'text', 'enableRichtext' => true]],
            'slug'        => ['config' => ['type' => 'slug']],
            'note'        => ['config' => ['type' => 'text']],
            'TSconfig'    => ['config' => ['type' => 'text', 'renderType' => 'codeEditor']],
        ];
        $GLOBALS['TCA']['pages']['types'] = [
            'a' => ['showitem' => 'description'],
            'b' => ['columnsOverrides' => ['note' => ['config' => ['enableRichtext' => true]]]],
        ];
        $GLOBALS['TCA']['be_users']['columns']['realName'] = ['config' => ['type' => 'input']];
        $GLOBALS['TCA']['sys_note']['columns']['message']  = ['config' => ['type' => 'text']];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    #[Test]
    public function nothingIsOptedInByDefault(): void
    {
        self::assertSame([], $this->settings([])->fieldsOf('pages'));
    }

    #[Test]
    public function aSettingThatCannotBeReadOptsInNothing(): void
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new RuntimeException('not set', 1798000103));

        self::assertSame([], (new FieldFillSettings($configuration))->fieldsOf('pages'));
    }

    #[Test]
    public function readsTheListPerTable(): void
    {
        $settings = $this->settings(['fields' => ' pages.description , pages.abstract,tt_content.header,']);

        self::assertSame(['description', 'abstract'], $settings->fieldsOf('pages'));
        self::assertTrue($settings->isOptedIn('pages', 'abstract'));
        self::assertFalse($settings->isOptedIn('pages', 'title'));
    }

    #[Test]
    public function onlyPlainTextCounts(): void
    {
        self::assertSame([], $this->settings(['fields' => 'pages.bodytext,pages.slug,pages.nothing,pages.note,pages.TSconfig'])->fieldsOf('pages'));
    }

    #[Test]
    public function accountsAndSystemRecordsDoNotCount(): void
    {
        $settings = $this->settings(['fields' => 'be_users.realName,sys_note.message']);

        self::assertSame([], $settings->fieldsOf('be_users'));
        self::assertSame([], $settings->fieldsOf('sys_note'));
    }

    #[Test]
    public function theLengthIsTheSettingOrLessWhereTheTcaSaysSo(): void
    {
        self::assertSame(FieldFillSettings::DEFAULT_MAX_LENGTH, $this->settings([])->maxLength('pages', 'description'));
        self::assertSame(200, $this->settings(['maxLength' => '200'])->maxLength('pages', 'description'));
        self::assertSame(120, $this->settings(['maxLength' => '200'])->maxLength('pages', 'abstract'));
        self::assertSame(FieldFillSettings::DEFAULT_MAX_LENGTH, $this->settings(['maxLength' => '0'])->maxLength('pages', 'description'));
    }

    /**
     * @param array<string, string> $values
     */
    private function settings(array $values): FieldFillSettings
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn($values);

        return new FieldFillSettings($configuration);
    }
}

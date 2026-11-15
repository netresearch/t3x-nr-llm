<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\DescribeFalAssetTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The EXT:filemetadata columns of `describe_fal_asset` (spec 058 DF4-DF6):
 * reported because the TCA declares them, "not set" when empty, and — all five
 * carry the `exclude` flag — withheld from an editor without the grant.
 */
#[CoversClass(DescribeFalAssetTool::class)]
final class DescribeFalAssetFilemetadataTest extends AbstractFunctionalTestCase
{
    use IndexesDescribedFilesTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'filemetadata'];

    private const FILE = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStorageWithEditorMount();

        $this->indexFile(self::FILE, '/docs/photo.txt');
        $this->indexMetadata(100, self::FILE, [
            'title'     => 'Harbour at dawn',
            'copyright' => '© 2026 Example Photo Agency',
            'creator'   => 'A. Photographer',
            'source'    => '',
            'publisher' => 'Example Press',
            'language'  => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function theFilemetadataColumnsAreReportedAndAnEmptyOneIsNotSet(): void
    {
        $content = $this->describeTool()->execute(['uid' => self::FILE], ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1)))->content;

        self::assertSame(<<<'TEXT'
            File [10] "photo.txt", metadata in the default language:
            title: "Harbour at dawn"
            alternative: not set
            description: not set
            width: not set
            height: not set
            copyright: "© 2026 Example Photo Agency"
            creator: "A. Photographer"
            source: not set
            publisher: "Example Press"
            language: "en"
            TEXT, $content);
    }

    /**
     * All five EXT:filemetadata columns are exclude fields. An editor granted
     * only `copyright` reads it and nothing of the other four.
     */
    #[Test]
    public function theFilemetadataColumnsNeedTheirGrant(): void
    {
        $content = $this->describeTool()->execute(
            ['uid' => self::FILE],
            ToolExecutionContext::fromBackendUser($this->editor('sys_file_metadata:copyright')),
        )->content;

        self::assertStringContainsString("\ncopyright: \"© 2026 Example Photo Agency\"\n", $content);
        foreach (['title', 'creator', 'source', 'publisher', 'language'] as $field) {
            self::assertStringContainsString("\n" . $field . ': withheld (no field permission)', $content, $field);
        }

        foreach (['Harbour', 'Photographer', 'Example Press', '"en"'] as $value) {
            self::assertStringNotContainsString($value, $content, $value);
        }
    }
}

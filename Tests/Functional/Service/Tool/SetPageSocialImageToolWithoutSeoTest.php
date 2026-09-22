<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\SetPageSocialImageTool;
use Netresearch\NrLlm\Service\Tool\FalStorageGate;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * `set_page_social_image` on an installation WITHOUT EXT:seo (ADR-195).
 *
 * The two columns the tool writes are EXT:seo's. Without the extension they do
 * not exist in the live TCA, and the tool has to say so rather than hand the
 * DataHandler a field it will drop. {@see SetPageSocialImageToolTest} loads the
 * extension and holds the other direction.
 */
#[CoversClass(SetPageSocialImageTool::class)]
final class SetPageSocialImageToolWithoutSeoTest extends AbstractFunctionalTestCase
{
    private SetPageSocialImageTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $connectionPool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Open page', 'doktype' => 1, 'slug' => '/open',
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => Permission::ALL,
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $gate = $this->get(FalStorageGate::class);
        self::assertInstanceOf(FalStorageGate::class, $gate);
        $this->tool = new SetPageSocialImageTool($connectionPool, $gate);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theColumnsAreAbsentWithoutExtSeo(): void
    {
        $tca = $GLOBALS['TCA'];
        self::assertIsArray($tca);
        self::assertIsArray($tca['pages']);
        self::assertIsArray($tca['pages']['columns']);
        self::assertArrayNotHasKey('og_image', $tca['pages']['columns'], 'this test proves nothing if EXT:seo is loaded');
    }

    #[Test]
    public function aCallIsRefusedAndNamesTheMissingExtension(): void
    {
        $admin = $this->setUpBackendUser(1);

        $result = $this->tool->execute(
            ['page' => 1, 'field' => 'og_image', 'file' => 1],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertTrue($result->isError);
        self::assertStringContainsString('"og_image" is not a page field this tool sets', $result->content);
        self::assertStringContainsString('EXT:seo', $result->content);
    }

    #[Test]
    public function thePreviewRefusesInTheSameWords(): void
    {
        $admin = $this->setUpBackendUser(1);

        $lines = $this->tool->previewCall(
            ['page' => 1, 'field' => 'twitter_image', 'file' => 1],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertCount(1, $lines);
        self::assertStringContainsString('"twitter_image" is not a page field this tool sets', $lines[0]);
    }

    /**
     * The schema stays valid: an empty `enum` is not a JSON-Schema the model can
     * use, so the spec falls back to naming both fields and the call refuses —
     * the shape {@see \Netresearch\NrLlm\Service\Tool\Builtin\CreateContentElementDraftTool}
     * uses for its content types.
     */
    #[Test]
    public function theSpecStillNamesBothFields(): void
    {
        $properties = $this->tool->getSpec()->parameters['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertIsArray($properties['field']);
        self::assertSame(['og_image', 'twitter_image'], $properties['field']['enum'] ?? null);
    }
}

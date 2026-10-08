<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\PageTsConfigReader;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * The workspace input of {@see PageTsConfigReader} (#1017), with EXT:workspaces
 * loaded so the overlay actually runs.
 *
 * The tree: root 1 with branches 5 and 6, page 7 under 5. In workspace 1, page
 * 7 is moved under 6, and a new page 30 exists under 6. The expectation is
 * core's own answer for the same user in that workspace, plus the literal
 * values, so a reader that agrees with a wrong core build still fails.
 */
#[CoversClass(PageTsConfigReader::class)]
final class PageTsConfigReaderWorkspaceTest extends AbstractFunctionalTestCase
{
    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'workspaces'];

    private const WORKSPACE = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => self::WORKSPACE, 'pid' => 0, 'title' => 'Draft']);

        $pages = $connectionPool->getConnectionForTable('pages');
        foreach ([
            [1, 0, 'demo.root = 1' . "\n[workspace.workspaceId == 1]\ndemo.inWorkspace = 1\n[END]", 0, 0, 0],
            [5, 1, 'demo.branch = five', 0, 0, 0],
            [6, 1, 'demo.branch = six', 0, 0, 0],
            [7, 5, '', 0, 0, 0],
            // A page created in the workspace, unrelated to page 7's rootline.
            [30, 6, 'demo.leak = new page', self::WORKSPACE, 0, VersionState::NEW_PLACEHOLDER->value],
            // Page 7 moved under page 6 in the workspace.
            [31, 6, '', self::WORKSPACE, 7, VersionState::MOVE_POINTER->value],
        ] as [$uid, $pid, $tsConfig, $workspace, $original, $state]) {
            $pages->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'doktype' => 1, 'TSconfig' => $tsConfig,
                't3ver_wsid' => $workspace, 't3ver_oid' => $original, 't3ver_state' => $state,
            ]);
        }
    }

    #[Test]
    public function aMovedPageClimbsItsNewParentsAndANewPageDoesNotLeakIn(): void
    {
        $acting = $this->inWorkspace($this->setUpBackendUser(1));

        // The ambient user is a different one, in the live workspace.
        $this->setUpBackendUser(2);

        self::assertSame(
            ['root' => '1', 'inWorkspace' => '1', 'branch' => 'six'],
            (new PageTsConfigReader())->forPage(7, $acting)['demo.'] ?? null,
        );
    }

    #[Test]
    public function theReaderAgreesWithCoreForTheSameUser(): void
    {
        $acting = $this->inWorkspace($this->setUpBackendUser(1));
        $reader = (new PageTsConfigReader())->forPage(7, $acting);

        // Core, asked with that user as the ambient one.
        $context = $this->get(Context::class);
        self::assertInstanceOf(Context::class, $context);
        $context->setAspect('workspace', new WorkspaceAspect(self::WORKSPACE));
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();

        self::assertSame(BackendUtility::getPagesTSconfig(7), $reader);
    }

    private function inWorkspace(BackendUserAuthentication $user): BackendUserAuthentication
    {
        $user->workspace = self::WORKSPACE;

        return $user;
    }
}

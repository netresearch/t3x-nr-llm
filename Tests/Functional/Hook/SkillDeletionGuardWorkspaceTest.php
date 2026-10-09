<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Hook;

use Netresearch\NrLlm\Hook\SkillDeletionGuardHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A page deleted in a workspace while none of its skills was attached, then
 * published after a skill on it was attached in live: the publish applies
 * the delete through the delete action, which the guard refuses, so the
 * page and the skill stay live (ADR-214 item 3).
 */
#[CoversClass(SkillDeletionGuardHook::class)]
final class SkillDeletionGuardWorkspaceTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'workspaces'];

    private const WORKSPACE = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => self::WORKSPACE, 'pid' => 0, 'title' => 'Draft']);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 10, 'pid' => 0, 'title' => 'Skills', 'doktype' => 254]);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 11, 'pid' => 0, 'title' => 'Other', 'doktype' => 254]);
        $pool->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', ['uid' => 7, 'pid' => 10, 'identifier' => 'guide']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function publishingAPageDeleteDoesNotDeleteAnAttachedSkill(): void
    {
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        // In the workspace, while nothing is attached: a delete placeholder.
        $backendUser->setWorkspace(self::WORKSPACE);
        $this->commands(['pages' => [10 => ['delete' => 1]]]);
        $placeholder = $this->placeholderOf(10);
        self::assertGreaterThan(0, $placeholder, 'the workspace holds a delete placeholder');

        // In live, the skill on the page is attached and the page edited.
        $backendUser->setWorkspace(0);
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('pages')->update('pages', ['title' => 'Edited live'], ['uid' => 10]);
        $pool->getConnectionForTable('tx_nrllm_configuration')->insert('tx_nrllm_configuration', ['uid' => 3, 'pid' => 0]);
        $pool->getConnectionForTable('tx_nrllm_configuration_skill_mm')->insert('tx_nrllm_configuration_skill_mm', ['uid_local' => 3, 'uid_foreign' => 7]);

        $this->commands(['pages' => [10 => ['version' => ['action' => 'publish', 'swapWith' => $placeholder]]]]);

        self::assertSame(0, $this->deleted('tx_nrllm_skill', 7), 'the attached skill stays');
        self::assertSame(0, $this->deleted('pages', 10), 'and the page it is on');
        self::assertSame($placeholder, $this->placeholderOf(10), 'the staged delete stays in the workspace');
        self::assertSame('Edited live', $this->title(10), 'the live page was not swapped with the placeholder');
    }

    private function title(int $uid): string
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $value = $queryBuilder->select('title')->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()->fetchOne();

        return is_string($value) ? $value : '';
    }

    /**
     * EXT:workspaces reads swapWith with an int cast; the guard reads it the
     * same way, so a value with trailing characters is refused too.
     */
    #[Test]
    public function aSwapWithValueWithTrailingCharactersIsRefusedToo(): void
    {
        $placeholder = $this->stageDeleteThenAttach();

        $this->commands(['pages' => [10 => ['version' => ['action' => 'publish', 'swapWith' => $placeholder . 'x']]]]);

        self::assertSame($placeholder, $this->placeholderOf(10), 'the staged delete stays in the workspace');
    }

    /**
     * The auto-publish path sends 'swap' instead of 'publish'.
     */
    #[Test]
    public function swappingAPageDeleteIsRefusedToo(): void
    {
        $placeholder = $this->stageDeleteThenAttach();

        $this->commands(['pages' => [10 => ['version' => ['action' => 'swap', 'swapWith' => $placeholder]]]]);

        self::assertSame(0, $this->deleted('pages', 10));
        self::assertSame($placeholder, $this->placeholderOf(10));
    }

    /**
     * A workspace publishes a page with its dependent records in one
     * request; a refused page delete refuses the whole request, so nothing
     * of it is applied — here, another page's staged change.
     */
    #[Test]
    public function aRefusedPageDeleteRefusesTheWholePublish(): void
    {
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(self::WORKSPACE);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $data = GeneralUtility::makeInstance(DataHandler::class);
        $data->start(['pages' => [11 => ['title' => 'Other, edited']]], []);
        $data->process_datamap();

        $otherVersion = $this->placeholderOf(11);
        self::assertGreaterThan(0, $otherVersion, "the workspace holds the other page's change");

        $placeholder = $this->stageDeleteThenAttach();

        $this->commands(['pages' => [
            10 => ['version' => ['action' => 'publish', 'swapWith' => $placeholder]],
            11 => ['version' => ['action' => 'publish', 'swapWith' => $otherVersion]],
        ]]);

        self::assertSame(0, $this->deleted('pages', 10));
        self::assertSame('Other', $this->title(11), 'the other change in the same publish was not applied');

        $this->commands(['pages' => [11 => ['version' => ['action' => 'publish', 'swapWith' => $otherVersion]]]]);

        self::assertSame('Other, edited', $this->title(11), 'on its own, it publishes');
    }

    private function stageDeleteThenAttach(): int
    {
        $backendUser     = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $backendUser->setWorkspace(self::WORKSPACE);
        $this->commands(['pages' => [10 => ['delete' => 1]]]);
        $placeholder = $this->placeholderOf(10);
        self::assertGreaterThan(0, $placeholder);

        $backendUser->setWorkspace(0);
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('tx_nrllm_configuration')->insert('tx_nrllm_configuration', ['uid' => 3, 'pid' => 0]);
        $pool->getConnectionForTable('tx_nrllm_configuration_skill_mm')->insert('tx_nrllm_configuration_skill_mm', ['uid_local' => 3, 'uid_foreign' => 7]);

        return $placeholder;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $commands
     */
    private function commands(array $commands): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $commands);
        $dataHandler->process_cmdmap();
    }

    private function placeholderOf(int $liveUid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $uid = $queryBuilder->select('uid')->from('pages')
            ->where(
                $queryBuilder->expr()->eq('t3ver_oid', $liveUid),
                $queryBuilder->expr()->eq('t3ver_wsid', self::WORKSPACE),
            )
            ->executeQuery()->fetchOne();

        return is_numeric($uid) ? (int)$uid : 0;
    }

    private function deleted(string $table, int $uid): int
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $value = $queryBuilder->select('deleted')->from($table)
            ->where($queryBuilder->expr()->eq('uid', $uid))
            ->executeQuery()->fetchOne();

        return is_numeric($value) ? (int)$value : -1;
    }
}

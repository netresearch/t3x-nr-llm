<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Permissions;

use Netresearch\NrLlm\Controller\Backend\ConfigurationController;
use Netresearch\NrLlm\Controller\Backend\ModelController;
use Netresearch\NrLlm\Controller\Backend\ProviderController;
use Netresearch\NrLlm\Controller\Backend\SkillApprovalController;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use TYPO3\CMS\Backend\Form\Exception\AccessDeniedException;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseUserPermissionCheck;
use TYPO3\CMS\Backend\Form\FormDataProvider\TcaColumnsOverrides;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The management permission matrix uses real core user groups, page grants,
 * FormEngine permission checks and DataHandler writes. It does not render a browser.
 */
#[CoversNothing]
final class ManagementPermissionMatrixTest extends AbstractFunctionalTestCase
{
    private const PAGE = 100;

    private const GROUP = 10;

    private const RECORD = 42;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
    }

    /**
     * @return iterable<string, array{string, int, bool, bool, bool}>
     */
    public static function recordPermissions(): iterable
    {
        foreach ([
            'provider',
            'model',
            'configuration',
            'task',
            'promptsnippet',
            'glossary',
            'term_list',
            'skill',
        ] as $kind) {
            $table = 'tx_nrllm_' . $kind;
            yield $kind . ' root with table grant' => [$table, 0, true, true, false];
            yield $kind . ' mounted page with grants' => [$table, self::PAGE, true, true, true];
            yield $kind . ' mounted page without table grant' => [$table, self::PAGE, false, true, false];
            yield $kind . ' mounted page without page grant' => [$table, self::PAGE, true, false, false];
        }
    }

    #[Test]
    #[DataProvider('recordPermissions')]
    public function nativeFormAndDataHandlerEnforceTheSameRecordGrants(
        string $table,
        int $pid,
        bool $tableGrant,
        bool $pageGrant,
        bool $mayWrite,
    ): void {
        $this->prepareEditor($table, $tableGrant, $pageGrant);
        $row = ['uid' => self::RECORD, 'pid' => $pid, 'name' => 'Before'];
        if ($table === 'tx_nrllm_skill') {
            $this
                ->pool()
                ->getConnectionForTable('tx_nrllm_skill_source')
                ->insert(
                    'tx_nrllm_skill_source',
                    [
                        'uid'   => 10,
                        'pid'   => self::PAGE,
                        'title' => 'Backend authoring',
                        'type'  => 'backend',
                    ],
                );
            $row['source']     = 10;
            $row['identifier'] = 'matrix:skill';
        }

        $this->pool()->getConnectionForTable($table)->insert($table, $row);
        $page = $this
            ->pool()
            ->getConnectionForTable('pages')
            ->select(['*'], 'pages', ['uid' => self::PAGE])
            ->fetchAssociative();
        self::assertIsArray($page);
        $stored = $this
            ->pool()
            ->getConnectionForTable($table)
            ->select(['*'], $table, ['uid' => self::RECORD])
            ->fetchAssociative();
        self::assertIsArray($stored);
        $formAllowed = true;
        try {
            $this
                ->getService(DatabaseUserPermissionCheck::class)
                ->addData(
                    [
                        'tableName'     => $table,
                        'command'       => 'edit',
                        'databaseRow'   => $stored,
                        'parentPageRow' => $pid === 0 ? null : $page,
                        'processedTca'  => $this->tcaOf($table),
                        'tcaSchemata'   => $this->getService(TcaSchemaFactory::class)->all(),
                    ],
                );
        } catch (AccessDeniedException) {
            $formAllowed = false;
        }

        self::assertSame(
            $mayWrite,
            $formAllowed,
            'Native FormEngine edit permission',
        );

        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([$table => [self::RECORD => ['name' => 'After']]], []);
        $handler->process_datamap();

        $name = $this
            ->pool()
            ->getConnectionForTable($table)
            ->select(['name'], $table, ['uid' => self::RECORD])
            ->fetchOne();
        self::assertSame(
            $mayWrite ? 'After' : 'Before',
            $name,
            'Persisted DataHandler result',
        );
        if ($mayWrite) {
            self::assertSame([], $handler->errorLog);
        } else {
            self::assertNotSame(
                [],
                $handler->errorLog,
                'Denied writes must report refusal.',
            );
        }
    }

    #[Test]
    public function backendSkillAuthoringEditsBodyWhileSyncedFormsStayReadOnly(): void
    {
        $table = 'tx_nrllm_skill';
        $this->prepareEditor($table, true, true);
        $this
            ->pool()
            ->getConnectionForTable('tx_nrllm_skill_source')
            ->insert(
                'tx_nrllm_skill_source',
                [
                    'uid'   => 10,
                    'pid'   => self::PAGE,
                    'title' => 'Backend authoring',
                    'type'  => 'backend',
                ],
            );
        $this
            ->pool()
            ->getConnectionForTable($table)
            ->insert(
                $table,
                [
                    'uid'        => self::RECORD,
                    'pid'        => self::PAGE,
                    'source'     => 10,
                    'identifier' => 'matrix:authored',
                    'name'       => 'House skill',
                    'body'       => 'Before',
                ],
            );
        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start(
            [
                $table => [self::RECORD => ['body' => 'Edited by the authorized author']],
            ],
            [],
        );
        $handler->process_datamap();
        self::assertSame([], $handler->errorLog);
        self::assertSame(
            'Edited by the authorized author',
            $this
                ->pool()
                ->getConnectionForTable($table)
                ->select(['body'], $table, ['uid' => self::RECORD])
                ->fetchOne(),
        );
        $overrides = $this->getService(TcaColumnsOverrides::class);
        $authored  = $overrides->addData(
            [
                'processedTca'    => $this->tcaOf($table),
                'recordTypeValue' => 'backend',
                'command'         => 'edit',
            ],
        );
        $synced = $overrides->addData(
            [
                'processedTca'    => $this->tcaOf($table),
                'recordTypeValue' => 'repo',
                'command'         => 'edit',
            ],
        );
        self::assertFalse(
            $this->bodyIsReadOnly($authored),
        );
        self::assertTrue(
            $this->bodyIsReadOnly($synced),
        );
    }

    #[Test]
    public function recordGrantsDoNotAuthorizeAdminOnlyAjaxOrSkillApproval(): void
    {
        $this->prepareEditor(
            'tx_nrllm_provider,tx_nrllm_model,tx_nrllm_configuration,tx_nrllm_skill',
            true,
            true,
        );
        $request = (new ServerRequest('https://typo3-testing.local/typo3/ajax', 'POST'))->withParsedBody(
            ['uid' => self::RECORD],
        );
        foreach ([
            ProviderController::class,
            ModelController::class,
            ConfigurationController::class,
        ] as $class) {
            $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            self::assertSame(
                403,
                $controller->toggleActiveAction($request)->getStatusCode(),
            );
        }

        $approvals = (new ReflectionClass(SkillApprovalController::class))->newInstanceWithoutConstructor();
        self::assertSame(
            403,
            $approvals
                ->approveVersionAction(self::RECORD, 'untrusted-digest')
                ->getStatusCode(),
        );
        self::assertSame(
            403,
            $approvals
                ->revokeVersionAction(self::RECORD, 'untrusted-digest')
                ->getStatusCode(),
        );
    }

    private function prepareEditor(
        string $tables,
        bool $tableGrant,
        bool $pageGrant,
    ): void {
        $this
            ->pool()
            ->getConnectionForTable('be_groups')
            ->insert(
                'be_groups',
                [
                    'uid'            => self::GROUP,
                    'title'          => 'Management editors',
                    'db_mountpoints' => (string) self::PAGE,
                    'tables_select'  => $tables,
                    'tables_modify'  => $tableGrant ? $tables : '',
                ],
            );
        $this
            ->pool()
            ->getConnectionForTable('be_users')
            ->update('be_users', ['usergroup' => (string) self::GROUP], ['uid' => 2]);
        $this
            ->pool()
            ->getConnectionForTable('pages')
            ->insert(
                'pages',
                [
                    'uid'             => self::PAGE,
                    'pid'             => 0,
                    'title'           => 'Management storage',
                    'doktype'         => 254,
                    'perms_groupid'   => self::GROUP,
                    'perms_group'     => $pageGrant ? 31 : 0,
                    'perms_everybody' => 0,
                ],
            );
        $editor = $this->setUpBackendUser(2);
        self::assertFalse($editor->isAdmin());
        $GLOBALS['LANG'] = $this
            ->getService(LanguageServiceFactory::class)
            ->createFromUserPreferences($editor);
    }

    private function pool(): ConnectionPool
    {
        return $this->getService(ConnectionPool::class);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function tcaOf(string $table): array
    {
        $all = $GLOBALS['TCA'] ?? null;
        self::assertIsArray($all);
        self::assertArrayHasKey($table, $all);
        $configuration = $all[$table];
        self::assertIsArray($configuration);

        return $configuration;
    }

    /**
     * @param array<array-key, mixed> $formData
     */
    private function bodyIsReadOnly(array $formData): bool
    {
        $value = $formData;
        foreach (['processedTca', 'columns', 'body', 'config', 'readOnly'] as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        self::assertIsBool($value);

        return $value;
    }
}

<?php

declare(strict_types=1);

/**
 * This file is part of the package netresearch/nr-llm.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\UpdateFalAssetMetaTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The `copyright` field of `update_fal_asset_meta` (ADR-194), which exists
 * only with EXT:filemetadata loaded — this class loads it; the unit tests
 * cover the installation without it, where the field is neither offered nor
 * accepted.
 *
 * The storage recipe is the one {@see UpdateFalAssetMetaToolFileMountTest}
 * uses: the storage ROW is inserted before the backend request exists, so the
 * ResourceStorage object is first built inside it.
 */
#[CoversClass(UpdateFalAssetMetaTool::class)]
final class UpdateFalAssetMetaToolCopyrightTest extends AbstractFunctionalTestCase
{
    private const STORAGE_CONFIGURATION = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?>
<T3FlexForms>
    <data>
        <sheet index="sDEF">
            <language index="lDEF">
                <field index="basePath"><value index="vDEF">fileadmin/</value></field>
                <field index="pathType"><value index="vDEF">relative</value></field>
                <field index="caseSensitive"><value index="vDEF">1</value></field>
            </language>
        </sheet>
    </data>
</T3FlexForms>';

    private const FILE = 10;

    private const METADATA = 100;

    private const STORED_TITLE = 'Stored title';

    private const STORED_COPYRIGHT = 'Stored copyright';

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = [
        'extbase',
        'fluid',
        'filemetadata',
    ];

    private ConnectionPool $connectionPool;

    private UpdateFalAssetMetaTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $this->connectionPool = $connectionPool;

        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/docs');
        file_put_contents($this->instancePath . '/fileadmin/docs/manual.txt', 'The manual');

        $storageConnection = $this->connectionPool->getConnectionForTable('sys_file_storage');
        self::assertInstanceOf(Connection::class, $storageConnection);
        $storageConnection->insert('sys_file_storage', [
            'uid' => 1, 'pid' => 0, 'name' => 'Main storage', 'driver' => 'Local',
            'configuration' => self::STORAGE_CONFIGURATION,
            'is_online' => 1, 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1,
        ]);

        $this->connectionPool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid'             => self::FILE,
            'pid'             => 0,
            'storage'         => 1,
            'identifier'      => '/docs/manual.txt',
            'identifier_hash' => 'fixture-' . self::FILE,
            'folder_hash'     => 'fixture-folder-' . self::FILE,
            'name'            => 'manual.txt',
            'extension'       => 'txt',
            'mime_type'       => 'text/plain',
            'size'            => 10,
            'type'            => 1,
            'missing'         => 0,
        ]);
        $this->connectionPool->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', [
            'uid'              => self::METADATA,
            'pid'              => 0,
            'file'             => self::FILE,
            'sys_language_uid' => 0,
            'title'            => self::STORED_TITLE,
            'description'      => 'Stored description',
            'alternative'      => 'Stored alt',
            'copyright'        => self::STORED_COPYRIGHT,
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $tool = $registry->get('update_fal_asset_meta');
        self::assertInstanceOf(UpdateFalAssetMetaTool::class, $tool);
        $this->tool = $tool;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theSpecOffersTheCopyrightWhereExtFilemetadataIsLoaded(): void
    {
        $spec       = $this->tool->getSpec();
        $properties = $spec->parameters['properties'] ?? null;

        self::assertIsArray($properties);
        self::assertArrayHasKey('copyright', $properties);
        self::assertStringContainsString('"copyright"', $spec->description);
    }

    #[Test]
    public function theCopyrightIsWrittenAndTheOtherFieldsKeepTheirValues(): void
    {
        $admin = $this->loginAdminInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE, 'copyright' => '© 2026 Netresearch DTT GmbH'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('copyright', $result->content);
        self::assertSame('© 2026 Netresearch DTT GmbH', $this->stored('copyright'));
        self::assertSame(self::STORED_TITLE, $this->stored('title'));
    }

    #[Test]
    public function anEmptyCopyrightClearsTheField(): void
    {
        $admin = $this->loginAdminInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE, 'copyright' => ''],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('', $this->stored('copyright'));
    }

    #[Test]
    public function aCallWithoutTheCopyrightLeavesItStored(): void
    {
        $admin = $this->loginAdminInBackendRequest();

        $result = $this->tool->execute(
            ['uid' => self::FILE, 'title' => 'Only the title'],
            ToolExecutionContext::fromBackendUser($admin),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(self::STORED_COPYRIGHT, $this->stored('copyright'));
        self::assertStringNotContainsString('copyright', $result->content);
    }

    #[Test]
    public function thePreviewNamesTheCopyrightWithItsStoredAndItsProposedValue(): void
    {
        $admin = $this->loginAdminInBackendRequest();

        $card = implode("\n", $this->tool->previewCall(
            ['uid' => self::FILE, 'copyright' => 'A new notice'],
            ToolExecutionContext::fromBackendUser($admin),
        ));

        self::assertStringContainsString('copyright', $card);
        self::assertStringContainsString(self::STORED_COPYRIGHT, $card);
        self::assertStringContainsString('A new notice', $card);
        self::assertStringNotContainsString('description', $card);
    }

    private function loginAdminInBackendRequest(): BackendUserAuthentication
    {
        $user = $this->setUpBackendUser(1);
        // The core StoragePermissionsAspect only attaches mounts/permissions
        // when the storage object is created inside a BACKEND request.
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://typo3-testing.local/typo3/'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $user;
    }

    private function stored(string $field): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select($field)
            ->from('sys_file_metadata')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter(self::METADATA, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        $stored = $row[$field] ?? null;
        self::assertIsString($stored);

        return $stored;
    }
}

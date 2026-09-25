<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Domain;

use Netresearch\NrLlm\Domain\Model\Glossary;
use Netresearch\NrLlm\Domain\Repository\GlossaryRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The glossary record as an editor stores it through FormEngine's write path,
 * DataHandler, and as the module reads it back (ADR-208).
 *
 * No CoversClass for the Glossary model: Domain/Model is excluded from
 * coverage.
 */
#[CoversClass(GlossaryRepository::class)]
final class GlossaryStorageTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('BeUsers.csv');
        $backendUser = $this->setUpBackendUser(1); // uid 1 is an admin (admin=1)
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function dataHandlerStoresAGlossaryAtTheRootLevel(): void
    {
        $uid = $this->createThroughDataHandler([
            'name' => 'Shop DE-EN',
            'site_identifier' => 'main',
            'source_language' => 'DE',
            'target_language' => 'en',
            'entries' => "Warenkorb = shopping cart\nKundenkonto\tcustomer account",
        ]);

        $row = $this->row($uid);
        self::assertSame(0, (int)$row['pid']);
        self::assertSame('Shop DE-EN', $row['name']);
        self::assertSame('main', $row['site_identifier']);
        // eval lower: the lookup compares lowercase base codes.
        self::assertSame('de', $row['source_language']);
        self::assertSame('en', $row['target_language']);
        self::assertSame("Warenkorb = shopping cart\nKundenkonto\tcustomer account", $row['entries']);
    }

    #[Test]
    public function theDeepLBookkeepingColumnsCannotBeWrittenThroughTheForm(): void
    {
        $uid = $this->createThroughDataHandler([
            'name' => 'Shop DE-EN',
            'site_identifier' => 'main',
            'source_language' => 'de',
            'target_language' => 'en',
            'entries' => 'Warenkorb = shopping cart',
            'deepl_glossary_id' => 'gls_test_forged',
            'deepl_entries_hash' => 'forged',
        ]);

        $row = $this->row($uid);
        self::assertSame('', $row['deepl_glossary_id']);
        self::assertSame('', $row['deepl_entries_hash']);
    }

    #[Test]
    public function theRepositoryReadsTheRecordWithItsEffectiveTermCount(): void
    {
        $this->importFixture('Glossaries.csv');

        $repository = $this->getService(GlossaryRepository::class);
        $glossary = $repository->findByUid(1);

        self::assertInstanceOf(Glossary::class, $glossary);
        self::assertSame('main', $glossary->getSiteIdentifier());
        self::assertSame('de', $glossary->getSourceLanguage());
        self::assertSame('en', $glossary->getTargetLanguage());
        self::assertSame(2, $glossary->getTermCount());
        // Seven of eight fixture rows are not deleted; the hidden ones count.
        self::assertSame(7, $repository->countAllRecords());
    }

    /**
     * @param array<string, string> $fields
     */
    private function createThroughDataHandler(array $fields): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tx_nrllm_glossary' => ['NEW1' => ['pid' => 0] + $fields]], []);
        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog, 'DataHandler reported: ' . implode(' | ', $dataHandler->errorLog));
        $uid = $dataHandler->substNEWwithIDs['NEW1'] ?? null;
        self::assertIsInt($uid);

        return $uid;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $uid): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_glossary')
            ->select(['*'], 'tx_nrllm_glossary', ['uid' => $uid])
            ->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }
}

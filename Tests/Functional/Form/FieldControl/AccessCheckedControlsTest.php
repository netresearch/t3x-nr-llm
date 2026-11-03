<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Form\FieldControl;

use Netresearch\NrLlm\Form\FieldControl\AccessCheckedAddRecord;
use Netresearch\NrLlm\Form\FieldControl\AccessibleName;
use Netresearch\NrLlm\Form\FieldControl\ForeignTable;
use Netresearch\NrLlm\Form\FieldControl\ManageRecordsControl;
use Netresearch\NrLlm\Form\FieldControl\RecordControlAccess;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * The create and manage controls next to a record select (ADR-255): rendered
 * for a user who can use them, absent for one who cannot, and named for
 * assistive technology.
 */
#[CoversClass(AccessCheckedAddRecord::class)]
#[CoversClass(ManageRecordsControl::class)]
#[CoversClass(AccessibleName::class)]
#[CoversClass(ForeignTable::class)]
final class AccessCheckedControlsTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/RecordControlAccess.csv');
    }

    private function access(): RecordControlAccess
    {
        return new RecordControlAccess($this->get(ModuleProvider::class));
    }

    private function loginAs(int $uid): void
    {
        $user            = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function addRecord(array $options): array
    {
        $control = new AccessCheckedAddRecord($this->get(UriBuilder::class), $this->access());
        $control->setData([
            'tableName'      => 'tx_nrllm_task',
            'fieldName'      => 'configuration_uid',
            'databaseRow'    => ['uid' => 7],
            'effectivePid'   => 1,
            'returnUrl'      => '/typo3/main',
            'renderData'     => ['fieldControlOptions' => $options],
            'parameterArray' => [
                'itemFormElName' => 'data[tx_nrllm_task][7][configuration_uid]',
                'fieldConf'      => ['config' => ['type' => 'select', 'foreign_table' => 'tx_nrllm_configuration']],
            ],
        ]);

        return $control->render();
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function manage(array $options): array
    {
        $control = new ManageRecordsControl($this->get(UriBuilder::class), $this->access());
        $control->setData(['renderData' => ['fieldControlOptions' => $options]]);

        return $control->render();
    }

    #[Test]
    public function createIsOfferedWhereTheUserMayCreateAndNamedForScreenReaders(): void
    {
        $this->loginAs(5);

        $result = $this->addRecord(['pid' => '1', 'setValue' => 'set']);

        self::assertSame('actions-plus', $result['iconIdentifier'] ?? null);
        $link = $result['linkAttributes'] ?? null;
        self::assertIsArray($link);
        self::assertSame('Create new', $link['aria-label'] ?? null);
        self::assertIsString($link['href'] ?? null);
        self::assertStringContainsString('tx_nrllm_configuration', urldecode($link['href']));
    }

    #[Test]
    public function createIsAbsentWhereTheUserMayNotCreate(): void
    {
        $this->loginAs(5);
        self::assertSame([], $this->addRecord(['pid' => '2']), 'page only shows');
        self::assertSame([], $this->addRecord(['pid' => '0']), 'root level');
        self::assertSame([], $this->addRecord(['pid' => '1', 'table' => 'tx_nrllm_model']), 'table not modifiable');

        $this->loginAs(6);
        self::assertSame([], $this->addRecord(['pid' => '1']), 'no rights at all');
    }

    #[Test]
    public function aMarkerPidIsCheckedAgainstThePageBeingEdited(): void
    {
        $this->loginAs(5);

        // ###PAGE_TSCONFIG_ID### is resolved later by the add wizard; until
        // then the page being edited (effectivePid 1, editable) decides.
        self::assertNotSame([], $this->addRecord(['pid' => '###PAGE_TSCONFIG_ID###']));
    }

    #[Test]
    public function manageOpensTheModuleForAnAdministrator(): void
    {
        $this->loginAs(1);

        $result = $this->manage(['module' => 'nrllm_configurations', 'title' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:choiceHelp.manage.configurations']);

        $link = $result['linkAttributes'] ?? null;
        self::assertIsArray($link);
        self::assertIsString($link['href'] ?? null);
        self::assertStringContainsString('/module/nrllm/configurations', $link['href']);
        self::assertSame('Show and manage all configurations', $link['aria-label'] ?? null);
    }

    #[Test]
    public function manageIsAbsentWithoutModuleAccessOrModule(): void
    {
        $this->loginAs(5);
        self::assertSame([], $this->manage(['module' => 'nrllm_configurations']));

        $this->loginAs(1);
        self::assertSame([], $this->manage(['module' => 'no_such_module']));
        self::assertSame([], $this->manage([]));
    }
}

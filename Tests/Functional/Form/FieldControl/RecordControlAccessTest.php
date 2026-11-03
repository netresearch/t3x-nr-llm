<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Form\FieldControl;

use Netresearch\NrLlm\Form\FieldControl\RecordControlAccess;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleProvider;

/**
 * Who sees the create and manage controls next to a record select
 * (ADR-255). A control the user cannot use is not rendered.
 */
#[CoversClass(RecordControlAccess::class)]
final class RecordControlAccessTest extends AbstractFunctionalTestCase
{
    private RecordControlAccess $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/RecordControlAccess.csv');
        $this->subject = new RecordControlAccess($this->get(ModuleProvider::class));
    }

    #[Test]
    public function anAdministratorMayUseEveryControl(): void
    {
        $this->setUpBackendUser(1);

        self::assertTrue($this->subject->mayCreate('tx_nrllm_configuration', 0));
        self::assertTrue($this->subject->mayCreate('tx_nrllm_model', 2));
        self::assertTrue($this->subject->mayManage('nrllm_configurations'));
    }

    #[Test]
    public function anEditorCreatesOnlyWhereTableAndPageAllowIt(): void
    {
        $this->setUpBackendUser(5);

        self::assertTrue($this->subject->mayCreate('tx_nrllm_configuration', 1), 'table modifiable, page editable');
        self::assertFalse($this->subject->mayCreate('tx_nrllm_configuration', 2), 'page only shows');
        self::assertFalse($this->subject->mayCreate('tx_nrllm_configuration', 0), 'root level is for administrators');
        self::assertFalse($this->subject->mayCreate('tx_nrllm_model', 1), 'table not modifiable');
        self::assertFalse($this->subject->mayCreate('no_such_table', 1), 'unknown table');
    }

    #[Test]
    public function theManageControlFollowsModuleAccess(): void
    {
        // The nr-llm record modules are for administrators.
        $this->setUpBackendUser(5);
        self::assertFalse($this->subject->mayManage('nrllm_configurations'));

        $this->setUpBackendUser(1);
        self::assertFalse($this->subject->mayManage('no_such_module'));
    }

    #[Test]
    public function aUserWithoutRightsSeesNoControl(): void
    {
        $this->setUpBackendUser(6);

        self::assertFalse($this->subject->mayCreate('tx_nrllm_configuration', 1));
        self::assertFalse($this->subject->mayManage('nrllm_configurations'));
    }

    #[Test]
    public function withoutABackendUserNothingIsAllowed(): void
    {
        unset($GLOBALS['BE_USER']);

        self::assertFalse($this->subject->mayCreate('tx_nrllm_configuration', 1));
        self::assertFalse($this->subject->mayManage('nrllm_configurations'));
    }
}

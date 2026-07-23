<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Updates;

use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Updates\DataClassEnforcementDefaultUpdateWizard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(DataClassEnforcementDefaultUpdateWizard::class)]
final class DataClassEnforcementDefaultUpdateWizardTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_provider';

    private mixed $originalConfVars = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = $this->originalConfVars;
        parent::tearDown();
    }

    #[Test]
    public function pinsObserveForAnExistingInstallThatNeverChoseAMode(): void
    {
        $this->addProvider('openai-1');
        // Raw stored config carries no enforcement value: relied on the old default.
        $this->storeNrLlmConfig(['tools' => []]);

        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['tools' => []]);
        $extensionConfiguration->expects(self::once())->method('set')->with(
            'nr_llm',
            self::callback(static fn(mixed $c): bool => is_array($c)
                && is_array($c['tools'] ?? null)
                && ($c['tools']['dataClassEnforcement'] ?? null) === 'observe'),
        );

        $wizard = new DataClassEnforcementDefaultUpdateWizard($this->getConnectionPool(), $extensionConfiguration);

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());
    }

    #[Test]
    public function doesNotFireForAFreshInstallWithoutProviders(): void
    {
        // No provider rows -> a fresh install keeps the new enforce default.
        $this->storeNrLlmConfig(['tools' => []]);

        $wizard = new DataClassEnforcementDefaultUpdateWizard(
            $this->getConnectionPool(),
            $this->createMock(ExtensionConfiguration::class),
        );

        self::assertFalse($wizard->updateNecessary());
    }

    #[Test]
    public function respectsAnExplicitEnforceChoice(): void
    {
        $this->addProvider('openai-2');
        $this->storeNrLlmConfig(['tools' => ['dataClassEnforcement' => 'enforce']]);

        $wizard = new DataClassEnforcementDefaultUpdateWizard(
            $this->getConnectionPool(),
            $this->createMock(ExtensionConfiguration::class),
        );

        self::assertFalse($wizard->updateNecessary(), 'an explicit enforce is left untouched');
    }

    #[Test]
    public function respectsAnExplicitObserveChoice(): void
    {
        $this->addProvider('openai-3');
        $this->storeNrLlmConfig(['tools' => ['dataClassEnforcement' => 'observe']]);

        $wizard = new DataClassEnforcementDefaultUpdateWizard(
            $this->getConnectionPool(),
            $this->createMock(ExtensionConfiguration::class),
        );

        self::assertFalse($wizard->updateNecessary(), 'already observe -> nothing to do');
    }

    private function addProvider(string $identifier): void
    {
        $this->getConnectionPool()->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'pid'          => 0,
            'identifier'   => $identifier,
            'name'         => 'Provider ' . $identifier,
            'adapter_type' => 'openai',
        ]);
    }

    /**
     * Write the raw stored nr_llm extension configuration, narrowing the untyped
     * $GLOBALS shape step by step so the writes stay PHPStan-clean.
     *
     * @param array<string, mixed> $nrLlm
     */
    private function storeNrLlmConfig(array $nrLlm): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!is_array($confVars)) {
            $confVars = [];
        }
        $extensions = $confVars['EXTENSIONS'] ?? [];
        if (!is_array($extensions)) {
            $extensions = [];
        }
        $extensions['nr_llm']   = $nrLlm;
        $confVars['EXTENSIONS'] = $extensions;
        $GLOBALS['TYPO3_CONF_VARS'] = $confVars;
    }
}

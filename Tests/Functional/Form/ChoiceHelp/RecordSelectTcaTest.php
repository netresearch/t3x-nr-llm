<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Form\ChoiceHelp;

use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Which record selects carry the help text and which controls (ADR-255), as
 * the loaded TCA has them - so a TCA edit that drops one is a test failure,
 * not a silent regression.
 */
final class RecordSelectTcaTest extends AbstractFunctionalTestCase
{
    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function selects(): iterable
    {
        yield 'configuration model' => ['tx_nrllm_configuration', 'model_uid', 'nrllm_models', true];
        yield 'configuration skills' => ['tx_nrllm_configuration', 'skills', 'nrllm_skills', false];
        yield 'configuration skills on demand' => ['tx_nrllm_configuration', 'skills_on_demand', 'nrllm_skills', false];
        yield 'task configuration' => ['tx_nrllm_task', 'configuration_uid', 'nrllm_configurations', true];
        yield 'task skills' => ['tx_nrllm_task', 'skills', 'nrllm_skills', false];
        yield 'model provider' => ['tx_nrllm_model', 'provider_uid', 'nrllm_providers', false];
        yield 'term list configurations' => ['tx_nrllm_term_list', 'configurations', 'nrllm_configurations', false];
    }

    #[Test]
    #[DataProvider('selects')]
    public function theSelectCarriesTheHelpAndItsControls(string $table, string $field, string $module, bool $create): void
    {
        $config = $this->path($GLOBALS['TCA'] ?? null, $table, 'columns', $field, 'config');
        self::assertIsArray($config);

        self::assertSame('nrllmChoiceHelp', $this->path($config, 'fieldWizard', 'nrllmChoiceHelp', 'renderType'));
        self::assertSame('nrllmManageRecords', $this->path($config, 'fieldControl', 'listModule', 'renderType'));
        self::assertFalse($this->path($config, 'fieldControl', 'listModule', 'disabled'));
        self::assertSame($module, $this->path($config, 'fieldControl', 'listModule', 'options', 'module'));

        $controls = $this->path($config, 'fieldControl');
        self::assertIsArray($controls);
        if ($create) {
            self::assertSame('nrllmAddRecord', $this->path($controls, 'addRecord', 'renderType'));
            self::assertFalse($this->path($controls, 'addRecord', 'disabled'));
        } else {
            self::assertArrayNotHasKey('addRecord', $controls);
        }

        // Core's editPopup works only with a multiple select; none is configured.
        self::assertArrayNotHasKey('editPopup', $controls);
    }

    private function path(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}

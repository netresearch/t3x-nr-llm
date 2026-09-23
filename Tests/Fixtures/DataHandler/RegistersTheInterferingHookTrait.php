<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

/**
 * Registers {@see InterferesWithAnUpdateHook} for one test and removes it again,
 * switches reset, in the test's tearDown.
 */
trait RegistersTheInterferingHookTrait
{
    private function registerInterferingHook(): void
    {
        InterferesWithAnUpdateHook::reset();
        $hooks   = $this->processDatamapHooks();
        $hooks[] = InterferesWithAnUpdateHook::class;
        $this->storeProcessDatamapHooks($hooks);
    }

    private function unregisterInterferingHook(): void
    {
        InterferesWithAnUpdateHook::reset();
        $this->storeProcessDatamapHooks(array_values(array_filter(
            $this->processDatamapHooks(),
            static fn(mixed $className): bool => $className !== InterferesWithAnUpdateHook::class,
        )));
    }

    /**
     * @return list<mixed>
     */
    private function processDatamapHooks(): array
    {
        $confVars  = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $scOptions = is_array($confVars['SC_OPTIONS'] ?? null) ? $confVars['SC_OPTIONS'] : [];
        $tcemain   = is_array($scOptions['t3lib/class.t3lib_tcemain.php'] ?? null) ? $scOptions['t3lib/class.t3lib_tcemain.php'] : [];
        $hooks     = is_array($tcemain['processDatamapClass'] ?? null) ? $tcemain['processDatamapClass'] : [];

        return array_values($hooks);
    }

    /**
     * @param list<mixed> $hooks
     */
    private function storeProcessDatamapHooks(array $hooks): void
    {
        $confVars  = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $scOptions = is_array($confVars['SC_OPTIONS'] ?? null) ? $confVars['SC_OPTIONS'] : [];
        $tcemain   = is_array($scOptions['t3lib/class.t3lib_tcemain.php'] ?? null) ? $scOptions['t3lib/class.t3lib_tcemain.php'] : [];

        $tcemain['processDatamapClass']                 = $hooks;
        $scOptions['t3lib/class.t3lib_tcemain.php']     = $tcemain;
        $confVars['SC_OPTIONS']                         = $scOptions;
        $GLOBALS['TYPO3_CONF_VARS']                     = $confVars;
    }
}

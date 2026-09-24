<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\DataHandler;

use Netresearch\NrLlm\Service\Tool\Builtin\ToolDataHandler;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Sets up the condition a hook failure needs on a live instance, for one test,
 * and removes it again in the test's tearDown.
 *
 * {@see self::failInTheNextWrite()} registers {@see FailsLikeAFlashMessageHook}
 * for data and command runs and makes the AMBIENT backend user one without a
 * session, the way the agent worker has it: loaded by uid, groups fetched,
 * nothing else — as {@see \Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver}
 * builds it. Call it after `setUpBackendUser()`, which sets an ambient user
 * WITH a session; the tool keeps the user its context carries.
 */
trait RegistersTheFailingHookTrait
{
    private const FAILING_HOOK_LISTS = ['processDatamapClass', 'processCmdmapClass'];

    private function failInTheNextWrite(string $failAt): void
    {
        foreach (self::FAILING_HOOK_LISTS as $list) {
            $this->registerHook($list, FailsLikeAFlashMessageHook::class);
        }

        FailsLikeAFlashMessageHook::$failAt = $failAt;
        $this->useASessionlessAmbientUser(1);
    }

    private function unregisterFailingHook(): void
    {
        FailsLikeAFlashMessageHook::reset();
        // A failure the test did not take stays in the process otherwise.
        ToolDataHandler::takeFailures();
        foreach (self::FAILING_HOOK_LISTS as $list) {
            $this->unregisterHook($list, FailsLikeAFlashMessageHook::class);
        }
    }

    private function useASessionlessAmbientUser(int $uid): BackendUserAuthentication
    {
        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $user->setBeUserByUid($uid);
        $user->fetchGroupData();
        $GLOBALS['BE_USER'] = $user;

        return $user;
    }

    private function registerHook(string $list, string $hook): void
    {
        $hooks = $this->hooks($list);
        if (!in_array($hook, $hooks, true)) {
            $this->storeHooks($list, [...$hooks, $hook]);
        }
    }

    private function unregisterHook(string $list, string $hook): void
    {
        $this->storeHooks($list, array_values(array_filter(
            $this->hooks($list),
            static fn(mixed $registered): bool => $registered !== $hook,
        )));
    }

    /**
     * @return list<mixed>
     */
    private function hooks(string $list): array
    {
        $confVars  = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $scOptions = is_array($confVars['SC_OPTIONS'] ?? null) ? $confVars['SC_OPTIONS'] : [];
        $tcemain   = is_array($scOptions['t3lib/class.t3lib_tcemain.php'] ?? null) ? $scOptions['t3lib/class.t3lib_tcemain.php'] : [];
        $hooks     = is_array($tcemain[$list] ?? null) ? $tcemain[$list] : [];

        return array_values($hooks);
    }

    /**
     * @param list<mixed> $hooks
     */
    private function storeHooks(string $list, array $hooks): void
    {
        $confVars  = is_array($GLOBALS['TYPO3_CONF_VARS'] ?? null) ? $GLOBALS['TYPO3_CONF_VARS'] : [];
        $scOptions = is_array($confVars['SC_OPTIONS'] ?? null) ? $confVars['SC_OPTIONS'] : [];
        $tcemain   = is_array($scOptions['t3lib/class.t3lib_tcemain.php'] ?? null) ? $scOptions['t3lib/class.t3lib_tcemain.php'] : [];

        $tcemain[$list]                             = $hooks;
        $scOptions['t3lib/class.t3lib_tcemain.php'] = $tcemain;
        $confVars['SC_OPTIONS']                     = $scOptions;
        $GLOBALS['TYPO3_CONF_VARS']                 = $confVars;
    }
}

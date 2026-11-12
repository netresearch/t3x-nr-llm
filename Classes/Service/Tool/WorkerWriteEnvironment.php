<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

use Closure;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

use const PHP_SAPI;

/**
 * The backend environment a writing tool needs when a worker carries out its
 * call (ADR-281, amending ADR-135 and ADR-237).
 *
 * The writers refuse in a process without `$GLOBALS['BE_USER']` and
 * `$GLOBALS['LANG']` and never set them (ADR-135): their writes were made after
 * an approval, in a backend request. A decision stored durably is carried out
 * by the worker, where neither exists, so an approved write there was refused
 * every time. As for the field fill (ADR-237), the environment is set around
 * that one call, as the run's acting user, and put back in `finally`; no later
 * hook or run in the process sees it.
 *
 * Only a process that has no backend user of its own gets it: no
 * `$GLOBALS['BE_USER']`, or the one the command line boots, which belongs to
 * nobody. A request keeps the user it belongs to.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class WorkerWriteEnvironment
{
    public function __construct(
        private LanguageServiceFactory $languages,
        private bool $commandLine = PHP_SAPI === 'cli',
    ) {}

    /**
     * @template T
     *
     * @param Closure(): T $write
     *
     * @return T
     */
    public function around(?BackendUserAuthentication $user, Closure $write): mixed
    {
        if (!$this->commandLine || !$user instanceof BackendUserAuthentication || !$this->processHasNoUser()) {
            return $write();
        }

        $hadUser      = array_key_exists('BE_USER', $GLOBALS);
        $hadLanguage  = array_key_exists('LANG', $GLOBALS);
        $previousUser = $GLOBALS['BE_USER'] ?? null;
        $previousLang = $GLOBALS['LANG'] ?? null;

        try {
            $GLOBALS['BE_USER'] = $user;
            $GLOBALS['LANG']    = $this->languages->createFromUserPreferences($user);

            return $write();
        } finally {
            if ($hadUser) {
                $GLOBALS['BE_USER'] = $previousUser;
            } else {
                unset($GLOBALS['BE_USER']);
            }

            if ($hadLanguage) {
                $GLOBALS['LANG'] = $previousLang;
            } else {
                unset($GLOBALS['LANG']);
            }
        }
    }

    private function processHasNoUser(): bool
    {
        $ambient = $GLOBALS['BE_USER'] ?? null;

        return !$ambient instanceof BackendUserAuthentication || $ambient instanceof CommandLineUserAuthentication;
    }
}

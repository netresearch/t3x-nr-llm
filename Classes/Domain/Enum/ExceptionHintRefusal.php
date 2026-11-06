<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\Enum;

/**
 * Why no error hint was given (ADR-258). The backend module words each
 * reason in the administrator's language from
 * `locallang_mod_exceptions.xlf:refusal.<value>`; the console command, which
 * has no backend language, prints {@see self::english()}.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum ExceptionHintRefusal: string
{
    /**
     * Argument: the configuration's identifier.
     */
    case ZONE_ADMITS_NO_DIAGNOSTICS = 'zone_admits_no_diagnostics';

    case NOTHING_TO_SEND = 'nothing_to_send';

    /**
     * Argument: the provider's error message, credentials masked.
     */
    case CALL_FAILED = 'call_failed';

    case UNREADABLE_ANSWER = 'unreadable_answer';

    case NO_CONFIGURATION = 'no_configuration';

    public function english(string ...$arguments): string
    {
        $text = match ($this) {
            self::ZONE_ADMITS_NO_DIAGNOSTICS => 'The configuration "%s" may not receive system diagnostics: its provider, or a fallback it can reach, is neither local nor privately hosted, or the configuration or a fallback selects its model by criteria, which counts as external; no model was asked.',
            self::NOTHING_TO_SEND            => 'The entry records no exception class and no stack, and the provider may not receive its message; there is nothing to ask about.',
            self::CALL_FAILED                => 'The model call failed: %s',
            self::UNREADABLE_ANSWER          => 'The model did not answer in the expected form.',
            self::NO_CONFIGURATION           => 'No LLM configuration to ask.',
        };

        return vsprintf($text, array_pad($arguments, substr_count($text, '%s'), ''));
    }
}

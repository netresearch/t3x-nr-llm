<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool;

/**
 * A write tool whose call names the language it writes in, stated as a value
 * for the approval card (ADR-292), so a consumer shows "German" without
 * reading the preview prose.
 *
 * Contract for implementors: a pure function of the arguments, like
 * {@see PendingTargetInterface}. Return the `sys_language_uid` the call
 * writes into, or null where the arguments name none that can be read. Only a
 * tool that takes a target language implements it; for every other call the
 * card states no language, which means "not stated" and not "the default".
 *
 * @api Extension point: third-party write tools may implement this. No new
 *      abstract member within a major version.
 */
interface PendingLanguageInterface
{
    /**
     * @param array<string, mixed> $arguments the model-chosen arguments of the pending call
     */
    public function pendingLanguage(array $arguments): ?int;
}

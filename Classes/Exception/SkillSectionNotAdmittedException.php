<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use RuntimeException;

/**
 * A run's approved skill sections do not fit the model's context window with
 * the headroom admission requires (ADR-214 item 7).
 *
 * Thrown before the first provider call: the head the fit never drops — the
 * system message with the sections and the first user message — plus the
 * newest turn would leave less than 10 % of the budget. The run does not start
 * rather than send instructions the window would later squeeze.
 *
 * @api
 */
final class SkillSectionNotAdmittedException extends RuntimeException implements NrLlmExceptionInterface
{
    /**
     * @param list<string> $skillIdentifiers the skills whose sections were not admitted
     * @param int          $code             the throw site's code
     */
    public function __construct(
        public readonly array $skillIdentifiers,
        int $code = 1791500201,
    ) {
        parent::__construct(
            sprintf(
                'The run was not started: the approved instructions of the skill(s) %s do not fit the context window of this model with the required headroom. Detach a skill, choose a model with a larger window, or shorten the first message.',
                implode(', ', array_map(static fn (string $id): string => '"' . $id . '"', $skillIdentifiers)),
            ),
            $code,
        );
    }
}

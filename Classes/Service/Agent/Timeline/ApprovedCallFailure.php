<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Timeline;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\Enum\ToolOutcome;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;

/**
 * Whether a call an approval released failed: the write somebody approved
 * did not take (NEXT-168).
 *
 * The run itself still ends `completed` — the model gets the error and
 * answers (ADR-092) — so the stored status cannot say it. The steps can: an
 * approval opens the calls it released, the next model round closes them,
 * as nr-mcp-agent reads the same stream for its "nothing was saved" note. A
 * released call failed as the run timeline shows it (ADR-191):
 * `toolIsError` true and an outcome other than `cancelled`. A denial
 * releases nothing, so the call it turned down is no failure here, and a
 * call that failed before any approval — one the model then corrected — is
 * none either.
 */
final class ApprovedCallFailure
{
    /**
     * @param iterable<array{0: string, 1: array<array-key, mixed>}> $steps    each step's kind and payload, in sequence
     * @param bool                                                   $released whether the first step directly follows an approval — the steps of a run resumed by one
     */
    public static function in(iterable $steps, bool $released = false): bool
    {
        foreach ($steps as [$kind, $payload]) {
            if ($kind === AgentEventKind::APPROVAL->value) {
                $released = ($payload['approved'] ?? null) === true;

                continue;
            }

            if ($kind === RunStep::KIND_TOOL) {
                if ($released
                    && ($payload['toolIsError'] ?? null) === true
                    && ($payload['toolOutcome'] ?? null) !== ToolOutcome::CANCELLED->value
                ) {
                    return true;
                }

                continue;
            }

            if ($kind === RunStep::KIND_REQUEST || $kind === RunStep::KIND_LLM) {
                $released = false;
            }
        }

        return false;
    }

    /**
     * The same question of the steps a resumed run returned, which begin with
     * the calls the approval released.
     *
     * @param list<RunStep> $steps
     */
    public static function afterApproval(array $steps): bool
    {
        return self::in(
            array_map(static fn (RunStep $step): array => [$step->kind, $step->toArray()], $steps),
            true,
        );
    }
}

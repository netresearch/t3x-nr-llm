<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\Exception;

/**
 * The submission does not name the turn it was written for, or names a
 * different one (ADR-150).
 *
 * The input-path sibling of {@see StaleApprovalTurnException}. Thrown by
 * submitInput() AFTER the claim, when the digest carried by the
 * {@see \Netresearch\NrLlm\Service\Agent\InputSubmission} is null or does not
 * match the freshly loaded state. A missing digest and a wrong digest are the
 * same fact — the turn the form was rendered from is not known — so both fail
 * closed.
 *
 * The digest covers the pending calls, the target tool and the declared input
 * schema, so a match also proves the submitted values were validated against
 * the schema the run is actually suspended on.
 *
 * The run is released back to WAITING_FOR_INPUT before this is thrown: the
 * submission was refused, not consumed, and the operator can re-open the
 * CURRENT form and submit again.
 */
final class StaleInputTurnException extends AgentRuntimeException
{
    public static function forRun(string $runUuid): self
    {
        return new self($runUuid, sprintf(
            "The submitted input does not match the run's current pending turn; it was written against a different (or no) turn. (run %s)",
            $runUuid !== '' ? $runUuid : 'unknown',
        ));
    }
}

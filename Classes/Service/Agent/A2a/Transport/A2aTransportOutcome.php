<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Transport;

/**
 * What became of one request, as far as the transport can tell (spec 051
 * A2A-C6). The send path maps each to exactly one journal state.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
enum A2aTransportOutcome
{
    /**
     * Nothing left the installation: the guard, the vault or the connect refused it.
     */
    case NOT_SENT;

    /**
     * A response was read: a status line, headers and a (possibly cut) body.
     */
    case RESPONSE;

    /**
     * The request may have reached the remote and no response was read.
     */
    case MAYBE_SENT;
}

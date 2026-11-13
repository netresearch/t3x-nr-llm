<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Service\Agent\A2a\Exception\A2aException;

/**
 * A token request the module refused (ADR-294): the actor may not do it, the
 * record is not theirs, or a limit of their groups does not allow it. The
 * message names the reason and is shown to the actor; it never holds a token.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class A2aTokenRefusedException extends A2aException {}

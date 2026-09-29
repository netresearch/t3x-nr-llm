<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

/**
 * A structured completion answered, but still not in the schema after the
 * one repair round-trip (ADR-082, ADR-211).
 *
 * The model was reached and replied twice; what failed is the answer, not
 * the call. An `InvalidArgumentException` like before, so existing catches
 * keep working, with its own type so a caller can tell a mismatched answer
 * from an invalid schema or a transport failure. The code names the entry
 * point: 1784500001 for `completeStructured()`, 1784500002 for
 * `completeStructuredForConfiguration()`.
 *
 * @api
 */
final class StructuredResponseMismatchException extends InvalidArgumentException {}

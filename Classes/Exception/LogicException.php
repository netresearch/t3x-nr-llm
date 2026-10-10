<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Exception;

/**
 * An internally generated logic or configuration error on the extension boundary (ADR-053).
 * Retains compatibility with the native exception hierarchy.
 *
 * @api
 */
class LogicException extends \LogicException implements NrLlmExceptionInterface {}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use RuntimeException;

/**
 * Exception thrown when access to an LLM configuration is denied.
 */
class AccessDeniedException extends RuntimeException {}

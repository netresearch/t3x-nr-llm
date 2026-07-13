<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception this extension throws
 * on its public API surface.
 *
 * Lets a consumer wrap any nr_llm call in a single
 * `catch (NrLlmExceptionInterface $e)` instead of enumerating the
 * concrete classes (`ProviderException`, `BudgetExceededException`,
 * `AccessDeniedException`, `ConfigurationNotFoundException`,
 * `InvalidArgumentException`, ...) — an enumeration that silently goes
 * stale whenever a new exception type is added (ADR-053).
 */
interface NrLlmExceptionInterface extends Throwable {}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\OpenAi;

use Netresearch\NrLlm\Exception\RuntimeException;

/**
 * The provider reported an overload inside a stream (ADR-240). Never leaves
 * {@see \Netresearch\NrLlm\Provider\OpenAiProvider}.
 *
 * @internal
 */
final class StreamRefusedByProviderException extends RuntimeException {}

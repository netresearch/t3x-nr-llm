<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web\Exception;

use RuntimeException;

/**
 * An external fetch that cannot be performed safely (ADR-202).
 */
final class ExternalFetchException extends RuntimeException {}

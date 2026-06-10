<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\SetupWizard\Exception;

use RuntimeException;

/**
 * Thrown when the setup wizard refuses to dispatch an HTTP request because
 * the target host is not covered by the nr-vault SSRF host allowlist
 * (`SecureHttpClientFactory::isHostAllowed()`).
 */
final class HostNotAllowedException extends RuntimeException
{
    public static function forHost(string $host): self
    {
        return new self(
            sprintf('Host "%s" is not in the allowed hosts list', $host),
            7438190001,
        );
    }
}

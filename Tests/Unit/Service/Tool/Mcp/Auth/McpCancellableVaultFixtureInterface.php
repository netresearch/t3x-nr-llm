<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\Auth;

use Netresearch\NrVault\Http\CancellableHttpClientInterface;
use Netresearch\NrVault\Http\VaultHttpClientInterface;

/**
 * @internal Compose stable SDK capabilities without PHPUnit intersection argument cloning.
 */
interface McpCancellableVaultFixtureInterface extends VaultHttpClientInterface, CancellableHttpClientInterface {}

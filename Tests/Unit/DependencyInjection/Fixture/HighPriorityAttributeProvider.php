<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\DependencyInjection\Fixture;

use Netresearch\NrLlm\Attribute\AsLlmProvider;

#[AsLlmProvider(priority: 500)]
final class HighPriorityAttributeProvider {}

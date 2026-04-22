<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\DependencyInjection\Fixture;

use Netresearch\NrLlm\Attribute\AsLlmProvider;

/**
 * Test fixture: a class carrying the #[AsLlmProvider] attribute.
 *
 * Used to exercise the attribute-discovery path in ProviderCompilerPass
 * without pulling in a real provider (with its HTTP client dependencies).
 */
#[AsLlmProvider(priority: 42)]
final class AttributeTaggedProvider {}

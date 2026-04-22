<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\DependencyInjection\Fixture;

/**
 * Test fixture: a plain service class without #[AsLlmProvider].
 * Must not be touched by ProviderCompilerPass.
 */
final class UntaggedService {}

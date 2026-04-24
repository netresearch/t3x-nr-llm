<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

/**
 * Resolves the backend test prompt with language interpolation.
 *
 * Interface exists so consumers can depend on the contract and tests
 * can substitute doubles — `TestPromptResolverService` is `final`.
 */
interface TestPromptResolverInterface
{
    public function resolve(): string;
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Utility\Fixtures;

use Netresearch\NrLlm\Utility\SecretShapeRedactorTrait;

/**
 * Exposes {@see SecretShapeRedactorTrait}'s protected API for testing, following
 * the same fixture convention as the other trait tests in this suite.
 */
final class SecretShapeRedactorFixture
{
    use SecretShapeRedactorTrait;

    public function redact(string $content): string
    {
        return $this->redactSecretShapes($content);
    }

    public function redactStrict(string $content): ?string
    {
        return $this->redactSecretShapesStrict($content);
    }
}

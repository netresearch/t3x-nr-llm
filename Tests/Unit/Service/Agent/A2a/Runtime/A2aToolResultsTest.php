<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Runtime;

use Netresearch\NrLlm\Service\Agent\A2a\Runtime\A2aToolResults;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(A2aToolResults::class)]
final class A2aToolResultsTest extends TestCase
{
    #[Test]
    public function anUnavailableReasonMasksSecretShapes(): void
    {
        $preview = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
        $pat     = 'ghp_abcdefghijklmnopqrstuvwxyz0123456789';

        $result = A2aToolResults::unavailable("the card at https://agent.example/?ADMCMD_prev={$preview} refused {$pat}");

        self::assertTrue($result->isError);
        self::assertStringContainsString('the card at https://agent.example/', $result->content);
        self::assertStringContainsString('Nothing was sent.', $result->content);
        self::assertStringNotContainsString($preview, $result->content);
        self::assertStringNotContainsString($pat, $result->content);
    }
}

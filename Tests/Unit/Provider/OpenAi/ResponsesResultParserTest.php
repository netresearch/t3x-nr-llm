<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\OpenAi;

use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Provider\OpenAi\ResponsesResultParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResponsesResultParser::class)]
final class ResponsesResultParserTest extends TestCase
{
    private const PREVIEW = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const PAT = 'ghp_abcdefghijklmnopqrstuvwxyz0123456789';

    #[Test]
    public function aFailedReplyMasksSecretShapesInTheProviderMessage(): void
    {
        $response = [
            'status' => 'failed',
            'error'  => ['code' => 'invalid_request', 'message' => 'Rejected https://site.example/?ADMCMD_prev=' . self::PREVIEW . ' sent with ' . self::PAT],
            'output' => [],
        ];

        try {
            (new ResponsesResultParser())->parse($response, 'gpt-test');
            self::fail('A failed reply must raise.');
        } catch (ProviderResponseException $e) {
            self::assertStringContainsString('Rejected https://site.example/', $e->getMessage());
            self::assertStringNotContainsString(self::PREVIEW, $e->getMessage());
            self::assertStringNotContainsString(self::PAT, $e->getMessage());
        }
    }
}

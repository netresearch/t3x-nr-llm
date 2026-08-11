<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Contract;

use Netresearch\NrLlm\Provider\GroqProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

#[CoversClass(GroqProvider::class)]
final class GroqAdapterContractTest extends AbstractOpenAiDialectContractTestCase
{
    protected function newAdapter(): GroqProvider
    {
        return new GroqProvider(
            $this->requestFactory,
            $this->streamFactory,
            new NullLogger(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
    }

    protected function expectedIdentifier(): string
    {
        return 'groq';
    }

    protected function adapterConfiguration(): array
    {
        return [
            'apiKeyIdentifier' => 'vault-groq',
            'defaultModel' => 'llama-3.3-70b-versatile',
            'timeout' => 30,
        ];
    }

    protected function wireModelId(): string
    {
        return 'llama-3.3-70b-versatile';
    }
}

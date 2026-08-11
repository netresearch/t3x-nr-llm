<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Contract;

use Netresearch\NrLlm\Provider\MistralProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

#[CoversClass(MistralProvider::class)]
final class MistralAdapterContractTest extends AbstractOpenAiDialectContractTestCase
{
    protected function newAdapter(): MistralProvider
    {
        return new MistralProvider(
            $this->requestFactory,
            $this->streamFactory,
            new NullLogger(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
    }

    protected function expectedIdentifier(): string
    {
        return 'mistral';
    }

    protected function adapterConfiguration(): array
    {
        return [
            'apiKeyIdentifier' => 'vault-mistral',
            'defaultModel' => 'mistral-large-latest',
            'timeout' => 30,
        ];
    }

    protected function wireModelId(): string
    {
        return 'mistral-large-latest';
    }
}

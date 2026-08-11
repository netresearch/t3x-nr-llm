<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Contract;

use Netresearch\NrLlm\Provider\OpenAiProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

#[CoversClass(OpenAiProvider::class)]
final class OpenAiAdapterContractTest extends AbstractOpenAiDialectContractTestCase
{
    protected function newAdapter(): OpenAiProvider
    {
        return new OpenAiProvider(
            $this->requestFactory,
            $this->streamFactory,
            new NullLogger(),
            $this->createVaultServiceMock(),
            $this->createSecureHttpClientFactoryMock(),
        );
    }

    protected function expectedIdentifier(): string
    {
        return 'openai';
    }

    protected function adapterConfiguration(): array
    {
        return [
            'apiKeyIdentifier' => 'vault-openai',
            'defaultModel' => 'gpt-4o',
            'organizationId' => 'org-contract',
            'timeout' => 30,
        ];
    }

    protected function wireModelId(): string
    {
        return 'gpt-4o';
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Fixture;

use Netresearch\NrVault\Domain\Dto\SecretDetails;
use Netresearch\NrVault\Domain\Dto\SecretMetadata;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use RuntimeException;
use SensitiveParameter;

/**
 * Records what a command asked the vault to do, without any crypto or database.
 *
 * Keeps the stored secrets so a test can assert that a key really was written,
 * and keeps `store` and `rotate` apart so a test can tell a first write from a
 * replacement — the distinction the caller is expected to get right.
 */
final class InMemoryVaultService implements VaultServiceInterface
{
    /** @var array<string, string> */
    public array $secrets = [];

    /** @var array<string, array<string, mixed>> */
    public array $storeOptions = [];

    /** @var list<string> */
    public array $storeCalls = [];

    /** @var list<array{identifier: string, reason: string}> */
    public array $rotateCalls = [];

    public ?string $throwOn = null;

    public function store(string $identifier, #[SensitiveParameter] string $secret, array $options = []): void
    {
        if ($this->throwOn === 'store') {
            throw new RuntimeException('vault refused the write', 9990040379);
        }

        $this->secrets[$identifier]      = $secret;
        $this->storeOptions[$identifier] = $options;
        $this->storeCalls[]              = $identifier;
    }

    public function retrieve(string $identifier): ?string
    {
        return $this->secrets[$identifier] ?? null;
    }

    public function exists(string $identifier): bool
    {
        return isset($this->secrets[$identifier]);
    }

    public function delete(string $identifier, string $reason = ''): void
    {
        unset($this->secrets[$identifier], $this->storeOptions[$identifier]);
    }

    public function rotate(string $identifier, #[SensitiveParameter] string $newSecret, string $reason = ''): void
    {
        if ($this->throwOn === 'rotate') {
            throw new RuntimeException('vault refused the rotation', 6956132853);
        }

        $this->secrets[$identifier] = $newSecret;
        $this->rotateCalls[]        = ['identifier' => $identifier, 'reason' => $reason];
    }

    /**
     * @return list<SecretMetadata>
     */
    public function list(?string $pattern = null): array
    {
        // The command never enumerates secrets; assert against $secrets instead.
        return [];
    }

    public function getMetadata(string $identifier): SecretDetails
    {
        throw new RuntimeException('not needed by these tests', 9452800525);
    }

    public function clearCache(): void {}

    public function http(): VaultHttpClientInterface
    {
        throw new RuntimeException('not needed by these tests', 2573807431);
    }
}

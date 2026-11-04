<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Mcp\OAuth;

use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Http\DnsResolverInterface;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use Netresearch\NrVault\Http\VaultHttpClient;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A Vault whose secrets live in an array, with the REAL Vault HTTP client on
 * top of a fake wire (ADR-259).
 *
 * The HTTP client is nr-vault's own, so host gating, header and body-field
 * injection and the redirect policy are the shipped code; only the transport
 * underneath and the secret storage are replaced. Every OAuth test drives its
 * requests through this, which is what lets an assertion on the wire say
 * something about a token that this extension never held in plain text.
 */
trait OAuthVaultTrait
{
    /** @var array<string, array{value: string, options: array<array-key, mixed>}> */
    private array $vaultSecrets = [];

    /** @var list<string> */
    private array $vaultDeleted = [];

    private bool $hostsAllowed = true;

    private function hostFactory(): SecureHttpClientFactory
    {
        $dns = self::createStub(DnsResolverInterface::class);
        $dns->method('resolve')->willReturnCallback(
            fn (): array => [['ip' => $this->hostsAllowed ? '93.184.215.14' : '10.0.0.7']],
        );

        return new SecureHttpClientFactory($dns);
    }

    private function inMemoryVault(ClientInterface $wire, SecureHttpClientFactory $factory): VaultServiceInterface
    {
        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('store')->willReturnCallback(
            function (string $identifier, string $secret, array $options = []): void {
                $this->vaultSecrets[$identifier] = ['value' => $secret, 'options' => $options];
            },
        );
        $vault->method('retrieve')->willReturnCallback(
            fn (string $identifier): ?string => $this->vaultSecrets[$identifier]['value'] ?? null,
        );
        $vault->method('exists')->willReturnCallback(
            fn (string $identifier): bool => isset($this->vaultSecrets[$identifier]),
        );
        $vault->method('rotate')->willReturnCallback(
            function (string $identifier, string $secret): void {
                if (!isset($this->vaultSecrets[$identifier])) {
                    throw new RuntimeException('No such secret.', 1730000101);
                }

                $this->vaultSecrets[$identifier]['value'] = $secret;
            },
        );
        $vault->method('delete')->willReturnCallback(
            function (string $identifier): void {
                $this->vaultDeleted[] = $identifier;
                unset($this->vaultSecrets[$identifier]);
            },
        );
        $vault->method('http')->willReturnCallback(
            fn (): VaultHttpClientInterface => $this->wireBoundClient($vault, $wire, $factory, null, []),
        );

        return $vault;
    }

    /**
     * nr-vault's own client rebuilds its transport in `withTimeout()`, which
     * would leave the fake wire behind for the real network. This builder
     * records the configuration as the code under test sets it and hands the
     * request, at send time, to a real {@see VaultHttpClient} on the fake wire
     * with exactly that configuration — the same seam the delegated tests use.
     *
     * @param array{0: string, 1: SecretPlacement, 2: array<array-key, mixed>}|null $primary
     * @param array<string, string>                                                 $additional
     */
    private function wireBoundClient(
        VaultServiceInterface $vault,
        ClientInterface $wire,
        SecureHttpClientFactory $factory,
        ?array $primary,
        array $additional,
    ): VaultHttpClientInterface {
        $capability = implode('\\', ['Netresearch', 'NrVault', 'Http', 'AdditionalSecretHttpClientInterface']);
        $client     = interface_exists($capability) ? self::createStub($capability) : self::createStub(VaultHttpClientInterface::class);
        self::assertInstanceOf(VaultHttpClientInterface::class, $client);
        $client->method('withTimeout')->willReturnSelf();
        $client->method('withReason')->willReturnSelf();
        $client->method('withAuthentication')->willReturnCallback(
            fn (string $id, SecretPlacement $placement, array $options = []): VaultHttpClientInterface => $this->wireBoundClient($vault, $wire, $factory, [$id, $placement, $options], []),
        );
        if (method_exists($client, 'withAdditionalBodyField')) {
            $client->method('withAdditionalBodyField')->willReturnCallback(
                fn (string $id, string $field): VaultHttpClientInterface => $this->wireBoundClient($vault, $wire, $factory, $primary, $additional + [$field => $id]),
            );
        }

        $client->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use ($vault, $wire, $factory, $primary, $additional): ResponseInterface {
                $real = new VaultHttpClient(
                    $vault,
                    self::createStub(AuditLogServiceInterface::class),
                    $wire,
                    secureHttpClientFactory: $factory,
                );
                if ($primary !== null) {
                    /** @var array{headerName?: string, prefix?: string, queryParam?: string, bodyField?: string, usernameSecret?: string, reason?: string} $options */
                    $options = $primary[2];
                    $real    = $real->withAuthentication($primary[0], $primary[1], $options);
                }

                foreach ($additional as $field => $id) {
                    // Detected by name, as ADR-217's tests do: nr-vault releases
                    // before the capability have no such method.
                    if (!interface_exists(implode('\\', ['Netresearch', 'NrVault', 'Http', 'AdditionalSecretHttpClientInterface']))) {
                        throw new RuntimeException('The installed Vault lacks additional body fields.', 1730000102);
                    }

                    $configured = $real->withAdditionalBodyField($id, $field);
                    self::assertInstanceOf(VaultHttpClientInterface::class, $configured);
                    $real = $configured;
                }

                return $real->sendRequest($request);
            },
        );

        return $client;
    }

    private function secretValue(string $identifier): string
    {
        return $this->vaultSecrets[$identifier]['value'] ?? '';
    }
}

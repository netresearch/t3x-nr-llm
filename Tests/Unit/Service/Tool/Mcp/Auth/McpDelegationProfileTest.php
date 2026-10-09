<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\Auth;

use InvalidArgumentException;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpDelegationProfile;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpSubjectCredential;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal Delegated MCP authentication tests.
 */
#[CoversClass(McpDelegationProfile::class)]
#[CoversClass(McpSubjectCredential::class)]
final class McpDelegationProfileTest extends AbstractUnitTestCase
{
    #[Test]
    public function canonicalizesBoundedPermissionSets(): void
    {
        $p = new McpDelegationProfile(
            'office',
            'https://idp.example.com/token',
            'client',
            null,
            ['urn:two', 'urn:one', 'urn:two'],
            ['write', 'read', 'write'],
        );
        self::assertSame(['urn:one', 'urn:two'], $p->allowedAudiences);
        self::assertSame(['read', 'write'], $p->allowedScopes);
        $subject = new McpSubjectCredential('11111111-1111-7111-8111-111111111111', ['urn:one'], ['read']);
        self::assertSame(['read'], $subject->allowedScopes);
    }

    #[Test]
    public function rejectsUnsafeEndpointProfileNamesAndCredentials(): void
    {
        foreach ([
            'http://idp.example.com/token',
            'https://user:pass@idp.example.com/token',
            'https://idp.example.com/token#fragment',
            'https:///token',
        ] as $endpoint) {
            try {
                $profile = new McpDelegationProfile('office', $endpoint, 'client', null, ['urn:one'], ['read']);
                self::fail('Accepted ' . $profile->identifier);
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }

        foreach (['', 'bad name', str_repeat('a', 65)] as $id) {
            try {
                $profile = new McpDelegationProfile($id, 'https://idp.example.com/token', 'client', null, ['urn:one'], ['read']);
                self::fail('Accepted ' . $profile->identifier);
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $subject = new McpSubjectCredential('plaintext-token', ['urn:one'], ['read']);
        self::assertSame('plaintext-token', $subject->credentialIdentifier);
    }

    #[Test]
    public function rejectsMalformedOrExcessiveScopeSets(): void
    {
        foreach ([['read write'], ['read"'], ['bad\scope'], array_fill(0, 65, 'read')] as $scopes) {
            try {
                $profile = new McpDelegationProfile(
                    'office',
                    'https://idp.example.com/token',
                    'client',
                    null,
                    ['urn:one'],
                    $scopes,
                );
                self::fail('Accepted ' . $profile->identifier);
            } catch (InvalidArgumentException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    /**
     * @param non-empty-string $identifier
     */
    #[Test]
    #[DataProvider('canonicalCredentialIdentifiers')]
    public function supportsCanonicalVaultIdentifiers(
        string $identifier,
    ): void {
        $subject = new McpSubjectCredential($identifier, ['urn:one'], ['read']);
        $profile = new McpDelegationProfile(
            'office',
            'https://idp.example.com/token',
            'client',
            $identifier,
            ['urn:one'],
            ['read'],
        );
        self::assertSame($identifier, $subject->credentialIdentifier);
        self::assertSame($identifier, $profile->clientSecretIdentifier);
    }

    /**
     * @return iterable<string,array{non-empty-string}>
     */
    public static function canonicalCredentialIdentifiers(): iterable
    {
        yield 'uuid v7' => ['abcdef12-abcd-7abc-8abc-abcdef123456'];
        yield 'uppercase uuid v7' => ['ABCDEF12-ABCD-7ABC-BABC-ABCDEF123456'];
        yield 'minimum alias' => ['Key'];
        yield 'alias letters digits underscores' => ['Subject_7'];
        yield 'maximum alias' => [str_repeat('a', 255)];
    }

    #[Test]
    #[DataProvider('invalidCredentialIdentifiers')]
    public function refusesNoncanonicalSubjectIdentifiers(
        string $identifier,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $subject = new McpSubjectCredential($identifier, ['urn:one'], ['read']);
        self::assertSame($identifier, $subject->credentialIdentifier);
    }

    #[Test]
    #[DataProvider('invalidCredentialIdentifiers')]
    public function refusesNoncanonicalClientIdentifiers(
        string $identifier,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $profile = new McpDelegationProfile(
            'office',
            'https://idp.example.com/token',
            'client',
            $identifier,
            ['urn:one'],
            ['read'],
        );
        self::assertSame($identifier, $profile->clientSecretIdentifier);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function invalidCredentialIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'short alias' => ['ab'];
        yield 'long alias' => [str_repeat('a', 256)];
        yield 'numeric alias prefix' => ['1key'];
        yield 'underscore alias prefix' => ['_key'];
        yield 'alias hyphen' => ['my-key'];
        yield 'non-ascii alias' => ['schlüssel'];
        yield 'space' => ['my key'];
        yield 'reference syntax' => ['%vault(my_key)%'];
        yield 'alias newline' => ["my_key\n"];
        yield 'alias control' => ['my_key' . chr(127)];
        yield 'uuid v4' => ['11111111-1111-4111-8111-111111111111'];
        yield 'uuid v8' => ['11111111-1111-8111-8111-111111111111'];
        yield 'uuid invalid variant' => ['11111111-1111-7111-c111-111111111111'];
        yield 'uuid newline' => ["11111111-1111-7111-8111-111111111111\n"];
        yield 'uuid uri' => ['urn:uuid:11111111-1111-7111-8111-111111111111'];
        yield 'uuid compact' => ['11111111111171118111111111111111'];
    }
}

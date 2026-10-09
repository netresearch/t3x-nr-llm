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
        $subject = new McpSubjectCredential('11111111-1111-4111-8111-111111111111', ['urn:one'], ['read']);
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
        $subject = new McpSubjectCredential('plaintext', ['urn:one'], ['read']);
        self::assertSame('plaintext', $subject->credentialIdentifier);
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
}

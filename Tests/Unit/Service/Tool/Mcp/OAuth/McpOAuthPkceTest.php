<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Mcp\OAuth;

use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthAuthorizer;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthException;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthPendingAuthorization;
use Netresearch\NrLlm\Service\Tool\Mcp\OAuth\McpOAuthPkce;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * PKCE, state and the RFC 9207 issuer check (ADR-259).
 */
#[CoversClass(McpOAuthPkce::class)]
#[CoversClass(McpOAuthPendingAuthorization::class)]
#[CoversClass(McpOAuthAuthorizer::class)]
final class McpOAuthPkceTest extends AbstractUnitTestCase
{
    #[Test]
    public function theChallengeMatchesTheRfc7636AppendixBVector(): void
    {
        self::assertSame(
            'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            McpOAuthPkce::challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'),
        );
    }

    #[Test]
    public function verifierAndStateAreFreshUnreservedAndLongEnough(): void
    {
        $verifier = McpOAuthPkce::verifier();

        self::assertSame(43, strlen($verifier));
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9\-._~]{43,128}\z/', $verifier);
        self::assertNotSame($verifier, McpOAuthPkce::verifier());
        self::assertNotSame(McpOAuthPkce::state(), McpOAuthPkce::state());
    }

    /**
     * The table of MCP "Authorization Response Validation".
     *
     * @return iterable<string, array{0: bool, 1: mixed, 2: string}>
     */
    public static function issuerCases(): iterable
    {
        yield 'advertised, present, equal' => [true, 'https://auth.example.com/t', ''];
        yield 'advertised, present, different' => [true, 'https://evil.example.com/t', 'iss_mismatch'];
        yield 'advertised, absent' => [true, null, 'iss_missing'];
        yield 'not advertised, present, equal' => [false, 'https://auth.example.com/t', ''];
        yield 'not advertised, present, different' => [false, 'https://evil.example.com/t', 'iss_mismatch'];
        yield 'not advertised, absent' => [false, null, ''];
        yield 'no normalisation: trailing slash' => [true, 'https://auth.example.com/t/', 'iss_mismatch'];
        yield 'no normalisation: upper-case host' => [true, 'https://AUTH.example.com/t', 'iss_mismatch'];
        yield 'not a string' => [false, ['https://auth.example.com/t'], 'iss_mismatch'];
    }

    #[Test]
    #[DataProvider('issuerCases')]
    public function theIssuerIsCheckedAsTheTableSays(bool $advertised, mixed $iss, string $reason): void
    {
        try {
            McpOAuthAuthorizer::assertIssuer($this->pending($advertised), $iss);
        } catch (McpOAuthException $exception) {
            self::assertSame($reason, $exception->reason());

            return;
        }

        self::assertSame('', $reason, 'the response was accepted');
    }

    #[Test]
    public function aPendingFlowSurvivesTheSessionAndExpiresAfterTenMinutes(): void
    {
        $pending = $this->pending(true);
        $copy    = McpOAuthPendingAuthorization::fromArray($pending->toArray());

        self::assertEquals($pending, $copy);
        self::assertFalse($pending->isExpired(1000 + 600));
        self::assertTrue($pending->isExpired(1000 + 601));
        self::assertTrue($pending->isExpired(999), 'a clock that went backwards is not trusted');
        self::assertNull(McpOAuthPendingAuthorization::fromArray(array_replace($pending->toArray(), ['clientCreated' => 'yes'])));
        self::assertNull(McpOAuthPendingAuthorization::fromArray(array_replace($pending->toArray(), ['serverUid' => '1'])));
    }

    private function pending(bool $advertised): McpOAuthPendingAuthorization
    {
        return new McpOAuthPendingAuthorization(
            serverUid: 1,
            state: 'state',
            verifier: 'verifier',
            issuer: 'https://auth.example.com/t',
            issParameterSupported: $advertised,
            tokenEndpoint: 'https://auth.example.com/t/token',
            resource: 'https://mcp.example.com/mcp',
            redirectUri: 'https://typo3.example.com/cb',
            clientId: 'client',
            clientSecretCredential: '',
            clientSource: 'dynamic',
            clientCreated: false,
            scope: 'a',
            createdAt: 1000,
        );
    }
}

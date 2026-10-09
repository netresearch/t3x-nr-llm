<?php

/* Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Service\Tool\Mcp\Auth;

use Eris\Generators;
use InvalidArgumentException;
use Netresearch\NrLlm\Service\Tool\Mcp\Auth\McpDelegationProfile;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal Delegated MCP authentication tests.
 */
#[CoversClass(McpDelegationProfile::class)]
final class McpDelegationBoundsFuzzyTest extends AbstractFuzzyTestCase
{
    /**
     * @param callable():McpDelegationProfile $construct
     */
    private function accepts(callable $construct): bool
    {
        try {
            $profile = $construct();
            self::assertSame('office', $profile->identifier);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
    /**
     * @param list<string> $scopes
     */
    private function profile(array $scopes = ['read'], string $audience = 'urn:one'): McpDelegationProfile
    {
        return new McpDelegationProfile('office', 'https://idp.example.com/token', 'client', null, [$audience], $scopes);
    }
    #[Test]
    public function eachScopeLengthHasTheDeclaredBoundary(): void
    {
        $this
            ->forAll(Generators::choose(0, 160))
            ->then(
                function (int $length): void {
                    $accepted = $this->accepts(fn(): McpDelegationProfile => $this->profile([str_repeat('a', $length)]));
                    self::assertSame($length >= 1 && $length <= 128, $accepted);
                },
            );
    }
    #[Test]
    public function eachPermissionListCountHasTheDeclaredBoundary(): void
    {
        $this
            ->forAll(Generators::choose(0, 80))
            ->then(
                function (int $count): void {
                    $values = [];
                    for ($i = 0; $i < $count; ++$i) {
                        $values[] = 'scope_' . $i;
                    }
                    $accepted = $this->accepts(fn(): McpDelegationProfile => $this->profile($values));
                    self::assertSame($count >= 1 && $count <= 64, $accepted);
                },
            );
    }
    #[Test]
    public function scopeCharactersFollowTheOauthTokenGrammar(): void
    {
        $this
            ->forAll(Generators::choose(0, 127))
            ->then(
                function (int $byte): void {
                    $accepted = $this->accepts(fn(): McpDelegationProfile => $this->profile(['scope' . chr($byte)]));
                    self::assertSame(
                        $byte === 33 || $byte >= 35 && $byte <= 91 || $byte >= 93 && $byte <= 126,
                        $accepted,
                    );
                },
            );
    }
    #[Test]
    public function audienceLengthDoesNotAcceptEmptyOrOversizedRecipients(): void
    {
        $this
            ->forAll(Generators::choose(0, 600))
            ->then(
                function (int $length): void {
                    $accepted = $this->accepts(fn(): McpDelegationProfile => $this->profile(audience: str_repeat('a', $length)));
                    self::assertSame($length >= 1 && $length <= 512, $accepted);
                },
            );
    }
}

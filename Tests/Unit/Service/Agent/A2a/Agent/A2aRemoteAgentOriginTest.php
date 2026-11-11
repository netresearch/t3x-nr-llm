<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Agent;

use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where the credential goes: an origin is computed only for a URL both PHP's
 * parser and the PSR-7 Uri read the same way, and an uncertain origin is
 * never equal to another.
 */
#[CoversClass(A2aRemoteAgent::class)]
final class A2aRemoteAgentOriginTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function plainUrls(): iterable
    {
        yield 'https, default port' => ['https://agent.example/rpc', 'https://agent.example:443'];
        yield 'explicit default port' => ['https://agent.example:443/rpc', 'https://agent.example:443'];
        yield 'case and trailing dot' => ['HTTPS://Agent.Example./rpc', 'https://agent.example:443'];
        yield 'http' => ['http://agent.example/rpc', 'http://agent.example:80'];
        yield 'other port' => ['https://agent.example:8443/rpc', 'https://agent.example:8443'];
        yield 'IPv6 literal' => ['https://[2001:db8::1]:8443/rpc', 'https://[2001:db8::1]:8443'];
        yield 'punycode' => ['https://xn--bcher-kva.example/', 'https://xn--bcher-kva.example:443'];
    }

    #[Test]
    #[DataProvider('plainUrls')]
    public function aPlainUrlHasAnOrigin(string $url, string $origin): void
    {
        self::assertSame($origin, A2aRemoteAgent::originOf($url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uncertainUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'no scheme' => ['agent.example/rpc'];
        yield 'not a URL' => ['not a url'];
        yield 'other scheme' => ['ftp://agent.example/rpc'];
        yield 'userinfo' => ['https://user@agent.example/rpc'];
        yield 'userinfo with password' => ['https://user:pw@agent.example/rpc'];
        yield 'userinfo that names another host' => ['https://agent.example@evil.example/rpc'];
        yield 'backslash before the at sign' => ['https://evil.example\\@agent.example/rpc'];
        yield 'backslash as a path separator' => ['https://agent.example\\evil.example/rpc'];
        yield 'NUL byte' => ["https://agent.example\x00.evil.example/rpc"];
        yield 'tab' => ["https://agent.example\t/rpc"];
        yield 'space' => ['https://agent .example/rpc'];
        yield 'DEL' => ["https://agent.example\x7F/rpc"];
        yield 'empty host' => ['https:///rpc'];
        yield 'backslash in the path' => ['https://agent.example/a\\b'];
        yield 'space in the path' => ['https://agent.example/a b'];
        yield 'NUL byte in the path' => ["https://agent.example/a\x00b"];
        yield 'line break in the query' => ["https://agent.example/a?b\nc"];
        yield 'internationalised host' => ['https://bücher.example/'];
        yield 'percent-encoded host' => ['https://agent%2Eexample/rpc'];
    }

    #[Test]
    #[DataProvider('uncertainUrls')]
    public function anUncertainUrlHasNoOrigin(string $url): void
    {
        self::assertNull(A2aRemoteAgent::originOf($url));
    }

    #[Test]
    public function twoUncertainUrlsAreNeverTheSameOrigin(): void
    {
        self::assertFalse(A2aRemoteAgent::sameOrigin('', ''));
        self::assertFalse(A2aRemoteAgent::sameOrigin('not a url', 'not a url'));
        self::assertFalse(A2aRemoteAgent::sameOrigin('https://agent.example@evil.example/', 'https://agent.example@evil.example/'));
    }

    #[Test]
    public function theOriginSeparatesSchemeHostAndPort(): void
    {
        self::assertTrue(A2aRemoteAgent::sameOrigin('https://agent.example/card.json', 'https://agent.example:443/rpc'));
        self::assertFalse(A2aRemoteAgent::sameOrigin('https://agent.example/card.json', 'http://agent.example/rpc'));
        self::assertFalse(A2aRemoteAgent::sameOrigin('https://agent.example/card.json', 'https://agent.example:8443/rpc'));
        self::assertFalse(A2aRemoteAgent::sameOrigin('https://agent.example/card.json', 'https://other.example/rpc'));
    }
}

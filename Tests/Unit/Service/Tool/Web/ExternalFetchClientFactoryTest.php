<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Web;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use InvalidArgumentException;
use Netresearch\NrLlm\Service\Tool\Web\ExternalFetchClientFactory;
use Netresearch\NrVault\Http\SecureHttpClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The client of fetch_external_url is nr-vault's hardened one (ADR-202), so
 * nr-vault's own per-request DNS check runs under the tool's guard.
 */
#[CoversClass(ExternalFetchClientFactory::class)]
final class ExternalFetchClientFactoryTest extends TestCase
{
    #[Test]
    public function theClientCarriesNrVaultsSsrfMiddleware(): void
    {
        $client = (new ExternalFetchClientFactory(new SecureHttpClientFactory()))->create(7);

        self::assertInstanceOf(Client::class, $client);
        $handler = $client->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $handler);
        // Guzzle 8 dropped the stack's string form, so the name is looked up
        // instead: before() throws "Middleware not found" for a missing name
        // in Guzzle 7 and 8 alike.
        $found = true;
        try {
            $handler->before('ssrf-dns-pin', static fn(callable $next): callable => $next);
        } catch (InvalidArgumentException) {
            $found = false;
        }

        self::assertTrue($found, "nr-vault's ssrf-dns-pin middleware is on the stack");
        self::assertSame(7, $client->getConfig('timeout'));
    }
}

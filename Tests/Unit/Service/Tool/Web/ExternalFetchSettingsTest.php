<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Web;

use Netresearch\NrLlm\Service\Tool\Web\ExternalFetchSettings;
use Netresearch\NrLlm\Service\Tool\Web\HostListEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The web tool and the A2A client read host lists of their own (ADR-202,
 * ADR-279): an entry for one never admits a host for the other.
 */
#[CoversClass(ExternalFetchSettings::class)]
final class ExternalFetchSettingsTest extends TestCase
{
    #[Test]
    public function theDefaultInstanceReadsTheWebToolSettingsOnly(): void
    {
        $settings = new ExternalFetchSettings($this->configuration());

        self::assertSame(['web.example'], $this->patterns($settings->allowedHosts()));
        self::assertSame(['denied-web.example'], $this->patterns($settings->deniedHosts()));
        self::assertFalse($settings->proxyPermitted());
    }

    #[Test]
    public function theA2aInstanceReadsItsOwnSettingsOnly(): void
    {
        $settings = new ExternalFetchSettings($this->configuration(), ['a2a', 'client']);

        self::assertSame(['agent.example'], $this->patterns($settings->allowedHosts()));
        self::assertSame(8443, $settings->allowedHosts()[0]->port ?? null);
        self::assertSame([], $this->patterns($settings->deniedHosts()));
        self::assertTrue($settings->proxyPermitted());
    }

    /**
     * @param list<HostListEntry>|null $entries
     *
     * @return list<string>|null
     */
    private function patterns(?array $entries): ?array
    {
        return $entries === null ? null : array_map(static fn (HostListEntry $entry): string => $entry->pattern, $entries);
    }

    private function configuration(): ExtensionConfiguration
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn([
            'tools' => ['fetchExternalUrl' => ['allowedHosts' => 'web.example', 'deniedHosts' => 'denied-web.example']],
            'a2a'   => ['client' => ['allowedHosts' => 'agent.example:8443', 'allowViaProxy' => '1']],
        ]);

        return $configuration;
    }
}

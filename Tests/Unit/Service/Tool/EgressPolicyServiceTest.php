<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolEgressScope;
use Netresearch\NrLlm\Service\Tool\EgressPolicyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Unit tests for the declarative per-group egress scope decision and the
 * fail-closed NONE path (ADR-061).
 *
 * The SiteFinder-backed OWN_SITE URL resolution (host/port matching, relative
 * paths, userinfo rejection) is exercised end-to-end through the real
 * SiteFinder in {@see \Netresearch\NrLlm\Tests\Functional\Service\Tool\ProbeUrlToolTest};
 * here the SiteFinder is only wired so the service can be constructed — the
 * asserted paths (scope lookup, denied groups) never reach it.
 */
#[CoversClass(EgressPolicyService::class)]
final class EgressPolicyServiceTest extends TestCase
{
    private EgressPolicyService $policy;

    protected function setUp(): void
    {
        // getAllSites() is deliberately empty: every assertion below either
        // short-circuits before touching the SiteFinder (NONE groups) or reads
        // an empty host set.
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $this->policy = new EgressPolicyService($siteFinder);
    }

    #[Test]
    public function systemGroupDeclaresOwnSiteScope(): void
    {
        self::assertSame(ToolEgressScope::OWN_SITE, $this->policy->scopeFor('system'));
    }

    #[Test]
    public function everyUndeclaredGroupFailsClosedToNone(): void
    {
        foreach (['content', 'structure', 'configuration', 'code', 'files', 'accounts', 'rag', 'third_party_ext', ''] as $group) {
            self::assertSame(
                ToolEgressScope::NONE,
                $this->policy->scopeFor($group),
                sprintf('Group "%s" must fail closed to NONE', $group),
            );
        }
    }

    #[Test]
    public function noneGroupDeniesEveryUrlWithoutConsultingSites(): void
    {
        // Fail-closed: an undeclared group may not egress even to a plausible
        // own-site URL or a relative path.
        self::assertNull($this->policy->resolveAllowedUrl('content', 'http://localhost/'));
        self::assertNull($this->policy->resolveAllowedUrl('content', '/imprint'));
        self::assertNull($this->policy->resolveAllowedUrl('files', 'https://example.com/'));
        self::assertNull($this->policy->resolveAllowedUrl('third_party_ext', 'http://localhost:8080/'));
    }

    #[Test]
    public function ownSiteGroupDeniesWhenNoSiteMatches(): void
    {
        // system is OWN_SITE, but with no sites configured nothing matches and
        // an absolute URL / relative path both resolve to a denial.
        self::assertNull($this->policy->resolveAllowedUrl('system', 'https://example.com/'));
        self::assertNull($this->policy->resolveAllowedUrl('system', '/imprint'));
        self::assertSame([], $this->policy->allowedHosts());
    }

    #[Test]
    public function ownSiteGroupMatchesAConfiguredHost(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([
            'main' => new Site('main', 1, ['base' => 'https://www.example.com/']),
        ]);
        $policy = new EgressPolicyService($siteFinder);

        self::assertSame(['www.example.com:443'], $policy->allowedHosts());
        self::assertSame(
            'https://www.example.com/team',
            $policy->resolveAllowedUrl('system', 'https://www.example.com/team'),
        );
        // Foreign host and rogue port on the right host are both denied.
        self::assertNull($policy->resolveAllowedUrl('system', 'https://evil.example.org/'));
        self::assertNull($policy->resolveAllowedUrl('system', 'https://www.example.com:8443/'));
        // Undeclared group stays denied even with sites present.
        self::assertNull($policy->resolveAllowedUrl('content', 'https://www.example.com/team'));
    }
}

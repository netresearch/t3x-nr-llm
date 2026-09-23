<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Web;

use Netresearch\NrLlm\Service\Tool\EgressPolicyService;
use Netresearch\NrLlm\Service\Tool\Web\ExternalFetchTarget;
use Netresearch\NrLlm\Service\Tool\Web\ExternalUrlGuard;
use Netresearch\NrLlm\Service\Tool\Web\HostResolverInterface;
use Netresearch\NrLlm\Service\Tool\Web\IpAddressClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The SSRF gate of fetch_external_url (ADR-202).
 */
#[CoversClass(ExternalUrlGuard::class)]
#[CoversClass(ExternalFetchTarget::class)]
final class ExternalUrlGuardTest extends TestCase
{
    /**
     * @param array<string, list<string>> $dns
     */
    private function guard(array $dns = [], string $allowed = '', string $denied = '', bool $unreadable = false): ExternalUrlGuard
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $resolver = new class ($dns) implements HostResolverInterface {
            /**
             * @param array<string, list<string>> $dns
             */
            public function __construct(private readonly array $dns) {}

            public function resolve(string $host): array
            {
                return $this->dns[$host] ?? [];
            }
        };

        $configuration = self::createStub(ExtensionConfiguration::class);
        if ($unreadable) {
            $configuration->method('get')->willThrowException(new RuntimeException('not configured'));
        } else {
            $configuration->method('get')->willReturn([
                'tools' => ['fetchExternalUrl' => ['allowedHosts' => $allowed, 'deniedHosts' => $denied]],
            ]);
        }

        return new ExternalUrlGuard(new EgressPolicyService($siteFinder), $resolver, new IpAddressClassifier(), $configuration);
    }

    #[Test]
    public function aPublicHostIsAllowedAndCarriesItsCheckedAddresses(): void
    {
        $target = $this->guard(['example.org' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']])
            ->check('web', 'https://Example.org./page?q=1#section');

        self::assertTrue($target->allowed, $target->reason);
        self::assertSame('https://example.org/page?q=1', $target->url);
        self::assertSame('example.org', $target->host);
        self::assertSame(443, $target->port);
        self::assertSame(
            'example.org:443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]',
            $target->resolvePin(),
        );
    }

    #[Test]
    public function aPublicIpLiteralIsAllowedWithoutAPin(): void
    {
        $v4 = $this->guard()->check('web', 'http://8.8.8.8/');
        $v6 = $this->guard()->check('web', 'http://[2606:4700:4700::1111]/');

        self::assertTrue($v4->allowed, $v4->reason);
        self::assertTrue($v6->allowed, $v6->reason);
        self::assertSame('2606:4700:4700::1111', $v6->host);
        // curl does not resolve an IP literal, so there is nothing to pin.
        self::assertNull($v4->resolvePin());
        self::assertNull($v6->resolvePin());
    }

    #[Test]
    public function onlyTheWebGroupMayUseTheGuard(): void
    {
        $guard = $this->guard(['example.org' => ['93.184.215.14']]);

        foreach (['system', 'rag', 'content', 'third_party_ext', ''] as $group) {
            $target = $guard->check($group, 'https://example.org/');
            self::assertFalse($target->allowed, $group);
            self::assertStringContainsString('not permitted to reach external hosts', $target->reason);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedUrls(): array
    {
        return [
            'file scheme'              => ['file:///etc/passwd', 'only http and https'],
            'gopher scheme'            => ['gopher://example.org/', 'only http and https'],
            'ftp scheme'               => ['ftp://example.org/', 'only http and https'],
            'no scheme'                => ['example.org/page', 'only http and https'],
            'userinfo'                 => ['https://user:pw@example.org/', 'credentials'],
            'user only'                => ['https://user@example.org/', 'credentials'],
            'non-default port'         => ['https://example.org:8443/', 'port 8443'],
            'http on 443'              => ['http://example.org:443/', 'port 443'],
            'loopback literal'         => ['http://127.0.0.1/', 'private'],
            'metadata literal'         => ['http://169.254.169.254/latest/meta-data/', 'private'],
            'ipv6 loopback'            => ['http://[::1]/', 'private'],
            'ipv4-mapped loopback'     => ['http://[::ffff:127.0.0.1]/', 'private'],
            'aws ipv6 metadata'        => ['http://[fd00:ec2::254]/', 'private'],
            'decimal ip'               => ['http://2130706433/', 'numeric host'],
            'octal ip'                 => ['http://0177.0.0.1/', 'numeric host'],
            'hex ip'                   => ['http://0x7f.0.0.1/', 'numeric host'],
            'short ip'                 => ['http://127.1/', 'numeric host'],
            'resolves to private'      => ['https://internal.example.org/', 'resolves to a private'],
            'one of two is private'    => ['https://rebind.example.org/', 'resolves to a private'],
            'resolves to metadata'     => ['https://metadata.example.org/', 'resolves to a private'],
            'does not resolve'         => ['https://nowhere.example.org/', 'does not resolve'],
            'hosts-file-only name'     => ['http://db/', 'does not resolve'],
            'idn'                      => ['https://bücher.example/', 'no valid host'],
            // parse_url reads `example.org\` as a user name; refused either way.
            'backslash before @'       => ['https://example.org\\@evil.org/', 'credentials'],
            'percent in host'          => ['https://exa%6Dple.org/', 'no valid host'],
            'empty host'               => ['https:///path', 'cannot be parsed'],
        ];
    }

    #[Test]
    #[DataProvider('refusedUrls')]
    public function aDangerousUrlIsRefused(string $url, string $reason): void
    {
        $target = $this->guard([
            'example.org'          => ['93.184.215.14'],
            'internal.example.org' => ['10.0.0.5'],
            'rebind.example.org'   => ['93.184.215.14', '127.0.0.1'],
            'metadata.example.org' => ['169.254.169.254'],
        ])->check('web', $url);

        self::assertFalse($target->allowed);
        self::assertStringContainsString($reason, $target->reason);
        self::assertNull($target->resolvePin());
    }

    #[Test]
    public function theAllowlistNarrowsToItsHosts(): void
    {
        $guard = $this->guard(
            ['docs.example.org' => ['93.184.215.14'], 'www.example.org' => ['93.184.215.15'], 'other.org' => ['93.184.215.16'], 'example.org' => ['93.184.215.17']],
            allowed: 'docs.example.org, *.example.org',
        );

        self::assertTrue($guard->check('web', 'https://docs.example.org/')->allowed);
        self::assertTrue($guard->check('web', 'https://www.example.org/')->allowed);
        // `*.example.org` covers subdomains, not the apex.
        self::assertStringContainsString('not on the allowlist', $guard->check('web', 'https://example.org/')->reason);
        self::assertStringContainsString('not on the allowlist', $guard->check('web', 'https://other.org/')->reason);
    }

    #[Test]
    public function anAllowlistEntryWithAPortOpensThatPortForThatHostOnly(): void
    {
        $guard = $this->guard(['api.example.org' => ['93.184.215.14'], 'www.example.org' => ['93.184.215.15']], allowed: 'api.example.org:8443, www.example.org');

        $target = $guard->check('web', 'https://api.example.org:8443/v1');
        self::assertTrue($target->allowed, $target->reason);
        self::assertSame('api.example.org:8443:93.184.215.14', $target->resolvePin());
        self::assertFalse($guard->check('web', 'https://www.example.org:8443/')->allowed);
    }

    #[Test]
    public function theAllowlistCannotReadmitAPrivateAddress(): void
    {
        $guard = $this->guard(['intranet.example.org' => ['192.168.0.10']], allowed: '127.0.0.1, 169.254.169.254, intranet.example.org, localhost');

        foreach (['http://127.0.0.1/', 'http://169.254.169.254/', 'https://intranet.example.org/', 'http://localhost/'] as $url) {
            self::assertFalse($guard->check('web', $url)->allowed, $url);
        }
    }

    #[Test]
    public function theDenylistWinsOverTheAllowlist(): void
    {
        $guard = $this->guard(
            ['www.example.org' => ['93.184.215.14'], 'ads.example.org' => ['93.184.215.15'], 'tracker.org' => ['93.184.215.16']],
            allowed: '*.example.org, tracker.org',
            denied: 'www.example.org, *.tracker.org, tracker.org:8080',
        );

        self::assertStringContainsString('denylist', $guard->check('web', 'https://www.example.org/')->reason);
        self::assertStringContainsString('denylist', $guard->check('web', 'https://tracker.org/')->reason);
        self::assertTrue($guard->check('web', 'https://ads.example.org/')->allowed);
    }

    #[Test]
    public function unreadableHostListsRefuseEverything(): void
    {
        $target = $this->guard(['example.org' => ['93.184.215.14']], unreadable: true)->check('web', 'https://example.org/');

        self::assertFalse($target->allowed);
        self::assertStringContainsString('cannot be read', $target->reason);
    }

    #[Test]
    public function absentHostListsAllowEveryPublicHost(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['tools' => []]);
        $resolver = self::createStub(HostResolverInterface::class);
        $resolver->method('resolve')->willReturn(['93.184.215.14']);

        $guard = new ExternalUrlGuard(new EgressPolicyService($siteFinder), $resolver, new IpAddressClassifier(), $configuration);

        self::assertTrue($guard->check('web', 'https://example.org/')->allowed);
    }
}

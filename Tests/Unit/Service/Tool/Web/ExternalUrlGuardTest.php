<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Web;

use Netresearch\NrLlm\Service\Tool\EgressPolicyService;
use Netresearch\NrLlm\Service\Tool\Web\ExternalFetchSettings;
use Netresearch\NrLlm\Service\Tool\Web\ExternalFetchTarget;
use Netresearch\NrLlm\Service\Tool\Web\ExternalUrlGuard;
use Netresearch\NrLlm\Service\Tool\Web\HostListEntry;
use Netresearch\NrLlm\Service\Tool\Web\HostResolverInterface;
use Netresearch\NrLlm\Service\Tool\Web\IpAddressClassifier;
use Netresearch\NrLlm\Service\Tool\Web\ProxyDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The SSRF gate of fetch_external_url (ADR-202).
 */
#[CoversClass(ExternalUrlGuard::class)]
#[CoversClass(ExternalFetchTarget::class)]
#[CoversClass(ExternalFetchSettings::class)]
#[CoversClass(HostListEntry::class)]
#[CoversClass(ProxyDetector::class)]
final class ExternalUrlGuardTest extends TestCase
{
    /**
     * @param array<string, list<string>> $dns
     * @param array<string, mixed>        $settings extra keys under tools.fetchExternalUrl
     * @param array<string, string>       $env      environment seen by the proxy detector
     * @param list<Site>                  $sites
     */
    private function guard(
        array $dns = [],
        string $allowed = '',
        string $denied = '',
        bool $unreadable = false,
        array $settings = [],
        array $env = [],
        array $sites = [],
    ): ExternalUrlGuard {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);

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
                'tools' => ['fetchExternalUrl' => ['allowedHosts' => $allowed, 'deniedHosts' => $denied] + $settings],
            ]);
        }

        return new ExternalUrlGuard(
            new EgressPolicyService($siteFinder),
            $resolver,
            new IpAddressClassifier(),
            new ExternalFetchSettings($configuration),
            new ProxyDetector($env, 'fpm-fcgi'),
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy']);
        parent::tearDown();
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

        $guard = new ExternalUrlGuard(
            new EgressPolicyService($siteFinder),
            $resolver,
            new IpAddressClassifier(),
            new ExternalFetchSettings($configuration),
            new ProxyDetector([], 'fpm-fcgi'),
        );

        self::assertTrue($guard->check('web', 'https://example.org/')->allowed);
    }

    #[Test]
    public function aHostOfThisInstallationIsRefused(): void
    {
        // A stub: a real Site evaluates its variant conditions on construction.
        $site = self::createStub(Site::class);
        $site->method('getBase')->willReturn(new Uri('https://www.own.example/'));
        $site->method('getConfiguration')->willReturn([
            'base'         => 'https://www.own.example/',
            'baseVariants' => [['base' => 'https://staging.own.example/', 'condition' => 'false']],
        ]);
        $guard = $this->guard(
            ['www.own.example' => ['93.184.215.14'], 'staging.own.example' => ['93.184.215.15'], 'other.example' => ['93.184.215.16']],
            sites: [$site],
        );

        self::assertStringContainsString('host of this installation', $guard->check('web', 'https://www.own.example/secret')->reason);
        self::assertStringContainsString('host of this installation', $guard->check('web', 'https://STAGING.own.example./')->reason);
        self::assertTrue($guard->check('web', 'https://other.example/')->allowed);
    }

    #[Test]
    public function aHostOfASiteLanguageIsRefused(): void
    {
        $site = self::createStub(Site::class);
        $site->method('getBase')->willReturn(new Uri('https://www.own.example/'));
        $site->method('getAllLanguages')->willReturn([
            new SiteLanguage(0, 'en_US.UTF-8', new Uri('https://www.own.example/'), []),
            new SiteLanguage(1, 'de_DE.UTF-8', new Uri('https://www.own.de/'), []),
        ]);
        $site->method('getConfiguration')->willReturn([
            'base'      => 'https://www.own.example/',
            'languages' => [
                ['languageId' => 1, 'base' => 'https://www.own.de/', 'baseVariants' => [['base' => 'https://staging.own.de/', 'condition' => 'false']]],
            ],
        ]);
        $guard = $this->guard(
            ['www.own.de' => ['93.184.215.14'], 'staging.own.de' => ['93.184.215.15']],
            sites: [$site],
        );

        self::assertStringContainsString('host of this installation', $guard->check('web', 'https://www.own.de/')->reason);
        self::assertStringContainsString('host of this installation', $guard->check('web', 'https://staging.own.de/')->reason);
    }

    #[Test]
    public function anUnreadableListEntryMakesTheListUnreadable(): void
    {
        $dns = ['example.org' => ['93.184.215.14']];

        // Only an unparseable entry: read as "no entries", the allowlist would
        // allow every public host.
        $allowed = $this->guard($dns, allowed: '2001:db8:::1')->check('web', 'https://example.org/');
        $denied  = $this->guard($dns, denied: 'example.org, [1:2]')->check('web', 'https://example.org/');

        self::assertFalse($allowed->allowed);
        self::assertStringContainsString('cannot be read', $allowed->reason);
        self::assertFalse($denied->allowed);
        self::assertStringContainsString('cannot be read', $denied->reason);
        // Blank entries (a trailing comma) are not an error.
        self::assertTrue($this->guard($dns, allowed: 'example.org, ,')->check('web', 'https://example.org/')->allowed);
    }

    #[Test]
    public function aConfiguredTypo3ProxyRefusesTheFetch(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy'] = 'http://proxy.corp:3128';

        $target = $this->guard(['example.org' => ['93.184.215.14']])->check('web', 'https://example.org/');

        self::assertFalse($target->allowed);
        self::assertStringContainsString('HTTP proxy', $target->reason);
    }

    #[Test]
    public function anEnvironmentProxyRefusesUnlessNoProxyExcludesTheHost(): void
    {
        $dns = ['example.org' => ['93.184.215.14'], 'direct.example.org' => ['93.184.215.15']];
        $env = ['HTTPS_PROXY' => 'http://proxy.corp:3128', 'NO_PROXY' => 'direct.example.org'];

        self::assertStringContainsString('HTTP proxy', $this->guard($dns, env: $env)->check('web', 'https://example.org/')->reason);
        self::assertTrue($this->guard($dns, env: $env)->check('web', 'https://direct.example.org/')->allowed);
        // HTTP_PROXY is not trusted outside the CLI, as nr-vault's client does.
        self::assertTrue($this->guard($dns, env: ['HTTP_PROXY' => 'http://proxy.corp:3128'])->check('web', 'http://example.org/')->allowed);
    }

    #[Test]
    public function theProxyIsPermittedOnlyTogetherWithAnAllowlist(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['proxy'] = 'http://proxy.corp:3128';
        $dns = ['example.org' => ['93.184.215.14']];

        self::assertFalse($this->guard($dns, settings: ['allowViaProxy' => '1'])->check('web', 'https://example.org/')->allowed);
        self::assertTrue($this->guard($dns, allowed: 'example.org', settings: ['allowViaProxy' => '1'])->check('web', 'https://example.org/')->allowed);
        self::assertFalse($this->guard($dns, allowed: 'example.org')->check('web', 'https://example.org/')->allowed);
    }

    #[Test]
    public function ipv6ListEntriesAreAddressesNotHostPorts(): void
    {
        $guard = $this->guard(allowed: '2606:4700:4700::1111, [2a00:1450:4001:82a::200e], [2606:4700:4700::1001]:8443');

        self::assertTrue($guard->check('web', 'http://[2606:4700:4700::1111]/')->allowed);
        self::assertTrue($guard->check('web', 'https://[2a00:1450:4001:82a::200e]/')->allowed);
        self::assertTrue($guard->check('web', 'https://[2606:4700:4700::1001]:8443/')->allowed);
        self::assertFalse($guard->check('web', 'https://[2606:4700:4700::1001]/')->allowed);
        self::assertFalse($guard->check('web', 'http://[2606:4700:4700::1112]/')->allowed);
    }

    #[Test]
    public function anAddressOnTheDenylistIsRefusedHoweverTheHostNamesIt(): void
    {
        $guard = $this->guard(
            ['alias.example.org' => ['93.184.215.14'], 'other.example.org' => ['93.184.215.15']],
            denied: '93.184.215.14, 2606:4700:4700::1111',
        );

        self::assertStringContainsString('denylist', $guard->check('web', 'https://93.184.215.14/')->reason);
        self::assertStringContainsString('denylist', $guard->check('web', 'https://[::ffff:93.184.215.14]/')->reason);
        self::assertStringContainsString('denylist', $guard->check('web', 'https://alias.example.org/')->reason);
        self::assertStringContainsString('denylist', $guard->check('web', 'https://[2606:4700:4700:0::1111]/')->reason);
        self::assertTrue($guard->check('web', 'https://other.example.org/')->allowed);
    }
}

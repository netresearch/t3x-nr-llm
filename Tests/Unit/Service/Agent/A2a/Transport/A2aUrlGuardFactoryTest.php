<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Transport;

use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTestNetworkAddressClassifier;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use Netresearch\NrLlm\Service\Tool\EgressPolicyService;
use Netresearch\NrLlm\Service\Tool\Web\HostResolverInterface;
use Netresearch\NrLlm\Service\Tool\Web\IpAddressClassifier;
use Netresearch\NrLlm\Service\Tool\Web\ProxyDetector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The address classifier of the A2A guard (spec 051 A2A-SEC10): the test
 * network is public only in the Testing context, and only when named.
 */
#[CoversClass(A2aUrlGuardFactory::class)]
#[CoversClass(A2aTestNetworkAddressClassifier::class)]
final class A2aUrlGuardFactoryTest extends TestCase
{
    private mixed $savedExtconf = null;

    protected function setUp(): void
    {
        $this->savedExtconf = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['nr_llm'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->savedExtconf === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['nr_llm']);

            return;
        }

        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['nr_llm'] = $this->savedExtconf;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonTestingContexts(): iterable
    {
        yield 'Production' => ['Production'];
        yield 'Production/Staging' => ['Production/Staging'];
        yield 'Development' => ['Development'];
    }

    #[Test]
    #[DataProvider('nonTestingContexts')]
    public function outsideTestingTheRealClassifierDecidesEvenWithNetworksNamed(string $context): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['nr_llm']['a2aTestPublicNetworks'] = ['172.16.0.0/12'];
        $factory                                                                  = $this->factory($context);

        self::assertInstanceOf(IpAddressClassifier::class, $factory->classifier());
        self::assertFalse($factory->classifier()->isPublic('172.17.0.1'));
    }

    #[Test]
    public function inTestingWithoutNamedNetworksTheRealClassifierDecides(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['nr_llm']['a2aTestPublicNetworks']);

        self::assertInstanceOf(IpAddressClassifier::class, $this->factory('Testing')->classifier());
    }

    #[Test]
    public function inTestingANamedNetworkIsPublicAndNothingElseChanges(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['nr_llm']['a2aTestPublicNetworks'] = ['172.16.0.0/12', 'fd00:a2a::/64', 'not-a-network'];
        $classifier                                                               = $this->factory('Testing')->classifier();

        self::assertInstanceOf(A2aTestNetworkAddressClassifier::class, $classifier);
        self::assertTrue($classifier->isPublic('172.17.0.1'));
        self::assertTrue($classifier->isPublic('172.31.255.255'));
        self::assertFalse($classifier->isPublic('192.168.1.1'), 'private outside the named network: still refused');
        self::assertTrue($classifier->isPublic('fd00:a2a::5'));
        self::assertFalse($classifier->isPublic('fd00:a2b::5'));
        self::assertFalse($classifier->isPublic('127.0.0.1'));
        self::assertFalse($classifier->isPublic('169.254.169.254'));
        self::assertTrue($classifier->isPublic('8.8.8.8'), 'public stays public');
        self::assertTrue($classifier->isAmbiguousNumericHost('0x7f000001'));
    }

    #[Test]
    public function aPrefixThatIsNotAByteBoundaryIsHonoured(): void
    {
        $classifier = new A2aTestNetworkAddressClassifier(new IpAddressClassifier(), ['10.0.0.128/25']);

        self::assertTrue($classifier->isPublic('10.0.0.200'));
        self::assertFalse($classifier->isPublic('10.0.0.100'));
    }

    private function factory(string $context): A2aUrlGuardFactory
    {
        return new A2aUrlGuardFactory(
            new EgressPolicyService(self::createStub(SiteFinder::class)),
            self::createStub(HostResolverInterface::class),
            new IpAddressClassifier(),
            self::createStub(ExtensionConfiguration::class),
            new ProxyDetector([], 'cli'),
            new ApplicationContext($context),
        );
    }
}

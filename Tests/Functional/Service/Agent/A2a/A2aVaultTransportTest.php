<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aHttpRequest;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aTransportOutcome;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aUrlGuardFactory;
use Netresearch\NrLlm\Service\Agent\A2a\Transport\A2aVaultTransport;
use Netresearch\NrLlm\Service\Tool\EgressPolicyService;
use Netresearch\NrLlm\Service\Tool\Web\HostResolverInterface;
use Netresearch\NrLlm\Service\Tool\Web\IpAddressClassifier;
use Netresearch\NrLlm\Service\Tool\Web\ProxyDetector;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The real transport through nr-vault against a local `php -S` endpoint
 * (spec 051 A2A-S0-3, A2A-S0-3a, A2A-C8, ADR-279).
 *
 * The endpoint listens on 127.0.0.1, so every case runs with the Testing
 * context's test network (A2A-SEC10) and a literal `allowed_hosts` entry,
 * nr-vault's own opt-in for a private address.
 *
 * The pin is proved with `agent.invalid`, a name that never resolves: a
 * request reaches the endpoint only if the address the guard checked is the
 * one the connection uses. That needs nr-vault's
 * `PinnedAddressHttpClientInterface` (netresearch/t3x-nr-vault#521); with an
 * nr-vault that lacks it the transport must refuse and send nothing, which
 * the first case asserts whenever the interface is absent.
 */
#[CoversClass(A2aVaultTransport::class)]
final class A2aVaultTransportTest extends AbstractFunctionalTestCase
{
    private const PORT = 18121;

    private const PIN_INTERFACE = 'Netresearch\\NrVault\\Http\\PinnedAddressHttpClientInterface';

    /** @var resource|null */
    private $server;

    private string $log = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->log    = tempnam(sys_get_temp_dir(), 'a2a-router-') ?: throw new RuntimeException('no temp file', 9078470291);
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::PORT, __DIR__ . '/../../../Fixtures/A2a/a2a-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['A2A_ROUTER_LOG' => $this->log],
        ) ?: null;
        for ($i = 0; $i < 50 && !$this->listening(); ++$i) {
            usleep(100000);
        }

        A2aConfVars::set(['EXTCONF', 'nr_llm', 'a2aTestPublicNetworks'], ['127.0.0.0/8']);
        A2aConfVars::set(['HTTP', 'allowed_hosts'], ['127.0.0.1', 'agent.invalid']);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }

        if ($this->log !== '' && is_file($this->log)) {
            unlink($this->log);
        }

        A2aConfVars::remove(['EXTCONF', 'nr_llm', 'a2aTestPublicNetworks']);
        parent::tearDown();
    }

    #[Test]
    public function withoutTheNrVaultPinAHostNameIsNeverSentUnpinned(): void
    {
        if (interface_exists(self::PIN_INTERFACE)) {
            self::markTestSkipped('This nr-vault offers the pin; the pinned cases below cover it.');
        }

        $result = $this->transport()->send($this->request('http://agent.invalid:' . self::PORT . '/rpc'));

        self::assertSame(A2aTransportOutcome::NOT_SENT, $result->outcome);
        self::assertSame('PIN_UNSUPPORTED', $result->reason);
        self::assertSame([], $this->hits());
    }

    #[Test]
    public function thePinnedAddressIsTheOneTheConnectionUses(): void
    {
        $this->requirePin();

        $result = $this->transport()->send($this->request('http://agent.invalid:' . self::PORT . '/rpc'));

        self::assertSame(A2aTransportOutcome::RESPONSE, $result->outcome, $result->detail);
        self::assertSame(200, $result->status);
        self::assertStringContainsString('TASK_STATE_WORKING', $result->body);
        self::assertSame('agent.invalid:' . self::PORT, $this->hits()[0]['host'] ?? null);
        self::assertSame('1.0', $this->hits()[0]['version'] ?? null);
    }

    #[Test]
    public function aRedirectIsReturnedAndItsTargetIsNeverReached(): void
    {
        $result = $this->transport()->send($this->request('http://127.0.0.1:' . self::PORT . '/redirect'));

        self::assertSame(A2aTransportOutcome::RESPONSE, $result->outcome, $result->detail);
        self::assertSame(307, $result->status);
        self::assertSame(['/redirect'], array_column($this->hits(), 'path'));
    }

    #[Test]
    public function aResponseAboveTheLimitIsCutAndMarked(): void
    {
        $result = $this->transport()->send($this->request('http://127.0.0.1:' . self::PORT . '/big', 1024));

        self::assertSame(A2aTransportOutcome::RESPONSE, $result->outcome, $result->detail);
        self::assertTrue($result->oversized);
        self::assertSame(1024, strlen($result->body));
    }

    #[Test]
    public function aPrivateAddressOutsideTheTestNetworkIsRefusedBeforeAnythingIsSent(): void
    {
        A2aConfVars::remove(['EXTCONF', 'nr_llm', 'a2aTestPublicNetworks']);

        $result = $this->transport()->send($this->request('http://127.0.0.1:' . self::PORT . '/rpc'));

        self::assertSame(A2aTransportOutcome::NOT_SENT, $result->outcome);
        self::assertSame('ADDRESS_REFUSED', $result->reason);
        self::assertSame([], $this->hits());
    }

    #[Test]
    public function aPortNotOnTheAllowlistIsRefused(): void
    {
        $result = $this->transport()->send($this->request('http://127.0.0.1:' . (self::PORT + 5) . '/rpc'));

        self::assertSame(A2aTransportOutcome::NOT_SENT, $result->outcome);
        self::assertSame('ADDRESS_REFUSED', $result->reason);
    }

    #[Test]
    public function aClosedPortSentNothing(): void
    {
        $result = $this->transport(['127.0.0.1:' . (self::PORT + 1)])->send($this->request('http://127.0.0.1:' . (self::PORT + 1) . '/rpc'));

        self::assertSame(A2aTransportOutcome::NOT_SENT, $result->outcome, $result->detail);
        self::assertSame('CONNECT_FAILED', $result->reason);
    }

    #[Test]
    public function aMissingCredentialRefusesBeforeAnythingIsSent(): void
    {
        $result = $this->transport()->send($this->request('http://127.0.0.1:' . self::PORT . '/rpc', 4096, '01890000-0000-7000-8000-00000000dead'));

        self::assertSame(A2aTransportOutcome::NOT_SENT, $result->outcome);
        self::assertSame('CREDENTIAL_UNAVAILABLE', $result->reason);
        self::assertSame([], $this->hits());
    }

    private function listening(): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            $socket = fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 0.1);
        } finally {
            restore_error_handler();
        }

        if (!is_resource($socket)) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function requirePin(): void
    {
        if (!interface_exists(self::PIN_INTERFACE)) {
            self::markTestSkipped('nr-vault without PinnedAddressHttpClientInterface (netresearch/t3x-nr-vault#521); the transport refuses host names there, see the first case.');
        }
    }

    private function request(string $url, int $maxBytes = 4096, string $credential = ''): A2aHttpRequest
    {
        return new A2aHttpRequest(
            'POST',
            $url,
            ['Content-Type' => 'application/json', 'A2A-Version' => '1.0'],
            '{"jsonrpc":"2.0","id":"r-1","method":"GetTask","params":{"id":"t-1"}}',
            $credential,
            5,
            10,
            $maxBytes,
            'A2aVaultTransportTest',
        );
    }

    /**
     * @param list<string> $extraAllowed
     */
    private function transport(array $extraAllowed = []): A2aVaultTransport
    {
        $resolver = new class implements HostResolverInterface {
            public function resolve(string $host): array
            {
                return $host === 'agent.invalid' ? ['127.0.0.1'] : [];
            }
        };
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['a2a' => ['client' => [
            'allowedHosts' => implode(',', ['127.0.0.1:' . self::PORT, 'agent.invalid:' . self::PORT, ...$extraAllowed]),
        ]]]);

        return new A2aVaultTransport(
            new A2aUrlGuardFactory(
                new EgressPolicyService($this->getService(SiteFinder::class)),
                $resolver,
                new IpAddressClassifier(),
                $configuration,
                new ProxyDetector([], 'cli', []),
            ),
            $this->getService(VaultServiceInterface::class),
            $this->getService(RequestFactoryInterface::class),
            $this->getService(StreamFactoryInterface::class),
        );
    }

    /**
     * @return list<array<string, string>>
     */
    private function hits(): array
    {
        $hits = [];
        foreach (file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $hit = json_decode($line, true);
            if (is_array($hit)) {
                $entry = [];
                foreach ($hit as $key => $value) {
                    if (is_scalar($value)) {
                        $entry[(string) $key] = (string) $value;
                    }
                }

                $hits[] = $entry;
            }
        }

        return $hits;
    }
}

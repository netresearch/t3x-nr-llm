<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized;

use LogicException;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\AbstractSpecializedService;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\MultipartBodyBuilderTrait;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrLlm\Tests\Fixture\AllowingBudgetService;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Support\InMemoryQueryResult;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(AbstractSpecializedService::class)]
final class AbstractSpecializedServiceTest extends AbstractUnitTestCase
{
    #[Test]
    public function isAvailableReturnsFalseWhenApiKeyIsEmpty(): void
    {
        $subject = $this->createSubject(apiKeyIdentifier: '');

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenApiKeyIsConfigured(): void
    {
        $subject = $this->createSubject(apiKeyIdentifier: 'test-key');

        self::assertTrue($subject->isAvailable());
    }

    #[Test]
    public function ensureAvailableThrowsWhenNotConfigured(): void
    {
        $subject = $this->createSubject(apiKeyIdentifier: '');

        $this->expectException(ServiceUnavailableException::class);

        $subject->callEnsureAvailable();
    }

    #[Test]
    public function ensureAvailableIsNoOpWhenConfigured(): void
    {
        $subject = $this->createSubject(apiKeyIdentifier: 'test-key');

        $subject->callEnsureAvailable();

        // Reaching here = no exception = pass.
        self::assertTrue($subject->isAvailable());
    }

    #[Test]
    public function enforceBudgetAlwaysConsultsTheBudgetService(): void
    {
        $budget = $this->createMock(BudgetServiceInterface::class);
        // The dependency is required since the fail-open optional parameter was
        // removed: there is no longer a construction in which the gate silently
        // does not run.
        $budget->expects(self::once())->method('check')->willReturn(BudgetCheckResult::allowed());

        $this->createSubject(budgetService: $budget)->callEnforceBudget(42, 0.5, null);
    }

    #[Test]
    public function enforceBudgetThrowsBudgetExceededWhenTheCheckDenies(): void
    {
        $budget = $this->createMock(BudgetServiceInterface::class);
        $budget->expects(self::once())
            ->method('check')
            ->with(42, 0.5, null)
            ->willReturn(BudgetCheckResult::denied('cost_per_day', 9.0, 9.0, 'exhausted'));

        $subject = $this->createSubject(budgetService: $budget);

        $this->expectException(BudgetExceededException::class);

        $subject->callEnforceBudget(42, 0.5, null);
    }

    #[Test]
    public function enforceBudgetPassesWhenTheCheckAllows(): void
    {
        $budget = $this->createMock(BudgetServiceInterface::class);
        $budget->expects(self::once())
            ->method('check')
            ->with(7, 0.25, null)
            ->willReturn(BudgetCheckResult::allowed());

        $subject = $this->createSubject(budgetService: $budget);

        $subject->callEnforceBudget(7, 0.25, null);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function enforceBudgetDefaultsAbsentBeUserUidAndCostToZero(): void
    {
        $budget = $this->createMock(BudgetServiceInterface::class);
        $budget->expects(self::once())
            ->method('check')
            ->with(0, 0.0, null)
            ->willReturn(BudgetCheckResult::allowed());

        $subject = $this->createSubject(budgetService: $budget);

        $subject->callEnforceBudget(null, null, null);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function enforceBudgetResolvesTheConfigurationIdentifierAndPassesItToTheCheck(): void
    {
        $configuration = self::createStub(LlmConfiguration::class);
        $configuration->method('isActive')->willReturn(true);

        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturn($configuration);

        $budget = $this->createMock(BudgetServiceInterface::class);
        $budget->expects(self::once())
            ->method('check')
            ->with(5, 0.1, self::identicalTo($configuration))
            ->willReturn(BudgetCheckResult::allowed());

        $subject = $this->createSubject(configurationRepository: $repository, budgetService: $budget);

        $subject->callEnforceBudget(5, 0.1, 'nr_repurpose_image');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function buildEndpointUrlConcatenatesWithSingleSlash(): void
    {
        $subject = $this->createSubject(baseUrl: 'https://api.example.test/v1');

        self::assertSame('https://api.example.test/v1/foo', $subject->callBuildEndpointUrl('foo'));
        self::assertSame('https://api.example.test/v1/foo', $subject->callBuildEndpointUrl('/foo'));
    }

    #[Test]
    public function buildEndpointUrlHandlesTrailingSlashOnBase(): void
    {
        $subject = $this->createSubject(baseUrl: 'https://api.example.test/v1/');

        self::assertSame('https://api.example.test/v1/foo', $subject->callBuildEndpointUrl('foo'));
    }

    #[Test]
    public function buildEndpointUrlReturnsBaseWhenEndpointEmpty(): void
    {
        // TTS posts directly to the base URL — endpoint is empty.
        $subject = $this->createSubject(baseUrl: 'https://api.example.test/v1/audio/speech');

        self::assertSame('https://api.example.test/v1/audio/speech', $subject->callBuildEndpointUrl(''));
    }

    #[Test]
    public function sendJsonRequestSubstitutesInvalidUtf8InPayloadInsteadOfThrowing(): void
    {
        // A non-UTF-8 byte in the request payload (e.g. a prompt pasted from a
        // Latin-1 source) must degrade to a replacement character, not throw a
        // \JsonException from json_encode and abort the call (PR #315/#316 class).
        $captured = null;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$captured) {
                $captured = $request;

                return $this->createJsonResponseMock(['result' => 'ok'], 200);
            });

        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test/v1', httpClient: $httpClient);

        $result = $subject->callSendJsonRequest('endpoint', ['prompt' => "caf\xE9 scene"]);

        self::assertSame(['result' => 'ok'], $result);
        // The invalid 0xE9 byte is substituted with U+FFFD in the outgoing body
        // (JSON-escaped as �), not left to throw \JsonException.
        self::assertNotNull($captured);
        self::assertStringContainsString('caf\ufffd scene', (string)$captured->getBody());
    }

    #[Test]
    public function sendJsonRequestReturnsDecodedSuccessResponse(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['result' => 'ok'], 200));

        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test/v1', httpClient: $httpClient);

        $result = $subject->callSendJsonRequest('endpoint', ['foo' => 'bar']);

        self::assertSame(['result' => 'ok'], $result);
    }

    #[Test]
    public function sendJsonRequestSetsContentTypeAndReachesInjectedClient(): void
    {
        // Auth is no longer added to the request here — the secure vault client
        // injects it, and `setHttpClient()` bypasses that client entirely. So
        // the request that reaches the injected mock carries Content-Type (and
        // any `getAdditionalHeaders()`) but no Authorization header.
        $captured = null;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$captured) {
                $captured = $request;
                return $this->createJsonResponseMock([], 200);
            });

        $subject = $this->createSubject(apiKeyIdentifier: 'vault-id', baseUrl: 'https://api.test/v1', httpClient: $httpClient);

        $subject->callSendJsonRequest('endpoint', []);

        self::assertNotNull($captured);
        self::assertSame('application/json', $captured->getHeaderLine('Content-Type'));
        self::assertSame('', $captured->getHeaderLine('Authorization'));
    }

    #[Test]
    public function getSecureClientAuthenticatesThroughVaultWithConfiguredIdentifierAndBearerPlacement(): void
    {
        // With no `setHttpClient()` override, `getSecureClient()` must build the
        // audited vault client via `vault->http()->withAuthentication($id, Bearer, [])`
        // for the configured identifier and stamp the audit reason. The base
        // class defaults to Bearer placement with no options.
        $capturedIdentifier = null;
        $capturedPlacement = null;
        $capturedOptions = null;
        $capturedReason = null;

        $vaultHttpClient = self::createStub(VaultHttpClientInterface::class);
        $vaultHttpClient->method('withTimeout')->willReturnSelf();
        $vaultHttpClient->method('withAuthentication')->willReturnCallback(
            function (string $id, SecretPlacement $placement, array $options) use (
                &$capturedIdentifier,
                &$capturedPlacement,
                &$capturedOptions,
                $vaultHttpClient,
            ): VaultHttpClientInterface {
                $capturedIdentifier = $id;
                $capturedPlacement = $placement;
                $capturedOptions = $options;
                return $vaultHttpClient;
            },
        );
        $vaultHttpClient->method('withReason')->willReturnCallback(
            function (string $reason) use (&$capturedReason, $vaultHttpClient): VaultHttpClientInterface {
                $capturedReason = $reason;
                return $vaultHttpClient;
            },
        );
        $vaultHttpClient->method('sendRequest')->willReturnCallback(fn() => $this->createJsonResponseMock([], 200));

        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $vault->method('retrieve')->willReturn('test-secret');
        $vault->method('http')->willReturn($vaultHttpClient);

        $extConf = self::createStub(ExtensionConfiguration::class);
        $extConf->method('get')->willReturn(['apiKeyIdentifier' => 'vault-id', 'baseUrl' => 'https://api.test/v1']);

        $subject = new TestableSpecializedService(
            vault: $vault,
            requestFactory: $this->passthroughRequestFactory(),
            streamFactory: $this->passthroughStreamFactory(),
            extensionConfiguration: $extConf,
            usageTracker: self::createStub(UsageTrackerServiceInterface::class),
            logger: self::createStub(LoggerInterface::class),
            costCalculator: self::createStub(SpecializedCostCalculatorInterface::class),
            budgetService: new AllowingBudgetService(),
        );

        $subject->callSendJsonRequest('endpoint', []);

        self::assertSame('vault-id', $capturedIdentifier);
        self::assertSame(SecretPlacement::Bearer, $capturedPlacement);
        self::assertSame([], $capturedOptions);
        self::assertNotNull($capturedReason);
        self::assertNotSame('', $capturedReason);
    }

    #[Test]
    public function getSecureClientAppliesDefaultTimeoutWhenVaultClientSupportsIt(): void
    {
        // Without an ext-conf override, the wire timeout must be the
        // service's getDefaultTimeout() (42 for the testable fixture) —
        // previously $this->timeout never reached the secure client and
        // the host's global HTTP.timeout silently applied instead.
        $capturedSeconds = null;
        $client = $this->createTimeoutCapableVaultClientStub($capturedSeconds);

        $subject = $this->createSubjectWithVaultClient(
            $client,
            ['apiKeyIdentifier' => 'vault-id', 'baseUrl' => 'https://api.test/v1'],
        );

        $subject->callSendJsonRequest('endpoint', []);

        self::assertSame(42, $capturedSeconds);
    }

    #[Test]
    public function getSecureClientPrefersExtensionConfiguredTimeoutOverDefault(): void
    {
        // The ext-conf per-service timeout override must win over
        // getDefaultTimeout() AND reach the wire via withTimeout().
        $capturedSeconds = null;
        $client = $this->createTimeoutCapableVaultClientStub($capturedSeconds);

        $subject = $this->createSubjectWithVaultClient(
            $client,
            ['apiKeyIdentifier' => 'vault-id', 'baseUrl' => 'https://api.test/v1', 'timeout' => 300],
        );

        $subject->callSendJsonRequest('endpoint', []);

        self::assertSame(300, $capturedSeconds);
    }

    #[Test]
    public function getSecureClientSkipsTimeoutWitherForNonPositiveTimeout(): void
    {
        // Non-positive = "no override" per the wither's contract: the
        // wither must not be called at all, even on a capable client.
        $client = $this->createMock(VaultHttpClientInterface::class);
        $client->expects(self::never())->method('withTimeout');
        $client->method('withAuthentication')->willReturn($client);
        $client->method('withReason')->willReturn($client);
        $client->method('sendRequest')->willReturnCallback(fn() => $this->createJsonResponseMock([], 200));

        $subject = $this->createSubjectWithVaultClient(
            $client,
            ['apiKeyIdentifier' => 'vault-id', 'baseUrl' => 'https://api.test/v1', 'timeout' => 0],
        );

        $subject->callSendJsonRequest('endpoint', []);
    }

    #[Test]
    public function auditReasonDefaultsToProviderLabelApiCall(): void
    {
        $subject = $this->createSubject();

        self::assertSame('TESTABLE API call', $subject->callGetAuditReason());
    }

    #[Test]
    public function auditReasonCarriesModelAndPurposeContextWhenSet(): void
    {
        $subject = $this->createSubject();

        $subject->callSetAuditContext('gpt-image-2, generate');

        self::assertSame('TESTABLE API call (gpt-image-2, generate)', $subject->callGetAuditReason());
    }

    #[Test]
    public function auditContextOfLatestRequestWins(): void
    {
        // The context is per-request state: a later setAuditContext()
        // replaces the earlier one entirely.
        $subject = $this->createSubject();

        $subject->callSetAuditContext('tts-1, voice nova');
        $subject->callSetAuditContext('tts-1-hd, voice alloy');

        self::assertSame('TESTABLE API call (tts-1-hd, voice alloy)', $subject->callGetAuditReason());
    }

    #[Test]
    public function executeRequestThrowsConfigurationExceptionOn401(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => ['message' => 'invalid key']], 401));

        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        $this->expectException(ServiceConfigurationException::class);

        $subject->callSendJsonRequest('endpoint', []);
    }

    #[Test]
    public function executeRequestThrowsConfigurationExceptionOn403(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => ['message' => 'forbidden']], 403));

        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        $this->expectException(ServiceConfigurationException::class);

        $subject->callSendJsonRequest('endpoint', []);
    }

    #[Test]
    public function executeRequestThrowsRateLimitOn429(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => ['message' => 'too many requests']], 429));

        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        try {
            $subject->callSendJsonRequest('endpoint', []);
            self::fail('Expected ServiceUnavailableException');
        } catch (ServiceUnavailableException $e) {
            self::assertStringContainsString('rate limit', $e->getMessage());
        }
    }

    #[Test]
    public function executeRequestExtractsErrorMessageFromOpenAiShape(): void
    {
        // `{"error": {"message": "..."}}` is the most common shape;
        // `decodeErrorMessage()` handles it by default. This is the
        // fallback for any subclass that doesn't override.
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createJsonResponseMock(['error' => ['message' => 'bad request — prompt empty']], 400));

        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        try {
            $subject->callSendJsonRequest('endpoint', []);
            self::fail('Expected ServiceUnavailableException');
        } catch (ServiceUnavailableException $e) {
            self::assertStringContainsString('bad request — prompt empty', $e->getMessage());
        }
    }

    #[Test]
    public function executeRequestWrapsTransportExceptionAsServiceUnavailable(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willThrowException(new RuntimeException('connection reset'));

        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        try {
            $subject->callSendJsonRequest('endpoint', []);
            self::fail('Expected ServiceUnavailableException');
        } catch (ServiceUnavailableException $e) {
            self::assertStringContainsString('Failed to connect', $e->getMessage());
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    #[Test]
    public function executeRequestReturnsEmptyArrayForEmpty2xxBody(): void
    {
        // Some endpoints (e.g. TTS) return binary or empty bodies on
        // success — the JSON decode path must not blow up.
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturn($this->createHttpResponseMock(204, ''));

        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        $result = $subject->callSendJsonRequest('endpoint', []);

        self::assertSame([], $result);
    }

    #[Test]
    public function loadConfigurationFailsSafelyOnException(): void
    {
        // Throws inside ExtensionConfiguration->get() — the service
        // must come up uncrashed with `isAvailable() === false` so
        // callers get a graceful unavailable error rather than a
        // bootstrap fatal.
        $extConf = self::createStub(ExtensionConfiguration::class);
        $extConf->method('get')->willThrowException(new RuntimeException('boom'));

        $subject = new TestableSpecializedService(
            vault: $this->createVaultServiceMock(),
            requestFactory: $this->passthroughRequestFactory(),
            streamFactory: $this->passthroughStreamFactory(),
            extensionConfiguration: $extConf,
            usageTracker: self::createStub(UsageTrackerServiceInterface::class),
            logger: self::createStub(LoggerInterface::class),
            costCalculator: self::createStub(SpecializedCostCalculatorInterface::class),
            budgetService: new AllowingBudgetService(),
        );

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function multipartTraitBuildsExpectedBoundaryAndBody(): void
    {
        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test');

        $body = $subject->callEncodeMultipartBody([
            ['name' => 'file', 'filename' => 'a.bin', 'content' => 'BIN', 'contentType' => 'application/octet-stream'],
            ['name' => 'model', 'value' => 'whisper-1'],
        ], 'BOUNDARY');

        self::assertStringContainsString('--BOUNDARY', $body);
        self::assertStringContainsString('Content-Disposition: form-data; name="file"; filename="a.bin"', $body);
        self::assertStringContainsString('Content-Type: application/octet-stream', $body);
        self::assertStringContainsString('BIN', $body);
        self::assertStringContainsString('Content-Disposition: form-data; name="model"', $body);
        self::assertStringContainsString('whisper-1', $body);
        self::assertStringEndsWith("--BOUNDARY--\r\n", $body);
    }

    #[Test]
    public function sendJsonRequestSkipsBodyForBodylessMethods(): void
    {
        // Regression: GET-with-body requests are non-standard and
        // some upstreams / proxies reject them outright. The base
        // must NOT attach a JSON body when the method is GET / HEAD /
        // DELETE, even if `$payload` is non-empty.
        $captured = null;
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request) use (&$captured) {
                $captured = $request;
                return $this->createJsonResponseMock([], 200);
            });

        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test', httpClient: $httpClient);

        $subject->callSendJsonRequest('endpoint', ['ignored' => 'on-get'], 'GET');

        self::assertNotNull($captured);
        self::assertInstanceOf(TestableRequest::class, $captured);
        // wasBodySet() tracks whether withBody() was ever called
        // on the TestableRequest; for GET it should not have been.
        self::assertFalse($captured->wasBodySet());
    }

    #[Test]
    public function loadConfigurationIsResilientAgainstTypeError(): void
    {
        // Regression: previous catch was `Exception` only, but
        // `TypeError` extends `Error`. A subclass parsing a malformed
        // config that flips a property assignment from string to int
        // would have raised a bootstrap fatal. Now it degrades to
        // `isAvailable() === false`.
        $extConf = self::createStub(ExtensionConfiguration::class);
        $extConf->method('get')->willReturn(['apiKeyIdentifier' => 12345, 'baseUrl' => 'https://api.test']);

        $subject = new TestableTypeErrorSpecializedService(
            vault: $this->createVaultServiceMock(),
            requestFactory: $this->passthroughRequestFactory(),
            streamFactory: $this->passthroughStreamFactory(),
            extensionConfiguration: $extConf,
            usageTracker: self::createStub(UsageTrackerServiceInterface::class),
            logger: self::createStub(LoggerInterface::class),
            costCalculator: self::createStub(SpecializedCostCalculatorInterface::class),
            budgetService: new AllowingBudgetService(),
        );

        self::assertFalse($subject->isAvailable());
    }

    #[Test]
    public function multipartTraitStripsCrLfAndQuoteFromHeaderValues(): void
    {
        // Regression for the header-injection concern: untrusted
        // filename / name / contentType values must have CR / LF /
        // double-quote stripped before they land in the
        // `Content-Disposition` / `Content-Type` headers, otherwise
        // an attacker can inject arbitrary headers or break the
        // body framing. The literal text "X-Injected: yes" may still
        // appear AS DATA inside the value (CR/LF removed → no header
        // boundary), but it must NOT appear on its own line.
        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test');

        $body = $subject->callEncodeMultipartBody([
            [
                'name'        => "evil\r\nX-Injected: yes",
                'filename'    => 'a".bin',
                'content'     => 'BIN',
                'contentType' => "image/png\r\nX-Forged: 1",
            ],
        ], 'BOUNDARY');

        // The smoking-gun assertions: no CR before the injected
        // header text, no LF before it. This catches the actual
        // injection vector (header-on-its-own-line) while permitting
        // the now-defanged literal text to remain inside the value.
        self::assertStringNotContainsString("\r\nX-Injected", $body);
        self::assertStringNotContainsString("\nX-Injected", $body);
        self::assertStringNotContainsString("\r\nX-Forged", $body);
        // No bare double-quote that would break the `name="..."` /
        // `filename="..."` framing.
        self::assertStringNotContainsString('filename="a".bin"', $body);
        // Content survives, headers are clean.
        self::assertStringContainsString('BIN', $body);
        self::assertStringEndsWith("--BOUNDARY--\r\n", $body);
    }

    #[Test]
    public function multipartTraitSkipsPartsMissingName(): void
    {
        // Defensive: a caller that hands us a malformed part dict
        // shouldn't poison the entire body. The part is silently
        // skipped (rather than throwing) so the surrounding parts
        // still produce a valid body.
        $subject = $this->createSubject(apiKeyIdentifier: 'k', baseUrl: 'https://api.test');

        $body = $subject->callEncodeMultipartBody([
            ['name' => 'good', 'value' => 'x'],
            ['value' => 'orphan'],          // missing name → skipped
            ['name' => 'also-good', 'value' => 'y'],
        ], 'B');

        self::assertStringContainsString('name="good"', $body);
        self::assertStringContainsString('name="also-good"', $body);
        self::assertStringNotContainsString('orphan', $body);
    }

    #[Test]
    public function resolveDefaultModelForReturnsFallbackWithoutRepository(): void
    {
        // "No repository in context" is a legal state (e.g. a manually
        // constructed service): the fallback must come back unchanged.
        $subject = $this->createSubject();

        self::assertSame('fallback-model', $subject->callResolveDefaultModelFor(ModelCapability::IMAGE, 'fallback-model'));
    }

    #[Test]
    public function resolveDefaultModelForPrefersDefaultFlaggedRecord(): void
    {
        // The default-flagged record wins even when a lower-sorting
        // record precedes it in the result.
        $lowerSorting = new Model();
        $lowerSorting->setModelId('gpt-image-1');
        $default = new Model();
        $default->setModelId('gpt-image-2');
        $default->setIsDefault(true);

        $repository = $this->createMock(ModelRepository::class);
        $repository->expects(self::once())
            ->method('findByCapability')
            ->with(ModelCapability::IMAGE->value)
            ->willReturn(new InMemoryQueryResult([$lowerSorting, $default]));

        $subject = $this->createSubject(modelRepository: $repository);

        self::assertSame('gpt-image-2', $subject->callResolveDefaultModelFor(ModelCapability::IMAGE, 'dall-e-3'));
    }

    #[Test]
    public function resolveDefaultModelForFallsBackToLowestSortingRecordWithoutDefaultFlag(): void
    {
        // findByCapability() returns records ordered by sorting — the
        // first usable one wins when no record is flagged as default.
        $first = new Model();
        $first->setModelId('tts-1-hd');
        $second = new Model();
        $second->setModelId('tts-1');

        $repository = self::createStub(ModelRepository::class);
        $repository->method('findByCapability')
            ->willReturn(new InMemoryQueryResult([$first, $second]));

        $subject = $this->createSubject(modelRepository: $repository);

        self::assertSame('tts-1-hd', $subject->callResolveDefaultModelFor(ModelCapability::TEXT_TO_SPEECH, 'tts-1'));
    }

    #[Test]
    public function resolveDefaultModelForSkipsRecordsWithoutModelId(): void
    {
        // A registry record without a model id cannot be sent to an
        // upstream API — it must never be returned as the default.
        $unusable = new Model();
        $usable = new Model();
        $usable->setModelId('whisper-1');

        $repository = self::createStub(ModelRepository::class);
        $repository->method('findByCapability')
            ->willReturn(new InMemoryQueryResult([$unusable, $usable]));

        $subject = $this->createSubject(modelRepository: $repository);

        self::assertSame('whisper-1', $subject->callResolveDefaultModelFor(ModelCapability::TRANSCRIPTION, 'fallback'));
    }

    #[Test]
    public function resolveDefaultModelForReturnsFallbackWhenNoRecordMatches(): void
    {
        $repository = self::createStub(ModelRepository::class);
        $repository->method('findByCapability')
            ->willReturn(new InMemoryQueryResult([]));

        $subject = $this->createSubject(modelRepository: $repository);

        self::assertSame('dall-e-3', $subject->callResolveDefaultModelFor(ModelCapability::IMAGE, 'dall-e-3'));
    }

    #[Test]
    public function resolveDefaultModelForReturnsFallbackWhenRepositoryThrows(): void
    {
        // Extbase persistence may be unavailable (early CLI bootstrap):
        // resolution is fail-soft and must never throw.
        $repository = self::createStub(ModelRepository::class);
        $repository->method('findByCapability')
            ->willThrowException(new RuntimeException('persistence unavailable'));

        $subject = $this->createSubject(modelRepository: $repository);

        self::assertSame('dall-e-3', $subject->callResolveDefaultModelFor(ModelCapability::IMAGE, 'dall-e-3'));
    }

    #[Test]
    public function resolveModelUidReturnsUidOfMatchingRecord(): void
    {
        $record = new Model();
        $record->setModelId('gpt-image-2');
        $record->_setProperty('uid', 42);

        $repository = $this->createMock(ModelRepository::class);
        $repository->expects(self::once())
            ->method('findOneByModelId')
            ->with('gpt-image-2')
            ->willReturn($record);

        $subject = $this->createSubject(modelRepository: $repository);

        self::assertSame(42, $subject->callResolveModelUid('gpt-image-2'));
    }

    #[Test]
    public function resolveModelUidReturnsZeroWithoutRepository(): void
    {
        $subject = $this->createSubject();

        self::assertSame(0, $subject->callResolveModelUid('gpt-image-2'));
    }

    #[Test]
    public function resolveModelUidReturnsZeroForEmptyModelIdWithoutQuerying(): void
    {
        $repository = $this->createMock(ModelRepository::class);
        $repository->expects(self::never())->method('findOneByModelId');

        $subject = $this->createSubject(modelRepository: $repository);

        self::assertSame(0, $subject->callResolveModelUid(''));
    }

    #[Test]
    public function resolveModelUidReturnsZeroWhenNoRecordMatches(): void
    {
        $repository = self::createStub(ModelRepository::class);
        $repository->method('findOneByModelId')->willReturn(null);

        $subject = $this->createSubject(modelRepository: $repository);

        self::assertSame(0, $subject->callResolveModelUid('unknown-model'));
    }

    #[Test]
    public function resolveModelUidReturnsZeroWhenRepositoryThrows(): void
    {
        $repository = self::createStub(ModelRepository::class);
        $repository->method('findOneByModelId')
            ->willThrowException(new RuntimeException('persistence unavailable'));

        $subject = $this->createSubject(modelRepository: $repository);

        self::assertSame(0, $subject->callResolveModelUid('gpt-image-2'));
    }

    #[Test]
    public function resolveConfiguredModelForReturnsConfiguredModelId(): void
    {
        // An active configuration with an active, usable model wins
        // outright — the capability-based registry default is not
        // consulted at all.
        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('alt-text-images')
            ->willReturn($this->createConfiguration(modelId: 'gpt-image-2'));

        $modelRepository = $this->createMock(ModelRepository::class);
        $modelRepository->expects(self::never())->method('findByCapability');

        $subject = $this->createSubject(
            modelRepository: $modelRepository,
            configurationRepository: $configurationRepository,
        );

        self::assertSame(
            'gpt-image-2',
            $subject->callResolveConfiguredModelFor(ModelCapability::IMAGE, 'alt-text-images', 'dall-e-3'),
        );
    }

    #[Test]
    public function resolveConfiguredModelForFallsBackToCapabilityDefaultForInactiveConfiguration(): void
    {
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')
            ->willReturn($this->createConfiguration(modelId: 'gpt-image-2', active: false));

        $registryDefault = new Model();
        $registryDefault->setModelId('gpt-image-1');
        $modelRepository = self::createStub(ModelRepository::class);
        $modelRepository->method('findByCapability')
            ->willReturn(new InMemoryQueryResult([$registryDefault]));

        $subject = $this->createSubject(
            modelRepository: $modelRepository,
            configurationRepository: $configurationRepository,
        );

        self::assertSame(
            'gpt-image-1',
            $subject->callResolveConfiguredModelFor(ModelCapability::IMAGE, 'alt-text-images', 'dall-e-3'),
        );
    }

    #[Test]
    public function resolveConfiguredModelForFallsBackToCapabilityDefaultForUnknownIdentifier(): void
    {
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')->willReturn(null);

        $registryDefault = new Model();
        $registryDefault->setModelId('gpt-image-1');
        $modelRepository = self::createStub(ModelRepository::class);
        $modelRepository->method('findByCapability')
            ->willReturn(new InMemoryQueryResult([$registryDefault]));

        $subject = $this->createSubject(
            modelRepository: $modelRepository,
            configurationRepository: $configurationRepository,
        );

        self::assertSame(
            'gpt-image-1',
            $subject->callResolveConfiguredModelFor(ModelCapability::IMAGE, 'unknown', 'dall-e-3'),
        );
    }

    #[Test]
    public function resolveConfiguredModelForSkipsConfiguredModelWithoutModelId(): void
    {
        // A model record without a model id cannot be sent to an
        // upstream API — resolution continues down the chain.
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')
            ->willReturn($this->createConfiguration(modelId: ''));

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame(
            'dall-e-3',
            $subject->callResolveConfiguredModelFor(ModelCapability::IMAGE, 'alt-text-images', 'dall-e-3'),
        );
    }

    #[Test]
    public function resolveConfiguredModelForSkipsInactiveConfiguredModel(): void
    {
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')
            ->willReturn($this->createConfiguration(modelId: 'gpt-image-2', modelActive: false));

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame(
            'dall-e-3',
            $subject->callResolveConfiguredModelFor(ModelCapability::IMAGE, 'alt-text-images', 'dall-e-3'),
        );
    }

    #[Test]
    public function resolveConfiguredModelForReturnsFallbackWithoutRepositories(): void
    {
        $subject = $this->createSubject();

        self::assertSame(
            'dall-e-3',
            $subject->callResolveConfiguredModelFor(ModelCapability::IMAGE, 'alt-text-images', 'dall-e-3'),
        );
    }

    #[Test]
    public function resolveConfiguredModelForReturnsFallbackWhenRepositoryThrows(): void
    {
        // Extbase persistence may be unavailable (early CLI bootstrap):
        // resolution is fail-soft and must never throw.
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')
            ->willThrowException(new RuntimeException('persistence unavailable'));

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame(
            'dall-e-3',
            $subject->callResolveConfiguredModelFor(ModelCapability::IMAGE, 'alt-text-images', 'dall-e-3'),
        );
    }

    #[Test]
    public function getConfigurationSystemPromptReturnsPromptOfActiveConfiguration(): void
    {
        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('alt-text-images')
            ->willReturn($this->createConfiguration(systemPrompt: 'Describe the image for screen readers.'));

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame(
            'Describe the image for screen readers.',
            $subject->getConfigurationSystemPrompt('alt-text-images'),
        );
    }

    #[Test]
    public function getConfigurationSystemPromptReturnsEmptyStringForInactiveConfiguration(): void
    {
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')
            ->willReturn($this->createConfiguration(systemPrompt: 'Hidden prompt.', active: false));

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame('', $subject->getConfigurationSystemPrompt('alt-text-images'));
    }

    #[Test]
    public function getConfigurationSystemPromptReturnsEmptyStringForUnknownIdentifier(): void
    {
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')->willReturn(null);

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame('', $subject->getConfigurationSystemPrompt('unknown'));
    }

    #[Test]
    public function getConfigurationSystemPromptReturnsEmptyStringWithoutRepository(): void
    {
        $subject = $this->createSubject();

        self::assertSame('', $subject->getConfigurationSystemPrompt('alt-text-images'));
    }

    #[Test]
    public function getConfigurationSystemPromptReturnsEmptyStringWhenRepositoryThrows(): void
    {
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')
            ->willThrowException(new RuntimeException('persistence unavailable'));

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame('', $subject->getConfigurationSystemPrompt('alt-text-images'));
    }

    #[Test]
    public function resolveConfigurationUidReturnsUidOfActiveConfiguration(): void
    {
        $configuration = $this->createConfiguration();
        $configuration->_setProperty('uid', 12);

        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->expects(self::once())
            ->method('findOneByIdentifier')
            ->with('alt-text-images')
            ->willReturn($configuration);

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertSame(12, $subject->callResolveConfigurationUid('alt-text-images'));
    }

    #[Test]
    public function resolveConfigurationUidReturnsNullForNullIdentifierWithoutQuerying(): void
    {
        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->expects(self::never())->method('findOneByIdentifier');

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertNull($subject->callResolveConfigurationUid(null));
    }

    #[Test]
    public function resolveConfigurationUidReturnsNullForEmptyIdentifierWithoutQuerying(): void
    {
        $configurationRepository = $this->createMock(LlmConfigurationRepository::class);
        $configurationRepository->expects(self::never())->method('findOneByIdentifier');

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertNull($subject->callResolveConfigurationUid(''));
    }

    #[Test]
    public function resolveConfigurationUidReturnsNullWithoutRepository(): void
    {
        $subject = $this->createSubject();

        self::assertNull($subject->callResolveConfigurationUid('alt-text-images'));
    }

    #[Test]
    public function resolveConfigurationUidReturnsNullForInactiveConfiguration(): void
    {
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')
            ->willReturn($this->createConfiguration(active: false));

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertNull($subject->callResolveConfigurationUid('alt-text-images'));
    }

    #[Test]
    public function resolveConfigurationUidReturnsNullWhenRepositoryThrows(): void
    {
        $configurationRepository = self::createStub(LlmConfigurationRepository::class);
        $configurationRepository->method('findOneByIdentifier')
            ->willThrowException(new RuntimeException('persistence unavailable'));

        $subject = $this->createSubject(configurationRepository: $configurationRepository);

        self::assertNull($subject->callResolveConfigurationUid('alt-text-images'));
    }

    /**
     * Build an LlmConfiguration fixture. `$modelId === null` leaves the
     * configuration without a model relation; otherwise a Model record
     * with the given id and active flag is attached.
     */
    private function createConfiguration(
        ?string $modelId = null,
        bool $active = true,
        bool $modelActive = true,
        string $systemPrompt = '',
    ): LlmConfiguration {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier('alt-text-images');
        $configuration->setIsActive($active);
        $configuration->setSystemPrompt($systemPrompt);

        if ($modelId !== null) {
            $model = new Model();
            $model->setModelId($modelId);
            $model->setIsActive($modelActive);
            $configuration->setLlmModel($model);
        }

        return $configuration;
    }

    private function createSubject(
        string $apiKeyIdentifier = 'test-key',
        string $baseUrl = 'https://api.example.test',
        ?ClientInterface $httpClient = null,
        ?ModelRepository $modelRepository = null,
        ?LlmConfigurationRepository $configurationRepository = null,
        ?BudgetServiceInterface $budgetService = null,
    ): TestableSpecializedService {
        $extConf = self::createStub(ExtensionConfiguration::class);
        $extConf->method('get')->willReturn(['apiKeyIdentifier' => $apiKeyIdentifier, 'baseUrl' => $baseUrl]);

        $subject = new TestableSpecializedService(
            vault: $this->createVaultServiceMock(),
            requestFactory: $this->passthroughRequestFactory(),
            streamFactory: $this->passthroughStreamFactory(),
            extensionConfiguration: $extConf,
            usageTracker: self::createStub(UsageTrackerServiceInterface::class),
            logger: self::createStub(LoggerInterface::class),
            costCalculator: self::createStub(SpecializedCostCalculatorInterface::class),
            budgetService: $budgetService ?? new AllowingBudgetService(),
            modelRepository: $modelRepository,
            configurationRepository: $configurationRepository,
        );

        // Inject the plain test client through the test seam; this bypasses the
        // vault secure client so request/response assertions can read the
        // request the service built (mirrors the provider tests).
        $subject->setHttpClient($httpClient ?? self::createStub(ClientInterface::class));

        return $subject;
    }

    /**
     * Timeout-capable vault client stub whose `withTimeout()` argument is
     * captured into `$capturedSeconds` (stays null when never called).
     */
    private function createTimeoutCapableVaultClientStub(?int &$capturedSeconds): VaultHttpClientInterface
    {
        $client = self::createStub(VaultHttpClientInterface::class);
        $client->method('withAuthentication')->willReturn($client);
        $client->method('withReason')->willReturn($client);
        $client->method('withTimeout')->willReturnCallback(
            static function (int $seconds) use (&$capturedSeconds, $client): VaultHttpClientInterface {
                $capturedSeconds = $seconds;
                return $client;
            },
        );
        $client->method('sendRequest')->willReturnCallback(fn() => $this->createJsonResponseMock([], 200));

        return $client;
    }

    /**
     * Build a subject whose vault `http()` returns the given client and that
     * deliberately does NOT use `setHttpClient()` — the test seam bypasses
     * `getSecureClient()` entirely, so timeout behaviour must be asserted on
     * the vault path.
     *
     * @param array<string, mixed> $config
     */
    private function createSubjectWithVaultClient(
        VaultHttpClientInterface $vaultHttpClient,
        array $config,
    ): TestableSpecializedService {
        $vault = self::createStub(VaultServiceInterface::class);
        $vault->method('exists')->willReturn(true);
        $vault->method('http')->willReturn($vaultHttpClient);

        $extConf = self::createStub(ExtensionConfiguration::class);
        $extConf->method('get')->willReturn($config);

        return new TestableSpecializedService(
            vault: $vault,
            requestFactory: $this->passthroughRequestFactory(),
            streamFactory: $this->passthroughStreamFactory(),
            extensionConfiguration: $extConf,
            usageTracker: self::createStub(UsageTrackerServiceInterface::class),
            logger: self::createStub(LoggerInterface::class),
            costCalculator: self::createStub(SpecializedCostCalculatorInterface::class),
            budgetService: new AllowingBudgetService(),
        );
    }

    private function passthroughRequestFactory(): RequestFactoryInterface
    {
        $stub = self::createStub(RequestFactoryInterface::class);
        $stub->method('createRequest')
            ->willReturnCallback(static function (string $method, mixed $uri): RequestInterface {
                if (is_string($uri)) {
                    $uriString = $uri;
                } elseif (is_object($uri) && method_exists($uri, '__toString')) {
                    $uriString = $uri->__toString();
                } else {
                    $uriString = '';
                }
                return new TestableRequest($method, $uriString);
            });
        return $stub;
    }

    private function passthroughStreamFactory(): StreamFactoryInterface
    {
        $stub = self::createStub(StreamFactoryInterface::class);
        $stub->method('createStream')->willReturnCallback(function (string $content): StreamInterface {
            $stream = $this->createStub(StreamInterface::class);
            $stream->method('__toString')->willReturn($content);
            $stream->method('getContents')->willReturn($content);
            return $stream;
        });
        return $stub;
    }
}

/**
 * Concrete fixture exercising the abstract base. Public delegates
 * (`callX()`) expose protected members for assertion convenience.
 */
final class TestableSpecializedService extends AbstractSpecializedService
{
    use MultipartBodyBuilderTrait;

    public function callEnsureAvailable(): void
    {
        $this->ensureAvailable();
    }

    public function callEnforceBudget(?int $beUserUid, ?float $plannedCost, ?string $configurationIdentifier = null): void
    {
        $this->enforceBudget($beUserUid, $plannedCost, $configurationIdentifier);
    }

    public function callBuildEndpointUrl(string $endpoint): string
    {
        return $this->buildEndpointUrl($endpoint);
    }

    public function callSetAuditContext(string $context): void
    {
        $this->setAuditContext($context);
    }

    public function callGetAuditReason(): string
    {
        return $this->getAuditReason();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function callSendJsonRequest(string $endpoint, array $payload, string $method = 'POST'): array
    {
        return $this->sendJsonRequest($endpoint, $payload, $method);
    }

    /**
     * @param list<array<string, mixed>> $parts
     */
    public function callEncodeMultipartBody(array $parts, string $boundary): string
    {
        return $this->encodeMultipartBody($parts, $boundary);
    }

    public function callResolveDefaultModelFor(ModelCapability $capability, string $fallback): string
    {
        return $this->resolveDefaultModelFor($capability, $fallback);
    }

    public function callResolveModelUid(string $modelId): int
    {
        return $this->resolveModelUid($modelId);
    }

    public function callResolveConfiguredModelFor(
        ModelCapability $capability,
        string $configurationIdentifier,
        string $fallback,
    ): string {
        return $this->resolveConfiguredModelFor($capability, $configurationIdentifier, $fallback);
    }

    public function callResolveConfigurationUid(?string $configurationIdentifier): ?int
    {
        return $this->resolveConfigurationUid($configurationIdentifier);
    }

    protected function getServiceDomain(): string
    {
        return 'test';
    }

    protected function getServiceProvider(): string
    {
        return 'testable';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.example.test';
    }

    protected function getDefaultTimeout(): int
    {
        return 42;
    }

    protected function loadServiceConfiguration(array $config): void
    {
        $this->apiKeyIdentifier = is_string($config['apiKeyIdentifier'] ?? null) ? $config['apiKeyIdentifier'] : '';
        $this->baseUrl          = is_string($config['baseUrl'] ?? null) ? $config['baseUrl'] : $this->getDefaultBaseUrl();
        // Timeout override semantics mirror the real services
        // (ServiceConfigurationTrait::loadOpenAiServiceConfiguration()).
        $timeout       = $config['timeout'] ?? null;
        $this->timeout = is_numeric($timeout) ? (int)$timeout : $this->getDefaultTimeout();
    }
}

/**
 * Real-ish RequestInterface implementation for tests — captures
 * headers / body so assertions can read them back. Trimmed to the
 * subset the base class exercises.
 */
final class TestableRequest implements RequestInterface
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    private ?StreamInterface $body = null;

    private bool $bodyWasSet = false;

    public function __construct(
        private readonly string $method,
        private readonly string $uri,
    ) {}

    public function withHeader(string $name, $value): static
    {
        $clone = clone $this;
        $clone->headers[$name] = array_values(is_array($value) ? array_map(strval(...), $value) : [(string)$value]);
        return $clone;
    }

    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        $clone->bodyWasSet = true;
        return $clone;
    }

    public function wasBodySet(): bool
    {
        return $this->bodyWasSet;
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->headers[$name] ?? []);
    }

    /**
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getRequestTarget(): string
    {
        return $this->uri;
    }

    /**
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        return $this->headers[$name] ?? [];
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    public function getBody(): StreamInterface
    {
        if ($this->body === null) {
            throw new LogicException('No body set', 3134810639);
        }
        return $this->body;
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }
    public function withProtocolVersion(string $version): static
    {
        return $this;
    }
    public function withAddedHeader(string $name, $value): static
    {
        return $this->withHeader($name, $value);
    }
    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        unset($clone->headers[$name]);
        return $clone;
    }
    public function withRequestTarget(string $requestTarget): static
    {
        return $this;
    }
    public function withMethod(string $method): static
    {
        return $this;
    }
    public function getUri(): UriInterface
    {
        throw new LogicException('Not implemented', 4146456712);
    }
    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        return $this;
    }
}

/**
 * Fixture whose `loadServiceConfiguration()` deliberately raises a
 * `TypeError` (assigning an int to a `string`-typed property). Used
 * to verify the base catches `Throwable` rather than `Exception`.
 */
final class TestableTypeErrorSpecializedService extends AbstractSpecializedService
{
    protected function getServiceDomain(): string
    {
        return 'test';
    }

    protected function getServiceProvider(): string
    {
        return 'typeerror';
    }

    protected function getDefaultBaseUrl(): string
    {
        return 'https://api.example.test';
    }

    protected function getDefaultTimeout(): int
    {
        return 30;
    }

    protected function loadServiceConfiguration(array $config): void
    {
        // Direct assignment without is_string() guard — TypeError when
        // the config value is not a string. Mirrors the bug Copilot
        // caught on PR #186 (DallE / DeepL `loadServiceConfiguration`
        // before the fix).
        /** @phpstan-ignore assign.propertyType */
        $this->apiKeyIdentifier = $config['apiKeyIdentifier']; // @phpstan-ignore-line
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Contract;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Provider\AbstractProvider;
use Netresearch\NrLlm\Provider\Contract\DocumentCapableInterface;
use Netresearch\NrLlm\Provider\Contract\StreamingCapableInterface;
use Netresearch\NrLlm\Provider\Contract\ToolCapableInterface;
use Netresearch\NrLlm\Provider\Contract\VisionCapableInterface;
use Netresearch\NrLlm\Provider\Exception\ProviderAuthenticationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConfigurationException;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Exception\ProviderException;
use Netresearch\NrLlm\Provider\Exception\ProviderRateLimitException;
use Netresearch\NrLlm\Provider\Exception\ProviderResponseException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * The contract every provider adapter answers to (ADR-160).
 *
 * Before this class each adapter had its own test and nothing compared them,
 * so what "an adapter does" drifted per adapter and no artefact said where.
 * Every bundled adapter extends this case, which fixes seven things for all of
 * them: the identifier, the capability declaration, error normalisation per
 * exception type, refusing to send without a credential, timeout behaviour,
 * usage reporting, and — where the adapter declares the capability — the shape
 * of a tool call and of a structured-output request.
 *
 * Three rules keep it a contract rather than a lowest common denominator:
 *
 * 1. **A capability an adapter does not have is skipped BY NAME.** The tool
 *    and document contracts call `markTestSkipped()` with the reason instead
 *    of being silently absent, so a reader of the run can tell "cannot" from
 *    "not tested". That distinction is the point of the suite.
 * 2. **A deliberate deviation is declared, not tolerated.** The three hooks
 *    {@see expectedServerErrorException()}, {@see retriesTransportFailures()}
 *    and {@see requiresApiKey()} carry the differences that are real: the
 *    first two because `OpenRouterProvider` sends through its own request
 *    path, the third because a local Ollama needs no credential. Overriding
 *    one is a statement in the subclass, which is where the deviation is now
 *    written down.
 * 3. **No live calls.** Every adapter is driven through an injected PSR-18
 *    double, the same way the integration tests do it.
 *
 * Streaming is covered by the declaration contract only. An SSE fixture is
 * dialect-specific enough that a shared one would assert the fixture rather
 * than the adapter, and each adapter's own test already carries one.
 */
abstract class AbstractAdapterContractTestCase extends AbstractUnitTestCase
{
    /**
     * A schema inside OpenAI's strict profile: object root, every property
     * required, `additionalProperties: false`, allowlisted keywords only.
     *
     * @var array<string, mixed>
     */
    protected const STRICT_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'title' => ['type' => 'string'],
        ],
        'required' => ['title'],
    ];

    /**
     * A valid ADR-126 schema OUTSIDE the strict profile: `minLength` is not on
     * the strict allowlist. Sending it as a provider-enforced strict contract
     * is what earns an HTTP 400 from OpenAI, so the adapter has to degrade.
     *
     * @var array<string, mixed>
     */
    protected const UNSUPPORTED_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'title' => ['type' => 'string', 'minLength' => 1],
        ],
        'required' => ['title'],
    ];

    protected RequestFactoryInterface $requestFactory;

    protected StreamFactoryInterface $streamFactory;

    /** @var list<RequestInterface> every request the adapter handed to the client */
    protected array $sentRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestFactory = new HttpFactory();
        $this->streamFactory  = new HttpFactory();
        $this->sentRequests   = [];
    }

    // ------------------------------------------------------------------
    // Seam every adapter fills in
    // ------------------------------------------------------------------

    /**
     * A fresh, unconfigured adapter with the shared collaborator doubles.
     */
    abstract protected function newAdapter(): AbstractProvider;

    /**
     * The value `getIdentifier()` must return — the key operators configure,
     * `#[AsLlmProvider]` registers and `CompletionResponse::$provider` carries.
     */
    abstract protected function expectedIdentifier(): string;

    /**
     * The `configure()` payload this adapter needs to become callable.
     *
     * @return array<string, mixed>
     */
    abstract protected function adapterConfiguration(): array;

    /**
     * A successful chat response in the adapter's own dialect, declaring
     * exactly the given token counts and carrying exactly `$content`.
     *
     * @return array<string, mixed>
     */
    abstract protected function chatResponseBody(int $promptTokens, int $completionTokens, string $content = 'ok'): array;

    /**
     * The same success response with every usage counter removed — a real
     * shape (streaming aggregations and proxies drop it) that must degrade
     * rather than fail.
     *
     * @return array<string, mixed>
     */
    abstract protected function chatResponseBodyWithoutUsage(): array;

    /**
     * A chat response in the adapter's own dialect carrying exactly one call
     * to `get_weather` with `{"city": "Leipzig"}`. Only consulted for
     * adapters implementing {@see ToolCapableInterface}.
     *
     * @return array<string, mixed>
     */
    abstract protected function toolCallResponseBody(): array;

    /**
     * Assert the decoded REQUEST payload enforces `$schema` on the provider
     * side, in whatever the adapter's native structured-output shape is.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $schema
     */
    abstract protected function assertSchemaEnforcedOnTheWire(array $payload, array $schema): void;

    /**
     * The adapter was handed a schema its provider cannot enforce strictly.
     * Assert the payload degrades rather than claiming enforcement the
     * provider would answer with a 400.
     *
     * An adapter whose dialect has no strict/loose distinction MUST call
     * `self::markTestSkipped()` with that reason instead of asserting
     * something vacuous.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $schema
     */
    abstract protected function assertUnsupportedSchemaDegrades(array $payload, array $schema): void;

    // ------------------------------------------------------------------
    // Declared deviations
    // ------------------------------------------------------------------

    /**
     * What a 5xx normalises to. The shared `AbstractProvider::sendRequest()`
     * path produces a connection error; an adapter that maps it elsewhere
     * overrides this and says why in the override's docblock.
     *
     * @return class-string<ProviderException>
     */
    protected function expectedServerErrorException(): string
    {
        return ProviderConnectionException::class;
    }

    /**
     * Whether a transport failure that is NOT a timeout gets the configured
     * retries. An adapter that does not retry overrides this to false; the
     * retry contract then skips by name instead of failing silently.
     */
    protected function retriesTransportFailures(): bool
    {
        return true;
    }

    /**
     * Whether the adapter needs a credential to be callable at all. A
     * keyless provider (a local Ollama) overrides this to false and the
     * configuration contract skips by name; every other adapter has to
     * refuse locally rather than send without one.
     */
    protected function requiresApiKey(): bool
    {
        return true;
    }

    // ------------------------------------------------------------------
    // Contract: identifier
    // ------------------------------------------------------------------

    #[Test]
    public function theIdentifierIsTheStableRegistrationKey(): void
    {
        $adapter = $this->newAdapter();

        self::assertSame($this->expectedIdentifier(), $adapter->getIdentifier());
        self::assertNotSame('', $adapter->getName(), 'An adapter must carry a human-readable name for the backend module.');
    }

    #[Test]
    public function theIdentifierTravelsOnEveryCompletion(): void
    {
        $adapter = $this->configuredAdapter([$this->jsonResponse($this->chatResponseBody(11, 7))]);

        $response = $adapter->chatCompletion([['role' => 'user', 'content' => 'ping']]);

        self::assertSame($this->expectedIdentifier(), $response->provider);
    }

    // ------------------------------------------------------------------
    // Contract: capability declaration
    // ------------------------------------------------------------------

    /**
     * An adapter declares a capability twice — by implementing the interface
     * and by listing the feature — and both declarations are read: the
     * interface by the service layer's `instanceof` gates, the feature list
     * by `LlmServiceManager::supportsFeature()`. They must agree, or an
     * operator reading one gets the opposite answer from the other.
     */
    #[Test]
    public function theCapabilityDeclarationAgreesWithTheImplementedInterfaces(): void
    {
        $adapter = $this->newAdapter();

        foreach ($this->capabilityInterfaces() as $interface => [$capability, $method]) {
            $implemented = $adapter instanceof $interface;

            if ($implemented) {
                self::assertTrue(
                    $adapter->{$method}(),
                    $adapter::class . ' implements ' . $interface . ' but ' . $method . '() denies it.',
                );
            }

            self::assertSame(
                $implemented,
                $adapter->supportsFeature($capability),
                $adapter::class . ' ' . ($implemented ? 'implements' : 'does not implement') . ' ' . $interface
                . ' but supportsFeature(' . $capability->value . ') says the opposite.',
            );
        }
    }

    #[Test]
    public function documentSupportIsDeclaredOnlyByTheAdaptersThatImplementIt(): void
    {
        $adapter = $this->newAdapter();

        if (!$adapter instanceof DocumentCapableInterface) {
            self::markTestSkipped(
                $adapter::class . ' does not implement DocumentCapableInterface — it cannot take documents, '
                . 'so there is no document contract to hold it to.',
            );
        }

        self::assertTrue($adapter->supportsDocuments());
        self::assertNotSame([], $adapter->getSupportedDocumentFormats());
    }

    #[Test]
    public function chatIsTheOneCapabilityEveryAdapterDeclares(): void
    {
        self::assertTrue(
            $this->newAdapter()->supportsFeature(ModelCapability::CHAT),
            'Every adapter serves chat — it is the capability the middleware pipeline assumes.',
        );
    }

    // ------------------------------------------------------------------
    // Contract: error normalisation
    // ------------------------------------------------------------------

    #[Test]
    public function anUnauthorisedResponseNormalisesToTheAuthenticationException(): void
    {
        $this->expectException(ProviderAuthenticationException::class);

        $this->configuredAdapter([$this->jsonResponse(['error' => ['message' => 'Invalid API key']], 401)])
            ->chatCompletion([['role' => 'user', 'content' => 'ping']]);
    }

    #[Test]
    public function aThrottledResponseNormalisesToTheRateLimitException(): void
    {
        $this->expectException(ProviderRateLimitException::class);

        $this->configuredAdapter([$this->jsonResponse(['error' => ['message' => 'Slow down']], 429)])
            ->chatCompletion([['role' => 'user', 'content' => 'ping']]);
    }

    /**
     * Any other 4xx is the base response exception — and it carries the
     * provider's own message, because a normalisation that swallows the text
     * turns every failure into "something went wrong" for the operator.
     */
    #[Test]
    public function anyOtherClientErrorNormalisesToTheResponseExceptionAndKeepsTheMessage(): void
    {
        try {
            $this->configuredAdapter([$this->jsonResponse(['error' => ['message' => 'Context window exceeded']], 400)])
                ->chatCompletion([['role' => 'user', 'content' => 'ping']]);
            self::fail('A 400 must surface as a ProviderResponseException.');
        } catch (ProviderResponseException $e) {
            self::assertNotInstanceOf(ProviderAuthenticationException::class, $e);
            self::assertNotInstanceOf(ProviderRateLimitException::class, $e);
            self::assertSame(400, $e->httpStatus);
            self::assertStringContainsString('Context window exceeded', $e->getMessage());
        }
    }

    #[Test]
    public function aServerErrorNormalisesToATypedProviderException(): void
    {
        $this->expectException($this->expectedServerErrorException());

        // maxRetries 0 keeps the retry loop to a single attempt; the mapping
        // under test is the status, not the backoff.
        $this->configuredAdapter(
            [$this->jsonResponse(['error' => ['message' => 'boom']], 500)],
            ['maxRetries' => 0],
        )->chatCompletion([['role' => 'user', 'content' => 'ping']]);
    }

    /**
     * A 2xx whose body is not JSON — an upstream HTML error page, a truncated
     * proxy response — must not escape as a raw decoding error the caller
     * cannot handle.
     */
    #[Test]
    public function anUndecodableSuccessBodyNormalisesToTheConnectionException(): void
    {
        $this->expectException(ProviderConnectionException::class);

        $this->configuredAdapter(
            [new Response(200, ['Content-Type' => 'application/json'], 'not json at all')],
            ['maxRetries' => 0],
        )->chatCompletion([['role' => 'user', 'content' => 'ping']]);
    }

    /**
     * An adapter that needs a credential refuses locally when it has none.
     *
     * The alternative is what OpenRouter did before ADR-160: fall through to
     * the api-key-less HTTP client and send a keyless request, so a local
     * misconfiguration reaches the operator as the provider's 401 — an
     * exception naming the provider for our own mistake, after an outbound
     * request that never had a chance.
     */
    #[Test]
    public function anAdapterWithNoCredentialRefusesBeforeSendingAnything(): void
    {
        $adapter = $this->newAdapter();
        if (!$this->requiresApiKey()) {
            self::markTestSkipped(
                $adapter::class . ' takes no API key — see the requiresApiKey() override for why.',
            );
        }

        $adapter->configure(array_merge($this->adapterConfiguration(), ['apiKeyIdentifier' => '']));
        // setHttpClient() must follow configure() — configure() resets the client.
        $adapter->setHttpClient($this->callbackClient(static function (): ResponseInterface {
            throw new RuntimeException('The adapter sent a request without a credential.', 1827242890);
        }));

        try {
            $adapter->chatCompletion([['role' => 'user', 'content' => 'ping']]);
            self::fail('An adapter with no credential must throw ProviderConfigurationException.');
        } catch (ProviderConfigurationException) {
            // The type is the contract; the wording is per adapter.
        }

        self::assertSame([], $this->sentRequests, 'A credential-less adapter must not reach the network.');
    }

    // ------------------------------------------------------------------
    // Contract: timeout behaviour
    // ------------------------------------------------------------------

    /**
     * A client-side timeout is a connection failure that must NOT be
     * retried: retrying multiplies the caller's wait by the attempt count,
     * which is how a 120s provider timeout becomes an eight-minute request.
     * The shared path discriminates on elapsed wall time, so the double burns
     * most of the configured second before failing.
     */
    #[Test]
    public function aClientSideTimeoutIsReportedAsAConnectionFailureAndIsNotRetried(): void
    {
        $attempts = 0;
        $adapter  = $this->adapterWithClient(
            $this->callbackClient(static function () use (&$attempts): ResponseInterface {
                ++$attempts;
                usleep(700_000);

                throw new RuntimeException('cURL error 28: Operation timed out', 8599352423);
            }),
            ['timeout' => 1, 'maxRetries' => 3],
        );

        try {
            $adapter->chatCompletion([['role' => 'user', 'content' => 'ping']]);
            self::fail('A timed-out request must surface as a ProviderConnectionException.');
        } catch (ProviderConnectionException) {
            // The type is the contract; the wording is per adapter.
        }

        self::assertSame(1, $attempts, 'A timeout must not be retried — maxRetries was 3.');
    }

    /**
     * The counterpart: a fast transport failure IS retried, so a flaky
     * upstream still gets the configured attempts.
     */
    #[Test]
    public function aFastTransportFailureUsesTheConfiguredRetries(): void
    {
        if (!$this->retriesTransportFailures()) {
            self::markTestSkipped(
                $this->newAdapter()::class . ' does not retry transport failures — see the '
                . 'retriesTransportFailures() override for why.',
            );
        }

        $attempts = 0;
        $adapter  = $this->adapterWithClient(
            $this->callbackClient(static function () use (&$attempts): ResponseInterface {
                ++$attempts;

                throw new RuntimeException('Connection refused', 8143437919);
            }),
            ['timeout' => 30, 'maxRetries' => 1],
        );

        try {
            $adapter->chatCompletion([['role' => 'user', 'content' => 'ping']]);
            self::fail('An unreachable provider must surface as a ProviderConnectionException.');
        } catch (ProviderConnectionException) {
            // Expected.
        }

        self::assertSame(2, $attempts, 'maxRetries counts retries AFTER the first attempt.');
    }

    // ------------------------------------------------------------------
    // Contract: usage reporting
    // ------------------------------------------------------------------

    #[Test]
    public function usageIsReportedFromTheProvidersOwnCounters(): void
    {
        $adapter = $this->configuredAdapter([$this->jsonResponse($this->chatResponseBody(31, 59))]);

        $usage = $adapter->chatCompletion([['role' => 'user', 'content' => 'ping']])->usage;

        self::assertSame(31, $usage->promptTokens);
        self::assertSame(59, $usage->completionTokens);
        self::assertSame(90, $usage->totalTokens, 'The total is derived, never trusted from the payload.');
    }

    /**
     * A payload with no usage block must still produce a usable
     * `UsageStatistics` — `UsageMiddleware` writes one row per call, and a
     * failure here would break cost accounting rather than degrade it.
     */
    #[Test]
    public function aMissingUsageBlockDegradesToZeroRatherThanFailing(): void
    {
        $usage = $this->configuredAdapter([$this->jsonResponse($this->chatResponseBodyWithoutUsage())])
            ->chatCompletion([['role' => 'user', 'content' => 'ping']])
            ->usage;

        self::assertSame(0, $usage->promptTokens);
        self::assertSame(0, $usage->completionTokens);
        self::assertSame(0, $usage->totalTokens);
    }

    // ------------------------------------------------------------------
    // Contract: tool-call shape
    // ------------------------------------------------------------------

    #[Test]
    public function aToolCallIsSurfacedAsATypedToolCall(): void
    {
        $adapter = $this->newAdapter();
        if (!$adapter instanceof ToolCapableInterface) {
            self::markTestSkipped(
                $adapter::class . ' does not implement ToolCapableInterface — it cannot make tool calls, '
                . 'so there is no tool-call shape to hold it to.',
            );
        }

        $configured = $this->configuredAdapter([$this->jsonResponse($this->toolCallResponseBody())]);
        self::assertInstanceOf(ToolCapableInterface::class, $configured);

        $response = $configured->chatCompletionWithTools(
            [['role' => 'user', 'content' => 'Weather in Leipzig?']],
            [$this->weatherTool()],
        );

        self::assertTrue($response->hasToolCalls(), 'The adapter dropped the provider tool call.');
        $calls = $response->toolCalls ?? [];
        self::assertCount(1, $calls);
        self::assertSame('get_weather', $calls[0]->name);
        self::assertNotSame('', $calls[0]->id, 'Every tool call needs an id the tool loop can key results by.');
        self::assertSame(['city' => 'Leipzig'], $calls[0]->arguments);
    }

    #[Test]
    public function theToolDeclarationReachesTheWire(): void
    {
        $adapter = $this->newAdapter();
        if (!$adapter instanceof ToolCapableInterface) {
            self::markTestSkipped(
                $adapter::class . ' does not implement ToolCapableInterface — it sends no tool declaration.',
            );
        }

        $configured = $this->configuredAdapter([$this->jsonResponse($this->toolCallResponseBody())]);
        self::assertInstanceOf(ToolCapableInterface::class, $configured);

        $configured->chatCompletionWithTools(
            [['role' => 'user', 'content' => 'Weather in Leipzig?']],
            [$this->weatherTool()],
        );

        self::assertStringContainsString(
            'get_weather',
            $this->lastRequestBody(),
            'The declared tool never reached the provider request.',
        );
    }

    // ------------------------------------------------------------------
    // Contract: structured output (ADR-126 / ADR-128)
    // ------------------------------------------------------------------

    #[Test]
    public function aStrictSchemaIsEnforcedThroughTheProvidersNativeShape(): void
    {
        $adapter = $this->configuredAdapter([$this->jsonResponse($this->chatResponseBody(5, 5, '{"title":"x"}'))]);

        $adapter->chatCompletion(
            [['role' => 'user', 'content' => 'Return json']],
            ['response_schema' => self::STRICT_SCHEMA],
        );

        $this->assertSchemaEnforcedOnTheWire($this->lastRequestPayload(), self::STRICT_SCHEMA);
    }

    #[Test]
    public function aSchemaTheProviderCannotEnforceDegradesInsteadOfFailing(): void
    {
        $adapter = $this->configuredAdapter([$this->jsonResponse($this->chatResponseBody(5, 5, '{"title":"x"}'))]);

        $adapter->chatCompletion(
            [['role' => 'user', 'content' => 'Return json']],
            ['response_schema' => self::UNSUPPORTED_SCHEMA],
        );

        $this->assertUnsupportedSchemaDegrades($this->lastRequestPayload(), self::UNSUPPORTED_SCHEMA);
    }

    #[Test]
    public function noStructuredOutputFieldIsSentWhenNoSchemaWasAsked(): void
    {
        $adapter = $this->configuredAdapter([$this->jsonResponse($this->chatResponseBody(5, 5))]);

        $adapter->chatCompletion([['role' => 'user', 'content' => 'Just talk']]);

        $body = $this->lastRequestBody();
        foreach (['json_schema', 'responseSchema', 'structured_output', '"format"'] as $marker) {
            self::assertStringNotContainsString(
                $marker,
                $body,
                'A plain completion must not carry a structured-output instruction.',
            );
        }
    }

    /**
     * A provider that answers a structured request with prose instead of JSON
     * is the malformed case the ADR-126 repair round-trip exists for. The
     * ADAPTER's job stops at handing the body back unchanged — decoding,
     * validating and the one repair attempt belong to `CompletionService`,
     * which has its own tests for exactly that. Asserting pass-through here
     * is what stops an adapter from "helpfully" rewriting the body and hiding
     * the failure from the layer that can act on it.
     */
    #[Test]
    public function aMalformedStructuredAnswerIsPassedThroughUntouched(): void
    {
        $adapter = $this->configuredAdapter([
            $this->jsonResponse($this->chatResponseBody(5, 5, 'Sorry, I cannot do that.')),
        ]);

        $response = $adapter->chatCompletion(
            [['role' => 'user', 'content' => 'Return json']],
            ['response_schema' => self::STRICT_SCHEMA],
        );

        self::assertSame('Sorry, I cannot do that.', $response->content);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<class-string, array{0: ModelCapability, 1: string}>
     */
    private function capabilityInterfaces(): array
    {
        return [
            StreamingCapableInterface::class => [ModelCapability::STREAMING, 'supportsStreaming'],
            ToolCapableInterface::class      => [ModelCapability::TOOLS, 'supportsTools'],
            VisionCapableInterface::class    => [ModelCapability::VISION, 'supportsVision'],
        ];
    }

    protected function weatherTool(): ToolSpec
    {
        return new ToolSpec(
            name: 'get_weather',
            description: 'Current weather for a city.',
            parameters: [
                'type' => 'object',
                'properties' => ['city' => ['type' => 'string']],
                'required' => ['city'],
            ],
        );
    }

    /**
     * @param list<ResponseInterface> $responses
     * @param array<string, mixed>    $configurationOverrides
     */
    protected function configuredAdapter(array $responses, array $configurationOverrides = []): AbstractProvider
    {
        $remaining = $responses;

        return $this->adapterWithClient(
            $this->callbackClient(static function () use (&$remaining): ResponseInterface {
                $next = array_shift($remaining);
                if (!$next instanceof ResponseInterface) {
                    throw new RuntimeException('The adapter made more requests than the test scripted.', 3204811424);
                }

                return $next;
            }),
            $configurationOverrides,
        );
    }

    /**
     * @param array<string, mixed> $configurationOverrides
     */
    protected function adapterWithClient(ClientInterface $client, array $configurationOverrides = []): AbstractProvider
    {
        $adapter = $this->newAdapter();
        $adapter->configure(array_merge($this->adapterConfiguration(), $configurationOverrides));
        // setHttpClient() must follow configure() — configure() resets the client.
        $adapter->setHttpClient($client);

        return $adapter;
    }

    /**
     * A PSR-18 double that records every request before delegating to
     * `$respond`. Recording is what lets the structured-output and tool
     * contracts assert on the payload the adapter actually built.
     *
     * @param callable(RequestInterface): ResponseInterface $respond
     */
    protected function callbackClient(callable $respond): ClientInterface
    {
        $client = self::createStub(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(
            function (RequestInterface $request) use ($respond): ResponseInterface {
                $this->sentRequests[] = $request;

                return $respond($request);
            },
        );

        return $client;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function jsonResponse(array $body, int $status = 200): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    protected function lastRequestBody(): string
    {
        $request = $this->sentRequests[count($this->sentRequests) - 1] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request, 'The adapter sent no request.');

        return (string)$request->getBody();
    }

    /**
     * @return array<string, mixed>
     */
    protected function lastRequestPayload(): array
    {
        $decoded = json_decode($this->lastRequestBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

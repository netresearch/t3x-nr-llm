<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized;

use JsonException;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Exception\BudgetExceededException;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Specialized\Exception\ServiceConfigurationException;
use Netresearch\NrLlm\Specialized\Exception\ServiceUnavailableException;
use Netresearch\NrLlm\Specialized\Pricing\SpecializedCostCalculatorInterface;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Base class for specialised single-task AI services (REC #7).
 *
 * Concentrates the HTTP scaffolding that every specialised service —
 * DALL-E, FAL, Whisper, TTS, DeepL — was reimplementing on its own
 * (audit estimated ~40-50% duplication, ~300+ LOC reclaimable). Each
 * subclass declares its identity (domain / provider strings used in
 * exceptions and usage tracking) and the auth header scheme; the base
 * owns config loading, availability checks, JSON POST, and the
 * status-code → typed-exception mapping.
 *
 * Multipart-body construction lives in `MultipartBodyBuilderTrait`
 * (only Whisper and DALL-E need it — JSON-only services don't pay
 * for the trait's footprint).
 *
 * Constructor signature is identical across every consumer. Property
 * visibility intentionally `protected` (not `private`) so subclasses
 * can read `apiKeyIdentifier` / `baseUrl` when building service-specific
 * payloads — the alternative (passing them through accessor methods)
 * adds no encapsulation since the subclass is the only thing that
 * touches them anyway.
 *
 * Secrets are never held as plaintext: each service stores the nr-vault
 * identifier and authenticates through the audited secure HTTP client
 * (`$vault->http()->withAuthentication(...)`), which injects and scrubs
 * the secret inside the vault. This mirrors `AbstractProvider`.
 *
 * @phpstan-consistent-constructor
 */
abstract class AbstractSpecializedService
{
    use ServiceConfigurationTrait;

    protected string $apiKeyIdentifier = '';
    protected string $baseUrl = '';
    protected int $timeout;

    /**
     * Per-request audit context appended to the audit reason, e.g.
     * `'gpt-image-2, generate'` or `'tts-1, voice nova'`. Set by subclasses
     * via `setAuditContext()` right before dispatching a request so the
     * vault audit log records which model and purpose a secret access
     * served. MUST never contain prompt text or other payload content.
     */
    private string $auditContext = '';

    /**
     * Test seam: when set, `getSecureClient()` returns this instead of the
     * vault secure client. Production never sets it — auth always flows
     * through the audited vault client. Mirrors `AbstractProvider`.
     */
    private ?ClientInterface $configuredHttpClient = null;

    public function __construct(
        protected readonly VaultServiceInterface $vault,
        protected readonly RequestFactoryInterface $requestFactory,
        protected readonly StreamFactoryInterface $streamFactory,
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly UsageTrackerServiceInterface $usageTracker,
        protected readonly LoggerInterface $logger,
        protected readonly SpecializedCostCalculatorInterface $costCalculator,
        // Required, and ahead of the optional collaborators: a budget gate that
        // silently disappears when a caller forgets to wire it is a fail-open
        // control on money (ADR-078 shipped it optional; that was wrong).
        protected readonly BudgetServiceInterface $budgetService,
        protected readonly ?ModelRepository $modelRepository = null,
        protected readonly ?LlmConfigurationRepository $configurationRepository = null,
    ) {
        $this->timeout = $this->getDefaultTimeout();
        $this->loadConfiguration();
    }

    /**
     * Pre-flight budget gate for the specialized (image / speech) send path.
     *
     * Unlike the chat-shaped feature services, the specialized services dispatch
     * HTTP directly and bypass the middleware pipeline, so BudgetMiddleware never
     * sees them (ADR-057 deferred this enforcement; ADR-078 adds it here). This
     * mirrors BudgetMiddleware::handle(): resolve the named configuration, run the
     * per-user and per-configuration check, and throw before any spend when a
     * limit is exceeded.
     *
     * Fail-open by construction: when no BudgetServiceInterface is wired (the
     * constructor param is optional, so unconfigured deployments and unit tests
     * that omit it are unaffected) the gate is a no-op. When wired, the shared
     * BudgetService still short-circuits to "allowed" for calls without a backend
     * user or without configured limits, so nothing changes until an operator
     * actually sets a cap.
     *
     * @throws BudgetExceededException when the pre-flight check denies the call
     */
    protected function enforceBudget(?int $beUserUid, ?float $plannedCost, ?string $configurationIdentifier = null): void
    {
        $configuration = $configurationIdentifier !== null
            ? $this->findActiveConfiguration($configurationIdentifier)
            : null;

        $result = $this->budgetService->check($beUserUid ?? 0, $plannedCost ?? 0.0, $configuration);
        if (!$result->allowed) {
            throw new BudgetExceededException($result);
        }
    }

    /**
     * Returns true once the service has a configured vault identifier
     * that resolves to an existing secret. Stable across the lifetime
     * of the instance — if the config changes at runtime, callers must
     * rebuild the service via DI rather than poll.
     */
    public function isAvailable(): bool
    {
        return $this->apiKeyIdentifier !== '' && $this->vault->exists($this->apiKeyIdentifier);
    }

    /**
     * Resolve a configured value to a non-empty string, falling back to a default. An empty
     * ext_conf base-URL string (the documented "use the provider default" value) must not be
     * used verbatim — a scheme-less URL makes the HTTP client fail — so empty falls back here.
     */
    protected function nonEmptyStringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Service domain used by `ServiceUnavailableException` /
     * `ServiceConfigurationException` (`'image'`, `'speech'`,
     * `'translation'`, …) so log sinks and downstream catches can
     * filter by domain without parsing the message string.
     */
    abstract protected function getServiceDomain(): string;

    /**
     * Service provider identifier used by exception payloads and
     * usage-tracking calls (`'dall-e'`, `'fal'`, `'whisper'`,
     * `'tts'`, `'deepl'`).
     */
    abstract protected function getServiceProvider(): string;

    /**
     * Default base URL for the upstream API. Used when the extension
     * configuration does not override it. Each provider has a
     * documented default endpoint.
     */
    abstract protected function getDefaultBaseUrl(): string;

    /**
     * Default request timeout in seconds. Some services (Whisper —
     * audio transcription) want a higher default; speech synthesis
     * and translation are typically faster.
     */
    abstract protected function getDefaultTimeout(): int;

    /**
     * Service-specific config picking. Called from `loadConfiguration()`
     * with the already-fetched `nr_llm` config tree (or `[]` if loading
     * failed). Subclasses navigate to their own config branch — e.g.
     * `$config['providers']['openai']['apiKeyIdentifier']` for DALL-E or
     * `$config['translators']['deepl']['apiKeyIdentifier']` for DeepL — and
     * set `$this->apiKeyIdentifier`, `$this->baseUrl`, `$this->timeout`.
     *
     * Subclasses MUST set `$this->apiKeyIdentifier` and `$this->baseUrl`. The
     * base class pre-populates `$this->timeout` with
     * `getDefaultTimeout()` so subclasses only need to override it
     * when the config provides a timeout override.
     *
     * The stored identifier is the nr-vault UUID, never a plaintext key;
     * the secret is resolved inside the audited secure client at request
     * time (see `getSecureClient()`).
     *
     * @param array<string, mixed> $config the full `nr_llm` config tree
     */
    abstract protected function loadServiceConfiguration(array $config): void;

    /**
     * How the upstream API expects the secret to be placed. Defaults to
     * Bearer (the OpenAI family — DALL-E, Whisper, TTS). Services with a
     * different scheme override this together with
     * `getSecretPlacementOptions()`:
     *  - FAL:   `SecretPlacement::Header` + `['headerName' => 'Authorization', 'prefix' => 'Key ']`
     *  - DeepL: `SecretPlacement::Header` + `['headerName' => 'Authorization', 'prefix' => 'DeepL-Auth-Key ']`.
     */
    protected function getSecretPlacement(): SecretPlacement
    {
        return SecretPlacement::Bearer;
    }

    /**
     * Options forwarded to `VaultHttpClientInterface::withAuthentication()`
     * for the configured placement (e.g. `headerName`, `prefix`). Empty by
     * default (Bearer needs none).
     *
     * @return array<string, string>
     */
    protected function getSecretPlacementOptions(): array
    {
        return [];
    }

    /**
     * Non-auth headers applied to every request alongside `Content-Type`.
     * Auth headers are NOT built here — the secure client injects them. Used
     * for extras like DeepL's `User-Agent`. Empty by default.
     *
     * @return array<string, string>
     */
    protected function getAdditionalHeaders(): array
    {
        return [];
    }

    /**
     * Set the per-request audit context (model, purpose, …) recorded with
     * the next secret access, e.g. `'gpt-image-2, generate'`. The secure
     * client is built per request (`getSecureClient()` inside the send
     * path), so a context set immediately before dispatch is what lands
     * in the vault audit log. Pass only request *metadata* — never prompt
     * text, file contents, or anything secret-bearing.
     */
    protected function setAuditContext(string $context): void
    {
        $this->auditContext = $context;
    }

    /**
     * Audit-log reason recorded by the secure client for this service's
     * requests, e.g. `'OpenAI Images API call (gpt-image-2, generate)'`.
     * Subclasses may override for a more specific phrase; most should
     * just call `setAuditContext()` before dispatching.
     *
     * The per-request context is CONSUMED here: it is cleared once it has
     * been folded into a reason, so a later request that does not set its
     * own context falls back to the plain default instead of silently
     * reusing the previous request's context in the vault audit log.
     */
    protected function getAuditReason(): string
    {
        $reason = sprintf('%s API call', $this->getProviderLabel());
        if ($this->auditContext !== '') {
            $reason .= sprintf(' (%s)', $this->auditContext);
            $this->auditContext = '';
        }
        return $reason;
    }

    /**
     * The nr-vault secure HTTP client configured for this service's secret
     * and placement. It resolves the secret inside the vault, injects it,
     * audits the access, and scrubs the plaintext — the secret never
     * surfaces in this extension's code. Mirrors `AbstractProvider`.
     *
     * Also pushes the service's configured request timeout down to the wire
     * via the per-request `withTimeout()` wither (nr-vault ^0.10.0). Without
     * it, plain PSR-18 `sendRequest()` runs under the host's global
     * `HTTP.timeout` (TYPO3 default) and long-running calls — large image
     * generations in particular — get cut off there no matter what
     * `$this->timeout` says.
     */
    protected function getSecureClient(): ClientInterface
    {
        if ($this->configuredHttpClient !== null) {
            return $this->configuredHttpClient;
        }

        $client = $this->vault->http()
            ->withAuthentication(
                $this->apiKeyIdentifier,
                $this->getSecretPlacement(),
                $this->getSecretPlacementOptions(),
            )
            ->withReason($this->getAuditReason());

        // Non-positive timeout = no override, per the wither's contract.
        if ($this->timeout > 0) {
            return $client->withTimeout($this->timeout);
        }

        return $client;
    }

    /**
     * Inject a custom HTTP client, bypassing the vault secure client.
     *
     * @internal Test seam only — production always authenticates through the
     *           audited vault client (see `getSecureClient()`).
     */
    public function setHttpClient(ClientInterface $client): void
    {
        $this->configuredHttpClient = $client;
    }

    /**
     * Throw a typed `ServiceUnavailableException` when the service
     * is not configured. Convenience for the `if (!$this->isAvailable())
     * throw …;` pattern that every service replicates.
     *
     * @throws ServiceUnavailableException
     */
    protected function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw ServiceUnavailableException::notConfigured(
                $this->getServiceDomain(),
                $this->getServiceProvider(),
            );
        }
    }

    /**
     * Resolve the admin-preferred default model for a capability from
     * the model registry (tx_nrllm_model). Considers ACTIVE records
     * carrying the capability — provider-agnostic — preferring the
     * record flagged as default, then the lowest sorting, and returns
     * that record's model id (the API model string). Records without a
     * model id are skipped: they cannot be sent to an upstream API.
     *
     * Fail-soft, mirroring `SpecializedCostCalculator::estimateFromModelRow()`:
     * no repository in context, a persistence failure, or no usable
     * record returns the given fallback unchanged — resolving a default
     * must never break the service call. Never throws.
     */
    protected function resolveDefaultModelFor(ModelCapability $capability, string $fallback): string
    {
        if ($this->modelRepository === null) {
            return $fallback;
        }

        try {
            // Results arrive ordered by the repository default (sorting, name),
            // so the first usable record already is the lowest-sorting one.
            // A default-flagged record overrides it and ends the scan.
            $chosen = null;
            foreach ($this->modelRepository->findByCapability($capability->value) as $candidate) {
                // @phpstan-ignore instanceof.alwaysTrue (defensive check for QueryResult)
                if (!$candidate instanceof Model || $candidate->getModelId() === '') {
                    continue;
                }
                // The capability query is provider-agnostic, but model-id vocabularies
                // are not (gpt-image-* vs flux-*): skip records this service cannot
                // speak, so e.g. an OpenAI default never reaches the FAL endpoint.
                if (!$this->acceptsModelId($candidate->getModelId())) {
                    continue;
                }
                if ($candidate->isDefault()) {
                    $chosen = $candidate;
                    break;
                }
                $chosen ??= $candidate;
            }
            if ($chosen instanceof Model) {
                return $chosen->getModelId();
            }
        } catch (Throwable) {
            // Extbase persistence may be unavailable in edge contexts
            // (early CLI bootstrap); the hardcoded fallback keeps the
            // service usable.
        }

        return $fallback;
    }

    /**
     * Resolve the tx_nrllm_model uid for a model id so usage rows link
     * to the registry record and the Analytics model breakdowns can
     * aggregate by record. Fail-soft: returns 0 (no linked record) when
     * no repository is in context, no record matches, or the lookup
     * fails — usage tracking must never break the service call.
     */
    protected function resolveModelUid(string $modelId): int
    {
        if ($this->modelRepository === null || $modelId === '') {
            return 0;
        }

        try {
            return $this->modelRepository->findOneByModelId($modelId)?->getUid() ?? 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Resolve the model id to use for a named LlmConfiguration record
     * (tx_nrllm_configuration). The configuration is the stable
     * indirection layer consumers reference by identifier: an
     * administrator swaps the model on the record and every consumer
     * picks it up without re-configuring anything on their side.
     *
     * Resolution order:
     *  1. the ACTIVE configuration's ACTIVE model record's model id —
     *     records with an empty model id are skipped (they cannot be
     *     sent to an upstream API),
     *  2. the capability-based registry default
     *     (`resolveDefaultModelFor()` semantics),
     *  3. the given hardcoded fallback.
     *
     * Fail-soft like every resolver here: no repository in context, a
     * persistence failure, or an unknown/inactive identifier moves
     * resolution down the chain — this method never throws.
     */
    protected function resolveConfiguredModelFor(
        ?ModelCapability $capability,
        string $configurationIdentifier,
        string $fallback,
    ): string {
        try {
            $model = $this->findActiveConfiguration($configurationIdentifier)?->getLlmModel();
            if ($model !== null && $model->isActive() && $model->getModelId() !== ''
                && $this->acceptsModelId($model->getModelId())
            ) {
                return $model->getModelId();
            }
        } catch (Throwable) {
            // Extbase persistence may be unavailable in edge contexts
            // (early CLI bootstrap); fall through to the capability-based
            // default so the service call never breaks on resolution.
        }

        return $capability === null ? $fallback : $this->resolveDefaultModelFor($capability, $fallback);
    }

    /**
     * The model-registry capability this service's models carry, or null for
     * services without registry-managed models (DeepL): the public resolvers
     * below then skip the capability-based registry default.
     */
    protected function getModelCapability(): ?ModelCapability
    {
        return null;
    }

    /**
     * Whether this service can speak the given model id. The capability-based
     * registry resolution is provider-agnostic while model-id vocabularies are
     * not — services sharing a capability (OpenAI images vs FAL) override this
     * to skip registry records they cannot send to their upstream API.
     */
    protected function acceptsModelId(string $modelId): bool
    {
        return true;
    }

    /**
     * Resolve the admin-preferred default model from the model registry: the
     * ACTIVE tx_nrllm_model record carrying this service's capability,
     * preferring the record flagged as default, then the lowest sorting.
     * Fail-soft: any error, no repository in context, or no usable record
     * returns the given fallback unchanged — this method never throws.
     */
    public function resolveDefaultModel(string $fallback): string
    {
        $capability = $this->getModelCapability();

        return $capability === null ? $fallback : $this->resolveDefaultModelFor($capability, $fallback);
    }

    /**
     * Resolve the model for a named LlmConfiguration record — the stable
     * indirection layer consumers reference by identifier: an administrator
     * swaps the assigned model on the record and every consumer picks it up
     * without re-configuring anything. Resolution order: the ACTIVE
     * configuration's ACTIVE model record's model id → the capability-based
     * registry default → the given fallback. Fail-soft — never throws.
     *
     * Resolve the model BEFORE constructing the call options: image options
     * validate the size against the concrete model value, so the model must
     * be known at construction time.
     */
    public function resolveModelForConfiguration(string $configurationIdentifier, string $fallback): string
    {
        return $this->resolveConfiguredModelFor($this->getModelCapability(), $configurationIdentifier, $fallback);
    }

    /**
     * System prompt of an ACTIVE LlmConfiguration record; the empty
     * string when the identifier is unknown or inactive, the prompt is
     * unset, no repository is in context, or the lookup fails — never
     * throws. Consumers weave the prompt into their own requests; the
     * extension never injects it implicitly, so the consumer always
     * records the exact prompt it sent (transparency requirement).
     */
    public function getConfigurationSystemPrompt(string $configurationIdentifier): string
    {
        try {
            return $this->findActiveConfiguration($configurationIdentifier)?->getSystemPrompt() ?? '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Resolve the tx_nrllm_configuration uid for an identifier so usage
     * rows link to the configuration record and the Analytics module can
     * aggregate spend per configuration. Fail-soft: null (no linked
     * record) when no identifier was given, no repository is in context,
     * the identifier is unknown/inactive, or the lookup fails — usage
     * tracking must never break the service call.
     */
    protected function resolveConfigurationUid(?string $configurationIdentifier): ?int
    {
        if ($configurationIdentifier === null) {
            return null;
        }

        try {
            return $this->findActiveConfiguration($configurationIdentifier)?->getUid();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Fetch an ACTIVE configuration record by identifier. Returns null
     * when no repository is in context, the identifier is empty or
     * unknown, or the record is inactive — an inactive configuration is
     * treated as nonexistent across the whole resolution layer.
     * Persistence failures bubble up; callers wrap this in their own
     * fail-soft handling.
     */
    private function findActiveConfiguration(string $identifier): ?LlmConfiguration
    {
        if ($this->configurationRepository === null || $identifier === '') {
            return null;
        }

        $configuration = $this->configurationRepository->findOneByIdentifier($identifier);
        if ($configuration === null || !$configuration->isActive()) {
            return null;
        }

        return $configuration;
    }

    /**
     * Send a JSON request to the upstream API and return the decoded
     * response body. The endpoint is appended to `$this->baseUrl`
     * (with single-slash normalisation), the secret is injected by the
     * secure client (see `executeRequest()`), any non-auth
     * `getAdditionalHeaders()` are applied, and the payload is
     * `json_encode`d with `JSON_THROW_ON_ERROR`.
     *
     * Body-less methods (`GET`, `HEAD`, `DELETE`) skip the JSON body
     * even when `$payload` is non-empty — some upstreams and proxies
     * reject GET-with-body and the right place to put query data on
     * a GET is the URL itself, not the body. Subclasses that need to
     * send GET data should serialise it onto the endpoint string they
     * pass in.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ServiceUnavailableException
     *
     * @return array<string, mixed>
     */
    protected function sendJsonRequest(string $endpoint, array $payload, string $method = 'POST'): array
    {
        $url = $this->buildEndpointUrl($endpoint);

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Content-Type', 'application/json');

        foreach ($this->getAdditionalHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($this->methodAllowsBody($method)) {
            // JSON_INVALID_UTF8_SUBSTITUTE: a request payload carrying an invalid
            // byte (e.g. a prompt pasted from a Latin-1 source) must degrade to a
            // replacement character, never throw \JsonException and abort the call
            // — matching AbstractProvider's request-encode guard (PR #315/#316).
            $body = $this->streamFactory->createStream(json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
            $request = $request->withBody($body);
        }

        return $this->executeRequest($request);
    }

    /**
     * Body-less HTTP methods per RFC 9110. `TRACE` and `OPTIONS` are
     * the other body-less methods but neither is in active use here.
     */
    private function methodAllowsBody(string $method): bool
    {
        return !in_array(strtoupper($method), ['GET', 'HEAD', 'DELETE'], true);
    }

    /**
     * Concatenate `$this->baseUrl` and a relative endpoint with
     * exactly one separating slash. `$endpoint` may be empty, in
     * which case the request targets the base URL directly (the
     * `TextToSpeechService` does this — its base URL already
     * resolves to `/v1/audio/speech`).
     */
    protected function buildEndpointUrl(string $endpoint): string
    {
        $base = rtrim($this->baseUrl, '/');
        $endpoint = ltrim($endpoint, '/');
        if ($endpoint === '') {
            return $base;
        }
        return $base . '/' . $endpoint;
    }

    /**
     * Send the prepared request, decode the JSON body, and map any
     * upstream error to a typed exception. Empty 2xx responses come
     * back as the empty array; non-2xx responses raise either
     * `ServiceConfigurationException` (auth) or
     * `ServiceUnavailableException` (everything else).
     *
     * Subclasses can override `decodeErrorMessage()` to extract the
     * upstream error message from a service-specific JSON shape;
     * everything else stays in the base.
     *
     * @throws ServiceUnavailableException
     * @throws ServiceConfigurationException
     *
     * @return array<string, mixed>
     */
    protected function executeRequest(RequestInterface $request): array
    {
        try {
            $response = $this->getSecureClient()->sendRequest($request);
            $statusCode = $response->getStatusCode();
            $responseBody = (string)$response->getBody();

            if ($statusCode >= 200 && $statusCode < 300) {
                if ($responseBody === '') {
                    return [];
                }
                $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    return [];
                }

                /** @var array<string, mixed> $decoded */
                return $decoded;
            }

            $errorMessage = $this->decodeErrorMessage($responseBody);

            $this->logger->error(sprintf('%s API error', $this->getProviderLabel()), [
                'status_code' => $statusCode,
                'error'       => $errorMessage,
            ]);

            throw $this->mapErrorStatus($statusCode, $errorMessage);
        } catch (ServiceUnavailableException|ServiceConfigurationException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error(sprintf('%s API connection error', $this->getProviderLabel()), [
                'exception' => $e->getMessage(),
            ]);

            throw new ServiceUnavailableException(
                sprintf('Failed to connect to %s API: %s', $this->getProviderLabel(), $e->getMessage()),
                $this->getServiceDomain(),
                ['provider' => $this->getServiceProvider()],
                0,
                $e,
            );
        }
    }

    /**
     * Extract the error message from an upstream non-2xx body. The
     * default handles the most common shape — `{"error": {"message":
     * "..."}}` — used by every OpenAI-family service (DALL-E,
     * Whisper, TTS). Subclasses with a different shape (FAL uses
     * `detail`/`message`; DeepL uses `message`) override.
     *
     * A non-JSON error body (e.g. an HTML gateway/proxy error page) is logged
     * with a short sample so it is distinguishable from an empty response, and
     * surfaced as a clearer fallback than the generic unknown-error label.
     */
    protected function decodeErrorMessage(string $responseBody): string
    {
        if ($responseBody === '') {
            return $this->unknownErrorLabel();
        }

        try {
            $error = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->warning(sprintf('%s error response is not JSON', $this->getProviderLabel()), [
                'provider' => $this->getServiceProvider(),
                'message'  => $e->getMessage(),
                'sample'   => substr($responseBody, 0, 200),
            ]);

            return sprintf('%s error response is not JSON', $this->getProviderLabel());
        }

        if (is_array($error)) {
            $errorBranch = $error['error'] ?? null;
            if (is_array($errorBranch)) {
                $message = $errorBranch['message'] ?? null;
                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }
        }
        return $this->unknownErrorLabel();
    }

    /**
     * Map an upstream HTTP status code to a typed exception. The
     * default covers the auth (401/403) and rate-limit (429) cases
     * that every service handles identically; subclasses override
     * to add provider-specific branches (FAL has a 422 validation
     * branch, DALL-E distinguishes 400 validation, etc.).
     */
    protected function mapErrorStatus(int $statusCode, string $errorMessage): Throwable
    {
        return match ($statusCode) {
            401, 403 => ServiceConfigurationException::invalidApiKey(
                $this->getServiceDomain(),
                $this->getServiceProvider(),
            ),
            429 => new ServiceUnavailableException(
                sprintf('%s API rate limit exceeded', $this->getProviderLabel()),
                $this->getServiceDomain(),
                ['provider' => $this->getServiceProvider()],
            ),
            default => new ServiceUnavailableException(
                sprintf('%s API error: %s', $this->getProviderLabel(), $errorMessage),
                $this->getServiceDomain(),
                ['provider' => $this->getServiceProvider()],
            ),
        };
    }

    /**
     * Human-readable label for log messages and exception text.
     * Defaults to the provider identifier upper-cased and
     * dash-stripped (`'dall-e'` → `'DALL-E'`, `'tts'` → `'TTS'`).
     * Subclasses with a more specific brand name (`'OpenAI Whisper'`)
     * can override.
     */
    protected function getProviderLabel(): string
    {
        return strtoupper($this->getServiceProvider());
    }

    /**
     * Default fallback when an upstream error body has no usable
     * message. Subclasses can override to provide a more specific
     * label (e.g. `'Unknown DALL-E API error'`).
     */
    protected function unknownErrorLabel(): string
    {
        return sprintf('Unknown %s API error', $this->getProviderLabel());
    }

    /**
     * Load the `nr_llm` extension configuration and hand the
     * already-decoded tree to the subclass. Failures are logged at
     * `warning` level and leave `$this->apiKey` empty so
     * `isAvailable()` will report `false` — same fail-soft posture
     * the per-service implementations had.
     */
    private function loadConfiguration(): void
    {
        try {
            $config = $this->extensionConfiguration->get('nr_llm');
            if (!is_array($config)) {
                return;
            }
            /** @var array<string, mixed> $config */
            $this->loadServiceConfiguration($config);
        } catch (Throwable $e) {
            // Catch `Throwable` not `Exception` because mis-typed
            // extension config (or a subclass parsing it as the wrong
            // shape) raises `TypeError` — which extends `Error`, not
            // `Exception`. We want `isAvailable() === false` and a
            // log line in either case rather than a bootstrap fatal.
            $this->logger->warning(
                sprintf('Failed to load %s configuration', $this->getProviderLabel()),
                ['exception' => $e],
            );
        }
    }
}

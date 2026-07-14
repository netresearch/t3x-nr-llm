<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Middleware;

use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRecord;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;

/**
 * Outermost pipeline layer: records one telemetry row per run, ALWAYS (ADR-058).
 *
 * This is the failure-rate / latency observer the UsageMiddleware doc block
 * leaves open ("The middleware never runs when $next throws: failed calls are
 * not tracked here. If failure-rate telemetry is needed later, a dedicated
 * middleware can wrap and record regardless of outcome."). It wraps the entire
 * pipeline and writes exactly one row on both success and failure, then
 * re-throws the original exception untouched.
 *
 * Pipeline ordering — Telemetry sits OUTSIDE Cache so the measured latency
 * includes the cache lookup and a cache-served response still produces a row:
 *
 *   TelemetryMiddleware      <-- outermost; pure observer          (priority 110)
 *     CacheMiddleware        <-- short-circuits on hit             (priority 100)
 *       BudgetMiddleware     <-- pre-flight denial                 (priority 75)
 *         FallbackMiddleware <-- swaps config on retryable failure (priority 50)
 *           UsageMiddleware  <-- records the call that ran         (priority 25)
 *             <terminal>
 *
 * What it records: the OPERATION and requested primary CONFIGURATION
 * (identifier, plus its provider/model). When FallbackMiddleware swaps to a
 * sibling configuration the swap is captured as `fallback_attempts > 0`; the
 * provider/model/cost of the configuration that actually SERVED live in the
 * usage table (UsageMiddleware sees the served config). Ad-hoc direct calls
 * carry no attached model, so provider/model are empty and the provider is
 * encoded in the `ad-hoc:<operation>:<provider>` identifier.
 *
 * Privacy: no prompt, no response, no exception message — only the exception
 * FQCN (`error_class`), because messages can carry payload fragments. The
 * central privacy model (retention tiers) is a later workstream; this
 * middleware is metadata-only by construction.
 *
 * Fail-soft: a telemetry write error is logged and swallowed. Observability
 * must never break the call it observes.
 *
 * Streaming stays out of the pipeline (ADR-026), so streamed responses produce
 * no telemetry row here; a streaming lifecycle is a separate workstream.
 *
 * Disable via the `telemetry.enabled` extension setting (default ON). When
 * disabled the middleware is a verbatim pass-through.
 */
#[AutoconfigureTag(name: ProviderMiddlewareInterface::TAG_NAME, attributes: ['priority' => 110])]
final readonly class TelemetryMiddleware implements ProviderMiddlewareInterface
{
    public function __construct(
        private TelemetryRepositoryInterface $repository,
        private Context $context,
        private ExtensionConfiguration $extensionConfiguration,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param callable(LlmConfiguration): mixed $next
     */
    public function handle(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        callable $next,
    ): mixed {
        if (!$this->isEnabled()) {
            return $next($configuration);
        }

        $start      = hrtime(true);
        $success    = false;
        $errorClass = '';

        try {
            $result  = $next($configuration);
            $success = true;

            return $result;
        } catch (Throwable $e) {
            $errorClass = $e::class;

            throw $e;
        } finally {
            $this->safeRecord($context, $configuration, $success, $errorClass, $this->elapsedMs($start));
        }
    }

    /**
     * Wall-clock milliseconds since the given hrtime() reading. Integer ms is
     * enough resolution for latency buckets and keeps the column an int.
     */
    private function elapsedMs(int|float $startNs): int
    {
        return (int)((hrtime(true) - $startNs) / 1_000_000);
    }

    private function safeRecord(
        ProviderCallContext $context,
        LlmConfiguration $configuration,
        bool $success,
        string $errorClass,
        int $latencyMs,
    ): void {
        try {
            $this->repository->record(new TelemetryRecord(
                correlationId: $context->correlationId,
                operation: $context->operation->value,
                provider: $configuration->getProviderType(),
                model: $configuration->getModelId(),
                configurationIdentifier: $configuration->getIdentifier(),
                beUser: $this->resolveBeUser($context),
                success: $success,
                errorClass: $errorClass,
                latencyMs: $latencyMs,
                cacheHit: $context->telemetrySignals->cacheHit,
                fallbackAttempts: $context->telemetrySignals->fallbackAttempts,
            ));
        } catch (Throwable $e) {
            // Observability must not break the call it observes. safeRecord()
            // runs inside handle()'s finally, so a Throwable escaping here would
            // (per PHP finally semantics) replace the provider exception the
            // caller is meant to see. The logger itself can throw (e.g. TYPO3's
            // FileWriter on a full/read-only var/log), so the log call is
            // guarded too — a logging failure is swallowed as a last resort.
            try {
                $this->logger->error(
                    'Failed to record LLM telemetry',
                    [
                        'correlationId' => $context->correlationId,
                        'operation'     => $context->operation->value,
                        'exception'     => $e,
                    ],
                );
            } catch (Throwable) {
                // Nothing safe left to do; never let observability break the call.
            }
        }
    }

    /**
     * Attribution: the caller-supplied backend user (BudgetMiddleware reads the
     * same key for enforcement), else the ambient backend.user aspect, else 0
     * (CLI / scheduler / unauthenticated) — mirroring UsageTrackerService.
     */
    private function resolveBeUser(ProviderCallContext $context): int
    {
        $fromMetadata = $context->metadata[BudgetMiddleware::METADATA_BE_USER_UID] ?? null;
        if (\is_int($fromMetadata)) {
            return $fromMetadata;
        }

        try {
            return (int)$this->context->getAspect('backend.user')->get('id');
        } catch (AspectNotFoundException) {
            return 0;
        }
    }

    /**
     * Telemetry defaults ON (observability by default). Any read failure or a
     * missing setting is treated as enabled; only an explicit falsey
     * `telemetry.enabled` turns it off.
     */
    private function isEnabled(): bool
    {
        try {
            /** @var array<string, mixed> $config */
            $config = $this->extensionConfiguration->get('nr_llm');
        } catch (Throwable) {
            return true;
        }

        $telemetry = \is_array($config['telemetry'] ?? null) ? $config['telemetry'] : [];
        if (!\array_key_exists('enabled', $telemetry)) {
            return true;
        }

        // Extension configuration delivers booleans as '0' / '1' strings;
        // (bool) maps '0' and '' to false, everything else to true.
        return (bool)$telemetry['enabled'];
    }
}

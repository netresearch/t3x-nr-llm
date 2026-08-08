<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Provider\Fallback;

use Generator;
use Netresearch\NrLlm\Domain\DTO\BudgetCheckResult;
use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Fallback\FallbackSkipReason;
use Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\BudgetServiceInterface;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Service\Tool\TrustZoneResolver;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\InMemoryTelemetryRepository;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingUsageTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;

/**
 * The single candidate loop over a primary's fallback chain (ADR-137), and the
 * two things that stay different between the paths that use it.
 *
 * The shared rules (shallow, no self-retry, missing and inactive entries
 * skipped) are asserted once on the resolver. The two deliberate differences —
 * the health-aware reorder on the non-streaming path only, and the primary
 * being a candidate on the streaming path only — are asserted SEPARATELY per
 * path, so a later attempt to "harmonise" them fails here instead of silently
 * changing routing.
 *
 * The last test pins the invariant the tool trust ceiling depends on: whatever
 * either path ends up attempting is a subset of the raw chain
 * {@see TrustZoneResolver} walks for its data-class ceiling (ADR-094). If a
 * path could reach a configuration that walk never saw, a run could be offered
 * tools above the trust zone it actually reaches.
 */
#[CoversClass(FallbackCandidateResolver::class)]
final class CandidateResolutionTest extends AbstractUnitTestCase
{
    // -----------------------------------------------------------------------
    // The shared rules
    // -----------------------------------------------------------------------

    #[Test]
    public function dropsThePrimaryFromItsOwnChain(): void
    {
        $primary  = $this->configuration('p', new FallbackChain(['a', 'p', 'b']));
        $looked   = [];
        $resolver = new FallbackCandidateResolver($this->repository([], $looked));

        self::assertSame(['a', 'b'], $resolver->chainFor($primary)->configurationIdentifiers);
    }

    #[Test]
    public function yieldsOnlyDispatchableEntriesAndReportsEachSkipWithItsReason(): void
    {
        $looked   = [];
        $resolver = new FallbackCandidateResolver($this->repository([
            'gone' => null,
            'off'  => $this->configuration('off', active: false),
            'a'    => $this->configuration('a'),
        ], $looked));

        /** @var list<array{0: string, 1: FallbackSkipReason}> $skips */
        $skips = [];
        /** @var list<array{0: string, 1: string}> $yielded */
        $yielded = [];

        foreach ($resolver->resolve(
            new FallbackChain(['gone', 'off', 'a']),
            static function (string $identifier, FallbackSkipReason $reason) use (&$skips): void {
                $skips[] = [$identifier, $reason];
            },
        ) as $identifier => $candidate) {
            $yielded[] = [$identifier, $candidate->getIdentifier()];
        }

        self::assertSame([['a', 'a']], $yielded, 'The key is the chain entry, the value the resolved configuration.');
        self::assertSame(
            [['gone', FallbackSkipReason::NotFound], ['off', FallbackSkipReason::Inactive]],
            $skips,
        );
    }

    #[Test]
    public function resolvesLazilySoAnEntryBehindTheServingOneIsNeverLookedUp(): void
    {
        $looked   = [];
        $resolver = new FallbackCandidateResolver($this->repository([
            'a' => $this->configuration('a'),
            'b' => $this->configuration('b'),
        ], $looked));

        foreach ($resolver->resolve(new FallbackChain(['a', 'b']), static function (): void {}) as $candidate) {
            self::assertSame('a', $candidate->getIdentifier());

            break;
        }

        self::assertSame(['a'], $looked, 'Resolution must not query the whole chain up front.');
    }

    // -----------------------------------------------------------------------
    // Difference 1: the health reorder belongs to the non-streaming path only
    // -----------------------------------------------------------------------

    #[Test]
    public function theNonStreamingPathWalksTheChainInTheOrderTheHealthServiceReturns(): void
    {
        $primary = $this->configuration('p', new FallbackChain(['a', 'b']));
        $rows    = ['a' => $this->configuration('a'), 'b' => $this->configuration('b')];

        self::assertSame(
            ['b', 'a'],
            $this->attemptedByMiddleware($primary, $rows, reverseOrder: true),
            'ADR-063: the health service may reorder the fallback candidates.',
        );
    }

    #[Test]
    public function theStreamingPathWalksTheChainInItsConfiguredOrder(): void
    {
        $primary = $this->configuration('p', new FallbackChain(['a', 'b']));
        $rows    = ['a' => $this->configuration('a'), 'b' => $this->configuration('b')];

        self::assertSame(
            ['p', 'a', 'b'],
            $this->attemptedByStreaming($primary, $rows),
            'A stream keeps the configured order: no reorder is applied on this path.',
        );
    }

    #[Test]
    public function theStreamingDispatcherHasNoHealthServiceDependency(): void
    {
        $constructor = (new ReflectionClass(StreamingDispatcher::class))->getConstructor();
        self::assertNotNull($constructor);

        $types = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType) {
                $types[] = $type->getName();
            }
        }

        self::assertNotContains(
            ProviderHealthServiceInterface::class,
            $types,
            'Streaming deliberately keeps no health reorder (ADR-137). Wiring one in is a routing change, not a refactor.',
        );
    }

    // -----------------------------------------------------------------------
    // Difference 2: who owns the primary attempt
    // -----------------------------------------------------------------------

    #[Test]
    public function theStreamingCandidateSetOpensWithThePrimaryWhileTheMiddlewareChainWalkDoesNot(): void
    {
        // Nothing but the primary is dispatchable: the chain lists only itself.
        $primary = $this->configuration('p', new FallbackChain(['p']));

        // Streaming opens the primary itself, so it is attempted here.
        self::assertSame(['p'], $this->attemptedByStreaming($primary, []));

        // The middleware's own walk attempts nothing: the pipeline already ran
        // the primary before handing over, and the chain filtered down to empty.
        self::assertSame([], $this->attemptedByMiddleware($primary, []));
    }

    // -----------------------------------------------------------------------
    // The invariant
    // -----------------------------------------------------------------------

    #[Test]
    public function everyAttemptedConfigurationIsPartOfTheChainTheTrustZoneResolverWalks(): void
    {
        // One chain with every edge case at once: a self-reference, a deleted
        // entry, a switched-off entry and two live ones.
        $primary = $this->configuration('p', new FallbackChain(['p', 'gone', 'off', 'a', 'b']));
        $rows    = [
            'p'    => $primary,
            'gone' => null,
            'off'  => $this->configuration('off', active: false),
            'a'    => $this->configuration('a'),
            'b'    => $this->configuration('b'),
        ];

        // What the data-class ceiling actually inspects (TrustZoneResolver).
        $walked = [];
        (new TrustZoneResolver($this->repository($rows, $walked)))->zoneFor($primary);
        self::assertSame(['p', 'gone', 'off', 'a', 'b'], $walked);

        // Both paths, with the reorder switched on for the one that has it.
        $streamed  = $this->attemptedByStreaming($primary, $rows);
        $pipelined = $this->attemptedByMiddleware($primary, $rows, reverseOrder: true);

        // Spelled out so the invariant is checked against a real walk, not an
        // empty one: streaming opens the primary and both live siblings in the
        // configured order; the middleware walks the reordered chain and drops
        // the switched-off and the deleted entry.
        self::assertSame(['p', 'a', 'b'], $streamed);
        self::assertSame(['b', 'a'], $pipelined);

        foreach (['streaming' => $streamed, 'non-streaming' => $pipelined] as $path => $attempted) {
            // The primary is covered by the resolver's own provider zone, not
            // by the chain walk.
            $fallbacks = array_values(array_filter($attempted, static fn(string $id): bool => $id !== 'p'));

            self::assertSame(
                [],
                array_values(array_diff($fallbacks, $walked)),
                sprintf('The %s path attempted a configuration the trust-zone walk never saw.', $path),
            );
        }
    }

    // -----------------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------------

    /**
     * Identifiers the non-streaming path really dispatched, in order. Every
     * attempt fails retryably, so the whole walk is observed. The pipeline's
     * own primary attempt is not part of the chain walk and is filtered out.
     *
     * @param array<string, LlmConfiguration|null> $rows the repository's content
     *
     * @return list<string>
     */
    private function attemptedByMiddleware(
        LlmConfiguration $primary,
        array $rows,
        bool $reverseOrder = false,
    ): array {
        $looked = [];

        $health = self::createStub(ProviderHealthServiceInterface::class);
        $health->method('reorder')->willReturnCallback(
            static fn(FallbackChain $chain): FallbackChain => $reverseOrder
                ? new FallbackChain(array_reverse($chain->configurationIdentifiers))
                : $chain,
        );

        $middleware = new FallbackMiddleware(
            new FallbackCandidateResolver($this->repository($rows, $looked)),
            new RecordingLogger(),
            $health,
        );

        /** @var list<string> $attempted */
        $attempted = [];

        try {
            $middleware->handle(
                ProviderCallContext::forConfiguration(ProviderOperation::Chat, $primary),
                static function (ProviderCallContext $ctx) use (&$attempted, $primary): never {
                    $configuration = $ctx->configuration;
                    assert($configuration instanceof LlmConfiguration);
                    if ($configuration->getIdentifier() !== $primary->getIdentifier()) {
                        $attempted[] = $configuration->getIdentifier();
                    }

                    throw new ProviderConnectionException('down', 1770000001);
                },
            );
        } catch (Throwable) {
            // Exhaustion is the expected outcome; the walk is what is asserted.
        }

        return $attempted;
    }

    /**
     * Identifiers the streaming path really primed, in order — the primary
     * included, because the dispatcher opens it itself.
     *
     * @param array<string, LlmConfiguration|null> $rows the repository's content
     *
     * @return list<string>
     */
    private function attemptedByStreaming(LlmConfiguration $primary, array $rows): array
    {
        $looked = [];

        $budget = self::createStub(BudgetServiceInterface::class);
        $budget->method('check')->willReturn(BudgetCheckResult::allowed());

        $aspect = self::createStub(AspectInterface::class);
        $aspect->method('get')->willReturn(0);
        $typo3Context = self::createStub(Context::class);
        $typo3Context->method('getAspect')->willReturn($aspect);

        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['telemetry' => ['enabled' => '0']]);

        $dispatcher = new StreamingDispatcher(
            $budget,
            new RecordingUsageTracker(),
            new InMemoryTelemetryRepository(),
            new FallbackCandidateResolver($this->repository($rows, $looked)),
            new RecordingLogger(),
            $typo3Context,
            $extensionConfiguration,
        );

        /** @var list<string> $attempted */
        $attempted = [];
        $open      = static function (LlmConfiguration $configuration) use (&$attempted): Generator {
            $attempted[] = $configuration->getIdentifier();

            yield from [];

            throw new ProviderConnectionException('down', 1770000002);
        };

        try {
            iterator_to_array($dispatcher->stream(
                new ProviderCallContext(ProviderOperation::Stream, 'corr-adr137'),
                $primary,
                $open,
            ));
        } catch (Throwable) {
            // Exhaustion is the expected outcome; the walk is what is asserted.
        }

        return $attempted;
    }

    /**
     * A repository that answers from $rows and records every identifier it was
     * asked for, in order.
     *
     * @param array<string, LlmConfiguration|null> $rows
     * @param list<string>                         $looked
     */
    private function repository(array $rows, array &$looked): LlmConfigurationRepository
    {
        $repository = self::createStub(LlmConfigurationRepository::class);
        $repository->method('findOneByIdentifier')->willReturnCallback(
            static function (string $identifier) use ($rows, &$looked): ?LlmConfiguration {
                $looked[] = $identifier;

                return $rows[$identifier] ?? null;
            },
        );

        return $repository;
    }

    private function configuration(
        string $identifier,
        ?FallbackChain $chain = null,
        bool $active = true,
    ): LlmConfiguration {
        $configuration = new LlmConfiguration();
        $configuration->setIdentifier($identifier);
        $configuration->setIsActive($active);
        if ($chain instanceof FallbackChain) {
            $configuration->setFallbackChainDTO($chain);
        }

        return $configuration;
    }
}

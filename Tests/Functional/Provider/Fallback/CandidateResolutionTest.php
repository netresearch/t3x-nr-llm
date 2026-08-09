<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Provider\Fallback;

use Generator;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;
use Netresearch\NrLlm\Provider\Exception\ProviderConnectionException;
use Netresearch\NrLlm\Provider\Fallback\FallbackCandidateResolver;
use Netresearch\NrLlm\Provider\Middleware\FallbackMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Health\ProviderHealthServiceInterface;
use Netresearch\NrLlm\Service\Streaming\StreamingDispatcher;
use Netresearch\NrLlm\Service\Telemetry\TelemetryRepository;
use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
use Netresearch\NrLlm\Tests\Fixture\AllowingBudgetService;
use Netresearch\NrLlm\Tests\Fixture\SkipRecordingLogger;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Missing and inactive chain entries against the real repository, on BOTH
 * paths (ADR-137).
 *
 * The unit tests drive the candidate loop with a stubbed repository; this
 * proves the two skips still happen when the lookup is a real Extbase query
 * against real rows — `chain-gone` has no row at all, `chain-inactive` has one
 * with `is_active = 0` (which `findOneByIdentifier()` deliberately still
 * returns, because it ignores enable fields, so the skip has to be the
 * candidate loop's own decision).
 *
 * The distinct skip wording of each path is asserted too: the merge shares the
 * rules, not the log surface.
 */
#[CoversClass(FallbackCandidateResolver::class)]
final class CandidateResolutionTest extends AbstractFunctionalTestCase
{
    private FallbackCandidateResolver $resolver;

    private LlmConfiguration $primary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('FallbackChains.csv');

        $repository = $this->getService(LlmConfigurationRepository::class);
        $primary    = $repository->findOneByIdentifier('chain-primary');
        self::assertInstanceOf(LlmConfiguration::class, $primary, 'Fixture row missing — the test would prove nothing.');

        $this->primary  = $primary;
        $this->resolver = new FallbackCandidateResolver($repository);
    }

    #[Test]
    public function theNonStreamingPathSkipsTheMissingAndTheInactiveEntryAndServesFromTheNextOne(): void
    {
        $logger = new SkipRecordingLogger();

        $health = self::createStub(ProviderHealthServiceInterface::class);
        $health->method('reorder')->willReturnArgument(0);

        $middleware = new FallbackMiddleware($this->resolver, $logger, $health);

        $served = $middleware->handle(
            ProviderCallContext::forConfiguration(ProviderOperation::Chat, $this->primary),
            static function (ProviderCallContext $context): string {
                $configuration = $context->configuration;
                assert($configuration instanceof LlmConfiguration);
                if ($configuration->getIdentifier() === 'chain-primary') {
                    throw new ProviderConnectionException('primary down', 1770000003);
                }

                return $configuration->getIdentifier();
            },
        );

        self::assertSame('chain-alt', $served, 'The first dispatchable sibling must serve.');

        // This path names the two reasons separately, and still does.
        self::assertSame(['chain-gone'], $logger->skipped('fallback configuration not found'));
        self::assertSame(['chain-inactive'], $logger->skipped('fallback configuration is inactive'));
    }

    #[Test]
    public function theStreamingPathSkipsTheMissingAndTheInactiveEntryAndServesFromTheNextOne(): void
    {
        $logger = new SkipRecordingLogger();

        $dispatcher = new StreamingDispatcher(
            new AllowingBudgetService(),
            $this->getService(UsageTrackerServiceInterface::class),
            new TelemetryRepository($this->getService(ConnectionPool::class)),
            $this->resolver,
            $logger,
            $this->getService(Context::class),
            $this->getService(ExtensionConfiguration::class),
        );

        $open = static function (LlmConfiguration $configuration): Generator {
            if ($configuration->getIdentifier() === 'chain-primary') {
                yield from [];

                throw new ProviderConnectionException('primary down', 1770000004);
            }

            yield $configuration->getIdentifier();
        };

        $chunks = iterator_to_array($dispatcher->stream(
            new ProviderCallContext(ProviderOperation::Stream, 'func-adr137'),
            $this->primary,
            $open,
        ));

        self::assertSame(['chain-alt'], $chunks, 'The first dispatchable sibling must serve.');

        // This path collapses both reasons into one line, and still does.
        self::assertSame(
            ['chain-gone', 'chain-inactive'],
            $logger->skipped('streaming fallback configuration missing or inactive'),
        );
    }

}

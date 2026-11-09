<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Enum\ReasoningEffort;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Service\Model\CapabilityVerifier;
use Netresearch\NrLlm\Service\Model\ThirdPartyReasoningCatalog;
use Netresearch\NrLlm\Service\SetupWizard\DTO\DiscoveredModel;
use Netresearch\NrLlm\Service\SetupWizard\ModelDiscoveryInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * The reasoning profile columns (ADR-275) through the real schema and
 * Extbase: their defaults on a record written before they existed (R9), a
 * round trip of every field, and "not measured" surviving a discovery run
 * that states nothing (R5).
 */
#[CoversNothing] // Domain/Model excluded from coverage in phpunit.xml
final class ModelReasoningProfilePersistenceTest extends AbstractFunctionalTestCase
{
    private ModelRepository $repository;

    private PersistenceManagerInterface $persistenceManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importFixture('Providers.csv');
        $this->importFixture('Models.csv');

        $repository = $this->get(ModelRepository::class);
        self::assertInstanceOf(ModelRepository::class, $repository);
        $this->repository = $repository;

        $persistenceManager = $this->get(PersistenceManagerInterface::class);
        self::assertInstanceOf(PersistenceManagerInterface::class, $persistenceManager);
        $this->persistenceManager = $persistenceManager;
    }

    /**
     * The fixture predates the columns, so every value read here is the
     * schema default.
     */
    #[Test]
    public function aRecordWrittenWithoutTheColumnsHasEmptyDefaults(): void
    {
        $row = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_model')
            ->select(['*'], 'tx_nrllm_model', ['uid' => 1])->fetchAssociative();
        self::assertIsArray($row);

        foreach (['reasoning_override', 'reasoning_efforts_override', 'tool_route_override', 'reasoning_discovered', 'reasoning_efforts_discovered', 'reasoning_source'] as $column) {
            self::assertSame('', $row[$column], $column);
        }

        self::assertSame(0, (int) $row['reasoning_confirmed_at']);

        $model = $this->repository->findByUid(1);
        self::assertInstanceOf(Model::class, $model);
        self::assertNull($model->getReasoningOverrideFlag());
        self::assertNull($model->getReasoningDiscoveredFlag());
    }

    #[Test]
    public function everyFieldSurvivesARoundTrip(): void
    {
        $when  = new DateTimeImmutable('2026-11-09 10:00:00');
        $model = $this->repository->findByUid(1);
        self::assertInstanceOf(Model::class, $model);

        $model->setReasoningOverride('yes');
        $model->setReasoningEffortsOverride('low,high');
        $model->setToolRouteOverride('responses');
        $model->recordReasoningDiscovery(true, [ReasoningEffort::Medium, ReasoningEffort::XHigh], CapabilitySource::Discovery, $when);

        $this->repository->update($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $reloaded = $this->repository->findByUid(1);
        self::assertInstanceOf(Model::class, $reloaded);

        self::assertSame('yes', $reloaded->getReasoningOverride());
        self::assertSame('low,high', $reloaded->getReasoningEffortsOverride());
        self::assertSame('responses', $reloaded->getToolRouteOverride());
        self::assertSame('yes', $reloaded->getReasoningDiscovered());
        self::assertSame('medium,xhigh', $reloaded->getReasoningEffortsDiscovered());
        self::assertSame(CapabilitySource::Discovery, $reloaded->getReasoningSourceEnum());
        self::assertSame($when->getTimestamp(), $reloaded->getReasoningConfirmedDate()?->getTimestamp());
    }

    /**
     * The opt-in lookup only works if the container hands the verifier its
     * catalogue; the constructor default (null) would silently switch R4 off.
     */
    #[Test]
    public function theContainerBuiltVerifierHasTheThirdPartyCatalogue(): void
    {
        $verifier = $this->get(CapabilityVerifier::class);
        self::assertInstanceOf(CapabilityVerifier::class, $verifier);

        self::assertInstanceOf(
            ThirdPartyReasoningCatalog::class,
            (new ReflectionProperty(CapabilityVerifier::class, 'reasoningCatalog'))->getValue($verifier),
        );
    }

    /**
     * R5: a discovery run without reasoning data for the model is persisted
     * like any other, and the reasoning columns stay empty — not "no".
     */
    #[Test]
    public function aDiscoveryRunThatStatesNothingLeavesTheColumnsEmpty(): void
    {
        $model = $this->repository->findByUid(1);
        self::assertInstanceOf(Model::class, $model);

        $discovery = self::createStub(ModelDiscoveryInterface::class);
        $discovery->method('discover')->willReturn([
            new DiscoveredModel(modelId: $model->getModelId(), name: 'listed', capabilities: ['chat'], capabilitiesFromApi: true),
        ]);
        $discovery->method('wasLastDiscoveryFromFallback')->willReturn(false);

        self::assertTrue((new CapabilityVerifier($discovery))->verify($model, new DateTimeImmutable('2026-11-09 10:00:00')));
        $this->repository->update($model);
        $this->persistenceManager->persistAll();

        $row = $this->getConnectionPool()->getConnectionForTable('tx_nrllm_model')
            ->select(['reasoning_discovered', 'reasoning_source', 'reasoning_confirmed_at', 'capabilities_confirmed_at'], 'tx_nrllm_model', ['uid' => 1])
            ->fetchAssociative();
        self::assertIsArray($row);

        self::assertGreaterThan(0, (int) $row['capabilities_confirmed_at'], 'the capability confirmation itself was stored');
        self::assertSame('', $row['reasoning_discovered']);
        self::assertSame('', $row['reasoning_source']);
        self::assertSame(0, (int) $row['reasoning_confirmed_at']);
    }
}

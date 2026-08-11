<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Repository;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Repository\ModelRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * The provenance columns have to survive a round trip through Extbase, which
 * is the part a unit test cannot answer: a property Extbase's ClassSchema
 * does not map is silently dropped on load, and the entity then reports
 * "never confirmed" for a model that was confirmed a minute ago (the same
 * failure mode ADR-138 documents for `capabilities` itself).
 */
#[CoversNothing] // Domain/Model excluded from coverage in phpunit.xml
final class ModelCapabilityProvenanceTest extends AbstractFunctionalTestCase
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

    #[Test]
    public function aRecordedDiscoveryIsReadBackAsProvenance(): void
    {
        $when  = new DateTimeImmutable('2026-08-11 09:30:00');
        $model = $this->repository->findByUid(1);
        self::assertInstanceOf(Model::class, $model);

        $model->setCapabilities('chat,tools');
        $model->recordCapabilityDiscovery(['chat'], CapabilitySource::Discovery, $when);

        $this->repository->update($model);
        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();

        $reloaded = $this->repository->findByUid(1);
        self::assertInstanceOf(Model::class, $reloaded);

        self::assertSame('chat', $reloaded->_getProperty('capabilitiesDiscovered'));
        self::assertSame(CapabilitySource::Discovery->value, $reloaded->_getProperty('capabilitiesSource'));
        self::assertSame($when->getTimestamp(), $reloaded->getCapabilitiesConfirmedDate()?->getTimestamp());

        $byName = [];
        foreach ($reloaded->getCapabilityProvenance() as $entry) {
            $byName[$entry->getName()] = $entry;
        }

        self::assertTrue($byName['chat']->isVerified());
        self::assertFalse($byName['tools']->isVerified());
    }

    /**
     * Every record that predates provenance reads back as unconfirmed, which
     * is the honest answer — nothing ever asked its provider.
     */
    #[Test]
    public function aFixtureRecordWithoutProvenanceReadsBackAsUnconfirmed(): void
    {
        $model = $this->repository->findByUid(1);
        self::assertInstanceOf(Model::class, $model);

        self::assertSame(0, $model->_getProperty('capabilitiesConfirmedAt'));
        self::assertNull($model->getCapabilitiesConfirmedDate());
        self::assertSame('', $model->_getProperty('capabilitiesSource'));

        foreach ($model->getCapabilityProvenance() as $entry) {
            self::assertSame(CapabilitySource::Operator, $entry->source);
        }
    }
}

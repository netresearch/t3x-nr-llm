<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Model;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\CapabilityProvenance;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Capability provenance on the model entity (ADR-160).
 */
#[CoversClass(Model::class)]
final class ModelCapabilityProvenanceTest extends AbstractUnitTestCase
{
    private function modelWithCapabilities(string $capabilities): Model
    {
        $model = new Model();
        $model->setCapabilities($capabilities);

        return $model;
    }

    #[Test]
    public function aModelNoDiscoveryEverTouchedAttributesEveryCapabilityToTheOperator(): void
    {
        $model = $this->modelWithCapabilities('chat,vision');

        $provenance = $model->getCapabilityProvenance();

        self::assertCount(2, $provenance);
        foreach ($provenance as $entry) {
            self::assertSame(CapabilitySource::Operator, $entry->source);
            self::assertNull($entry->confirmedAt);
            self::assertFalse($entry->isVerified());
        }

        self::assertNull($model->getCapabilitiesConfirmedDate());
    }

    #[Test]
    public function aCapabilityTheProviderReportedIsAttributedToThatRun(): void
    {
        $model = $this->modelWithCapabilities('chat,vision');
        $when  = new DateTimeImmutable('2026-08-11 09:30:00');

        $model->recordCapabilityDiscovery(['chat', 'vision'], CapabilitySource::Discovery, $when);

        foreach ($model->getCapabilityProvenance() as $entry) {
            self::assertSame(CapabilitySource::Discovery, $entry->source);
            self::assertTrue($entry->isVerified());
            self::assertSame($when->getTimestamp(), $entry->confirmedAt?->getTimestamp());
        }

        self::assertSame($when->getTimestamp(), $model->getCapabilitiesConfirmedDate()?->getTimestamp());
    }

    /**
     * The case the whole feature exists for: the operator ticked `tools`, the
     * provider never mentioned it. Verification must keep the tick and stop
     * lending it the provider's authority.
     */
    #[Test]
    public function aCapabilityOnlyTheOperatorTickedStaysAttributedToTheOperator(): void
    {
        $model = $this->modelWithCapabilities('chat,tools');

        $model->recordCapabilityDiscovery(['chat'], CapabilitySource::Discovery, new DateTimeImmutable());

        $byName = [];
        foreach ($model->getCapabilityProvenance() as $entry) {
            $byName[$entry->getName()] = $entry;
        }

        self::assertSame('chat,tools', $model->getCapabilities(), 'Verification must not rewrite the declared set.');
        self::assertTrue($byName['chat']->isVerified());
        self::assertFalse($byName['tools']->isVerified());
        self::assertSame(CapabilitySource::Operator, $byName['tools']->source);
        self::assertNull($byName['tools']->confirmedAt);
    }

    /**
     * The static catalog is a shipped guess, not a provider answer. It is
     * attributed and dated, but it never counts as verified.
     */
    #[Test]
    public function theBundledCatalogIsAttributedButNeverCountsAsVerified(): void
    {
        $model = $this->modelWithCapabilities('chat');
        $when  = new DateTimeImmutable('2026-08-11 09:30:00');

        $model->recordCapabilityDiscovery(['chat'], CapabilitySource::Catalog, $when);

        $entry = $model->getCapabilityProvenance()[0];
        self::assertSame(CapabilitySource::Catalog, $entry->source);
        self::assertSame($when->getTimestamp(), $entry->confirmedAt?->getTimestamp());
        self::assertFalse($entry->isVerified());
    }

    /**
     * A discovery answer for a capability the operator later REMOVED must not
     * reappear: the declared set decides what is listed, provenance only says
     * where each listed entry came from.
     */
    #[Test]
    public function aDiscoveredCapabilityTheOperatorRemovedIsNotListed(): void
    {
        $model = $this->modelWithCapabilities('chat');
        $model->recordCapabilityDiscovery(['chat', 'vision'], CapabilitySource::Discovery, new DateTimeImmutable());

        $names = array_map(
            static fn(CapabilityProvenance $entry): string => $entry->getName(),
            $model->getCapabilityProvenance(),
        );

        self::assertSame(['chat'], $names);
    }

    #[Test]
    public function unknownTokensAreDroppedOnBothSidesOfTheComparison(): void
    {
        $model = $this->modelWithCapabilities('chat,telepathy');
        $model->recordCapabilityDiscovery(['chat', 'telepathy'], CapabilitySource::Discovery, new DateTimeImmutable());

        $provenance = $model->getCapabilityProvenance();

        self::assertCount(1, $provenance);
        self::assertSame(ModelCapability::CHAT, $provenance[0]->capability);
        self::assertSame('chat', $model->_getProperty('capabilitiesDiscovered'));
    }

    /**
     * A record that carries a source but no timestamp (or the reverse) is a
     * half-written confirmation. Reporting half a claim would be worse than
     * reporting none.
     */
    #[Test]
    public function aHalfWrittenConfirmationCountsAsNoConfirmation(): void
    {
        $model = $this->modelWithCapabilities('chat');
        $model->recordCapabilityDiscovery(['chat'], CapabilitySource::Discovery, new DateTimeImmutable());
        $model->_setProperty('capabilitiesConfirmedAt', 0);

        $entry = $model->getCapabilityProvenance()[0];

        self::assertSame(CapabilitySource::Operator, $entry->source);
        self::assertFalse($entry->isVerified());
    }
}

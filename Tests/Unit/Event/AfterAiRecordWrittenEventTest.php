<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Event;

use InvalidArgumentException;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * The provenance event says three things and stays sayable (ADR-187).
 */
#[CoversClass(AfterAiRecordWrittenEvent::class)]
final class AfterAiRecordWrittenEventTest extends TestCase
{
    #[Test]
    public function itCarriesTheRunTheRecordAndTheKind(): void
    {
        $event = new AfterAiRecordWrittenEvent(
            '6f1d2f7e-0b7e-4f8b-9a3c-0d2f7e0b7e4f',
            new RecordReference('pages', 42),
            WriteKind::CREATED,
        );

        self::assertSame('6f1d2f7e-0b7e-4f8b-9a3c-0d2f7e0b7e4f', $event->correlationId);
        self::assertSame('pages:42', (string)$event->record);
        self::assertSame(WriteKind::CREATED, $event->kind);
    }

    /**
     * A write nobody can attribute is not provenance. The loop already declines
     * to dispatch one; refusing it here too means a consumer written against
     * this class never has to handle an empty id, whatever dispatches it.
     */
    #[Test]
    public function itRefusesAWriteItCannotAttribute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1788500001);

        new AfterAiRecordWrittenEvent('', new RecordReference('pages', 42), WriteKind::CREATED);
    }

    /**
     * Whitespace is not an identifier either — the id is compared and joined
     * against `tx_nrllm_agentrun.uuid`, where a blank string matches nothing.
     */
    #[Test]
    public function itRefusesABlankCorrelationId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AfterAiRecordWrittenEvent("  \t ", new RecordReference('pages', 42), WriteKind::UPDATED);
    }

    /**
     * The payload rule of #896, asserted rather than promised: reference and
     * kind, plus the run they belong to, and nothing of the record's content. A
     * property added here has to answer that question first.
     */
    #[Test]
    public function itCarriesNoRecordPayloadBeyondTheReferenceAndTheKind(): void
    {
        $properties = array_map(
            static fn(ReflectionProperty $p): string => $p->getName(),
            (new ReflectionClass(AfterAiRecordWrittenEvent::class))->getProperties(),
        );
        sort($properties);

        self::assertSame(
            ['correlationId', 'kind', 'record'],
            $properties,
            'The provenance event gained a property. A field value, a rendered excerpt or a before/after '
            . 'belongs in the record, which the listener may read under its own permissions — not in a copy '
            . 'that leaks and goes stale (ADR-187).',
        );
    }
}

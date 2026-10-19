<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\ValueObject\GeneratorProvenance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GeneratorProvenance::class)]
final class GeneratorProvenanceTest extends TestCase
{
    /**
     * @return array{version:int,providerIdentifier:string,modelId:string,reportedModelId:string}
     */
    private static function record(): array
    {
        return [
            'version' => 1,
            'providerIdentifier' => 'private-openai',
            'modelId' => 'vendor/model:tag@deployment+revision',
            'reportedModelId' => 'snapshot-2026.10',
        ];
    }

    #[Test]
    public function exactVersionedIdentityRoundTripsAndIgnoresInputKeyOrder(): void
    {
        $record = self::record();
        $identity = GeneratorProvenance::fromArray(array_reverse($record, true));
        self::assertInstanceOf(GeneratorProvenance::class, $identity);
        self::assertSame($record, $identity->toArray());
        self::assertSame(
            $record['providerIdentifier'],
            $identity->providerIdentifier,
        );
        self::assertSame($record['modelId'], $identity->modelId);
        self::assertSame($record['reportedModelId'], $identity->reportedModelId);
    }

    #[Test]
    public function maximumIdentifierLengthsRemainEligible(): void
    {
        $record = [
            'version' => 1,
            'providerIdentifier' => str_repeat('p', 100),
            'modelId' => str_repeat('m', 150),
            'reportedModelId' => str_repeat('r', 150),
        ];
        $identity = GeneratorProvenance::fromArray($record);
        self::assertInstanceOf(GeneratorProvenance::class, $identity);
        self::assertSame($record, $identity->toArray());
    }

    #[Test]
    #[DataProvider('invalidRecords')]
    public function invalidUnboundedOrUnversionedRecordsAreUnknown(
        mixed $record,
    ): void {
        self::assertNull(GeneratorProvenance::fromArray($record));
    }

    /**
     * @return iterable<string,array{mixed}>
     */
    public static function invalidRecords(): iterable
    {
        foreach ([null, false, 1, 'json'] as $index => $value) {
            yield 'not array ' . $index => [$value];
        }

        yield 'legacy empty' => [[]];
        yield 'extra field' => [self::record() + ['endpoint' => 'https://private.invalid']];
        foreach (array_keys(self::record()) as $field) {
            $record = self::record();
            unset($record[$field]);
            yield 'missing ' . $field => [$record];
        }

        foreach ([0, 2, '1', 1.0, null] as $index => $version) {
            yield 'version ' . $index => [array_replace(self::record(), ['version' => $version])];
        }

        foreach (['providerIdentifier', 'modelId', 'reportedModelId'] as $field) {
            foreach ([
                '',
                true,
                1,
                [],
                null,
                'a b',
                "a\n",
                'ä',
                'credential?token=secret',
            ] as $index => $value) {
                yield $field . ' invalid ' . $index => [array_replace(self::record(), [$field => $value])];
            }
        }

        yield 'provider overlong' => [
            array_replace(
                self::record(),
                ['providerIdentifier' => str_repeat('p', 101)],
            ),
        ];
        foreach (['modelId', 'reportedModelId'] as $field) {
            yield $field . ' overlong' => [array_replace(self::record(), [$field => str_repeat('m', 151)])];
            yield $field . ' url' => [array_replace(self::record(), [$field => 'https://model.invalid'])];
            yield $field . ' control' => [array_replace(self::record(), [$field => "model\x00hidden"])];
        }
    }

    #[Test]
    public function allThreeGeneratorDimensionsMustAgree(): void
    {
        $identity = GeneratorProvenance::fromArray(self::record());
        self::assertInstanceOf(GeneratorProvenance::class, $identity);
        $same = GeneratorProvenance::fromArray(self::record());
        self::assertInstanceOf(GeneratorProvenance::class, $same);
        self::assertTrue($identity->sameGenerator($same));
        foreach (['providerIdentifier', 'modelId', 'reportedModelId'] as $field) {
            $other = GeneratorProvenance::fromArray(
                array_replace(self::record(), [$field => 'different']),
            );
            self::assertInstanceOf(GeneratorProvenance::class, $other);
            self::assertFalse($identity->sameGenerator($other));
        }
    }
}

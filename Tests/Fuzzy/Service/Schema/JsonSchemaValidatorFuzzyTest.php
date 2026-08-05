<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fuzzy\Service\Schema;

use Eris\Generator;
use Netresearch\NrLlm\Service\Schema\JsonSchemaValidator;
use Netresearch\NrLlm\Tests\Fuzzy\AbstractFuzzyTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The property that ties the two validation modes together (ADR-126):
 * anything strict accepts, lenient accepts. Strict only ever rejects MORE.
 *
 * This is what makes the strict mode safe to introduce next to a lenient
 * mode that guards a security boundary (ADR-105): no input can be waved
 * through by strict that lenient would have caught. The property holds
 * because strict's primitive type matcher byte-mirrors the lenient one —
 * if either matcher is ever "fixed" toward the JSON-Schema spec alone
 * (`5.0` as integer), this test is the tripwire.
 */
#[CoversClass(JsonSchemaValidator::class)]
class JsonSchemaValidatorFuzzyTest extends AbstractFuzzyTestCase
{
    #[Test]
    public function strictAcceptanceImpliesLenientAcceptance(): void
    {
        $validator = new JsonSchemaValidator();

        $this
            ->forAll(
                $this->jsonValue(),
                $this->subsetSchema(),
            )
            ->then(function (mixed $data, array $schema) use ($validator): void {
                if ($validator->validateStrict($data, $schema)) {
                    $this->assertTrue(
                        $validator->validate($data, $schema),
                        'strict accepted a value lenient rejects — the modes have diverged on a primitive',
                    );
                }

                // And regardless of outcome: neither mode may crash on any
                // combination — a validator that throws on hostile input is
                // itself the vulnerability.
                $this->assertIsBool($validator->validate($data, $schema));
            });
    }

    #[Test]
    public function strictNeverCrashesOnArbitraryData(): void
    {
        $validator = new JsonSchemaValidator();

        $this
            ->forAll($this->jsonValue())
            ->then(function (mixed $data) use ($validator): void {
                $this->assertIsBool($validator->validateStrict($data, [
                    'type'       => ['object', 'array', 'string', 'number', 'boolean', 'null'],
                    'minLength'  => 1,
                    'minimum'    => 0,
                    'minItems'   => 0,
                    'uniqueItems' => true,
                    'pattern'    => '^[\s\S]*$',
                ]));
            });
    }

    /**
     * Arbitrary JSON-decodable values: scalars, lists, maps, shallow nesting.
     *
     * @return Generator\OneOfGenerator<mixed>
     */
    private function jsonValue(): Generator\OneOfGenerator
    {
        $scalar = Generator\oneOf( // @phpstan-ignore function.notFound
            Generator\int(), // @phpstan-ignore function.notFound
            Generator\float(), // @phpstan-ignore function.notFound
            Generator\string(), // @phpstan-ignore function.notFound
            Generator\bool(), // @phpstan-ignore function.notFound
            Generator\constant(null), // @phpstan-ignore function.notFound
        );

        return Generator\oneOf( // @phpstan-ignore function.notFound
            $scalar,
            Generator\seq($scalar), // @phpstan-ignore function.notFound
            Generator\associative([ // @phpstan-ignore function.notFound
                'a' => $scalar,
                'b' => $scalar,
            ]),
        );
    }

    /**
     * Schemas inside the strict subset, exercising the keywords whose
     * primitive semantics the two modes share.
     *
     * @return Generator\OneOfGenerator<array<string, mixed>>
     */
    private function subsetSchema(): Generator\OneOfGenerator
    {
        return Generator\oneOf( // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'string']), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'integer']), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'number']), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'boolean']), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'null']), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'array']), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'object']), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'object', 'required' => ['a']]), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer']]]), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false]), // @phpstan-ignore function.notFound
            Generator\constant(['type' => ['string', 'null']]), // @phpstan-ignore function.notFound
            Generator\constant(['enum' => [1, 'a', true]]), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'string', 'minLength' => 1, 'maxLength' => 3]), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'number', 'minimum' => -1, 'maximum' => 1]), // @phpstan-ignore function.notFound
            Generator\constant(['type' => 'array', 'items' => ['type' => 'integer'], 'uniqueItems' => true]), // @phpstan-ignore function.notFound
            Generator\constant(['oneOf' => [['type' => 'string'], ['type' => 'integer']]]), // @phpstan-ignore function.notFound
        );
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Grader;

use Netresearch\NrLlm\Service\Evaluation\Assertion;
use Netresearch\NrLlm\Service\Evaluation\GoldenPrompt;
use Netresearch\NrLlm\Service\Evaluation\Grader\DeterministicGrader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeterministicGrader::class)]
final class DeterministicGraderTest extends TestCase
{
    private DeterministicGrader $grader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grader = new DeterministicGrader();
    }

    private function promptWith(Assertion ...$assertions): GoldenPrompt
    {
        return new GoldenPrompt('p', 'irrelevant', array_values($assertions));
    }

    #[Test]
    public function identifierIsDeterministic(): void
    {
        self::assertSame('deterministic', $this->grader->getIdentifier());
    }

    #[Test]
    public function exactMatchPasses(): void
    {
        $result = $this->grader->grade('  Paris  ', $this->promptWith(Assertion::exact('Paris')));

        self::assertTrue($result->passed);
        self::assertSame(1.0, $result->score);
        self::assertSame('deterministic', $result->grader);
    }

    #[Test]
    public function exactMismatchFails(): void
    {
        $result = $this->grader->grade('London', $this->promptWith(Assertion::exact('Paris')));

        self::assertFalse($result->passed);
        self::assertSame(0.0, $result->score);
    }

    #[Test]
    public function containsPassesAndFails(): void
    {
        self::assertTrue($this->grader->grade('The capital is Paris.', $this->promptWith(Assertion::contains('Paris')))->passed);
        self::assertFalse($this->grader->grade('The capital is London.', $this->promptWith(Assertion::contains('Paris')))->passed);
    }

    #[Test]
    public function regexPassesAndFails(): void
    {
        self::assertTrue($this->grader->grade('Result: 4', $this->promptWith(Assertion::regex('/\b4\b/')))->passed);
        self::assertFalse($this->grader->grade('Result: five', $this->promptWith(Assertion::regex('/\b4\b/')))->passed);
    }

    #[Test]
    public function jsonSchemaPassesForMatchingStructure(): void
    {
        $schema = '{"type":"object","required":["name","age"],"properties":{"name":{"type":"string"},"age":{"type":"integer"}}}';
        $result = $this->grader->grade('{"name":"Ada","age":36,"extra":true}', $this->promptWith(Assertion::jsonSchema($schema)));

        self::assertTrue($result->passed);
    }

    #[Test]
    public function jsonSchemaFailsForMissingRequiredKey(): void
    {
        $schema = '{"type":"object","required":["name","age"]}';
        $result = $this->grader->grade('{"name":"Ada"}', $this->promptWith(Assertion::jsonSchema($schema)));

        self::assertFalse($result->passed);
    }

    #[Test]
    public function jsonSchemaAcceptsEmptyObjectWhenNoKeysRequired(): void
    {
        // An empty JSON object decodes to [], which array_is_list() reports
        // as a list — the required-key gate must still treat it as an object
        // (matchesType already does), so a schema requiring no keys passes.
        $schema = '{"type":"object","required":[]}';
        $result = $this->grader->grade('{}', $this->promptWith(Assertion::jsonSchema($schema)));

        self::assertTrue($result->passed);
    }

    #[Test]
    public function jsonSchemaFailsForEmptyObjectMissingRequiredKey(): void
    {
        $schema = '{"type":"object","required":["name"]}';
        $result = $this->grader->grade('{}', $this->promptWith(Assertion::jsonSchema($schema)));

        self::assertFalse($result->passed);
    }

    #[Test]
    public function jsonSchemaFailsForWrongPropertyType(): void
    {
        $schema = '{"type":"object","properties":{"age":{"type":"integer"}}}';
        $result = $this->grader->grade('{"age":"not-a-number"}', $this->promptWith(Assertion::jsonSchema($schema)));

        self::assertFalse($result->passed);
    }

    #[Test]
    public function jsonSchemaFailsForInvalidJson(): void
    {
        $result = $this->grader->grade('not json at all', $this->promptWith(Assertion::jsonSchema('{"type":"object"}')));

        self::assertFalse($result->passed);
    }

    #[Test]
    public function scoreIsFractionOfSatisfiedAssertions(): void
    {
        $result = $this->grader->grade(
            'Paris',
            $this->promptWith(Assertion::contains('Paris'), Assertion::contains('missing')),
        );

        self::assertFalse($result->passed);
        self::assertSame(0.5, $result->score);
    }
}

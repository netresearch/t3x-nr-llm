<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Service\Tool\InputSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The enum of an input schema is enforced when an input is submitted
 * (ADR-214 item 9), beside the validator, which ignores it.
 */
#[CoversClass(InputSchema::class)]
final class InputSchemaTest extends TestCase
{
    #[Test]
    public function anAnswerThatIsAMemberHolds(): void
    {
        self::assertTrue(InputSchema::enumsHold(['answer' => 'About'], $this->schema(['Home', 'About'])));
        self::assertTrue(InputSchema::enumsHold(['level' => 1.0], $this->schema([1, 2], 'level')), 'two numbers compare by value');
        self::assertTrue(InputSchema::enumsHold([], $this->schema(['Home'])), "a missing key is the required check's to refuse");
        self::assertTrue(InputSchema::enumsHold(['free' => 'x'], ['type' => 'object', 'properties' => ['free' => ['type' => 'string']]]), 'no enum, no constraint');
    }

    #[Test]
    public function anAnswerOutsideTheMembersIsRefused(): void
    {
        foreach ([
            'another string'          => [['answer' => 'Contact'], $this->schema(['Home', 'About'])],
            'a different case'        => [['answer' => 'home'], $this->schema(['Home', 'About'])],
            'a string for a number'   => [['level' => '1'], $this->schema([1, 2], 'level')],
            'a number for a string'   => [['answer' => 1], $this->schema(['1', '2'])],
            'a boolean for a number'  => [['level' => true], $this->schema([1, 2], 'level')],
            'a number between'        => [['level' => 1.5], $this->schema([1, 2], 'level')],
            'a list for a member'     => [['answer' => ['Home']], $this->schema(['Home'])],
            'an enum that is no list' => [['answer' => 'Home'], ['type' => 'object', 'properties' => ['answer' => ['enum' => 'Home']]]],
        ] as $case => [$data, $schema]) {
            self::assertFalse(InputSchema::enumsHold($data, $schema), $case);
        }
    }

    /**
     * @param list<mixed> $members
     *
     * @return array<string, mixed>
     */
    private function schema(array $members, string $name = 'answer'): array
    {
        return ['type' => 'object', 'properties' => [$name => ['enum' => $members]], 'required' => [$name]];
    }
}

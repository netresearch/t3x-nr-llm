<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Protocol;

use DateTimeImmutable;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aListTasksQuery;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aPart;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aRole;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aSendMessageConfiguration;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aTaskState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pinned proto (A2A-S0-4) and the field names the client emits.
 *
 * The serialisation tests read their field names from the fixture, not from
 * a list typed here: a member the client sends that the proto does not name
 * is a bug the remote would silently ignore.
 */
#[CoversClass(A2aMessage::class)]
#[CoversClass(A2aPart::class)]
#[CoversClass(A2aSendMessageConfiguration::class)]
#[CoversClass(A2aListTasksQuery::class)]
final class A2aProtoFixtureTest extends TestCase
{
    private const PROTO = __DIR__ . '/../../../../../Fixtures/A2a/a2a.v1.0.1.proto';

    private const SHA256 = 'e195bf96ab630c69797851970203e1b2b6b19528f2e9803b7d904b91a5104016';

    #[Test]
    public function theFixtureIsTheV101ProtoByteForByte(): void
    {
        self::assertSame(self::SHA256, hash_file('sha256', self::PROTO));
    }

    #[Test]
    public function everyTaskStateCaseIsAProtoEnumValueAndViceVersa(): void
    {
        $protoValues = $this->enumValues('TaskState');
        $cases       = array_map(static fn (A2aTaskState $state): string => $state->value, A2aTaskState::cases());

        sort($protoValues);
        sort($cases);
        self::assertSame($protoValues, $cases);
    }

    #[Test]
    public function everyRoleCaseIsAProtoEnumValueAndViceVersa(): void
    {
        $protoValues = $this->enumValues('Role');
        $cases       = array_map(static fn (A2aRole $role): string => $role->value, A2aRole::cases());

        sort($protoValues);
        sort($cases);
        self::assertSame($protoValues, $cases);
    }

    #[Test]
    public function aMessageEmitsOnlyProtoFieldNames(): void
    {
        $message = new A2aMessage(
            'm-1',
            A2aRole::USER,
            [A2aPart::text('hi', 'text/plain', ['k' => 'v'])],
            'ctx',
            'task',
            ['m' => 1],
            ['urn:ext'],
            ['t-0'],
        );

        $json = $message->toArray();
        self::assertSame([], array_values(array_diff(array_keys($json), $this->fieldNames('Message'))));
        self::assertCount(8, $json, 'every member was populated, so every member must be emitted');
    }

    #[Test]
    public function everyPartKindEmitsOnlyProtoFieldNames(): void
    {
        $parts = [
            A2aPart::text('t', 'text/plain', ['a' => 1]),
            A2aPart::raw("\x89PNG", 'image/png', 'dot.png', ['a' => 1]),
            A2aPart::url('https://example.org/f', 'application/pdf', 'f.pdf'),
            A2aPart::data(['x' => 1], 'application/json'),
        ];

        foreach ($parts as $part) {
            self::assertSame([], array_values(array_diff(array_keys($part->toArray()), $this->fieldNames('Part'))));
        }
    }

    #[Test]
    public function theSendConfigurationEmitsOnlyProtoFieldNames(): void
    {
        $json = (new A2aSendMessageConfiguration(true, ['text/plain'], 0))->toArray();

        self::assertSame([], array_values(array_diff(array_keys($json), $this->fieldNames('SendMessageConfiguration'))));
        self::assertCount(3, $json);
    }

    #[Test]
    public function theListQueryEmitsOnlyProtoFieldNames(): void
    {
        $json = (new A2aListTasksQuery(
            'ctx',
            A2aTaskState::WORKING,
            10,
            'cursor',
            2,
            new DateTimeImmutable('2026-11-10T21:54:29.148Z'),
            true,
        ))->toArray();

        self::assertSame([], array_values(array_diff(array_keys($json), $this->fieldNames('ListTasksRequest'))));
        self::assertCount(7, $json);
    }

    /**
     * The JSON (camelCase) names of a message's fields.
     *
     * @return list<string>
     */
    private function fieldNames(string $message): array
    {
        $body = $this->block('message', $message);
        preg_match_all('/^\s*(?:optional |repeated )?[\w.<>, ]+? (\w+) = \d+/m', $body, $matches);
        self::assertNotSame([], $matches[1], sprintf('message %s has no fields in the fixture', $message));

        return array_map(
            static fn (string $name): string => lcfirst(str_replace('_', '', ucwords($name, '_'))),
            $matches[1],
        );
    }

    /**
     * @return list<string>
     */
    private function enumValues(string $enum): array
    {
        preg_match_all('/^\s*([A-Z0-9_]+) = \d+;/m', $this->block('enum', $enum), $matches);

        return $matches[1];
    }

    private function block(string $keyword, string $name): string
    {
        $proto = (string) file_get_contents(self::PROTO);
        self::assertSame(1, preg_match('/^' . $keyword . ' ' . $name . ' \{\n(.*?)^\}/ms', $proto, $match), sprintf('%s %s not found', $keyword, $name));

        return $match[1];
    }
}

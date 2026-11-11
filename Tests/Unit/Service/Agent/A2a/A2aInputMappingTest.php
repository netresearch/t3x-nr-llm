<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a;

use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aInputMapping;
use Netresearch\NrLlm\Service\Tool\Builtin\AskChoiceTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How a client's text answers an input pause (spec 051 A2A-MT1, A2A-MT2).
 */
#[CoversClass(A2aInputMapping::class)]
final class A2aInputMappingTest extends TestCase
{
    /**
     * A schema asking for one required string takes the text as its value;
     * its enum is what the status message offers.
     */
    #[Test]
    public function oneRequiredStringTakesTheText(): void
    {
        $schema = (new AskChoiceTool())->inputSchemaFor(['question' => 'Which page?', 'options' => ['Home', 'About']]);

        self::assertSame(['answer' => 'Home'], A2aInputMapping::submission(" Home\n", $schema));
        self::assertSame(['Home', 'About'], A2aInputMapping::allowedAnswers($schema));
        self::assertSame('Which page?', A2aInputMapping::title($schema));
    }

    /**
     * Any other schema takes a JSON object, and nothing else: a text that
     * is not one is not guessed into a value.
     */
    #[Test]
    public function anotherSchemaTakesAJsonObjectOnly(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']], 'required' => ['a', 'b']];

        self::assertSame(['a' => 'x', 'b' => 2], A2aInputMapping::submission('{"a":"x","b":2}', $schema));
        self::assertNull(A2aInputMapping::submission('x and 2', $schema));
        self::assertNull(A2aInputMapping::submission('["x",2]', $schema));
        self::assertSame([], A2aInputMapping::allowedAnswers($schema));
        self::assertSame('', A2aInputMapping::title($schema));
    }
}

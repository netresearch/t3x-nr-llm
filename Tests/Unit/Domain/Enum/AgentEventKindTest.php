<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\AgentEventKind;
use Netresearch\NrLlm\Domain\ValueObject\RunStep;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AgentEventKindTest extends TestCase
{
    #[Test]
    public function valuesListsAllBackingStrings(): void
    {
        self::assertSame(['request', 'llm', 'tool', 'assembled'], AgentEventKind::values());
    }

    #[Test]
    public function isValidIsTrueForKnownAndFalseForUnknown(): void
    {
        self::assertTrue(AgentEventKind::isValid('llm'));
        self::assertFalse(AgentEventKind::isValid('approval'));
    }

    #[Test]
    public function fromRunStepKindMapsEveryRunStepKind(): void
    {
        self::assertSame(AgentEventKind::REQUEST, AgentEventKind::fromRunStepKind(RunStep::KIND_REQUEST));
        self::assertSame(AgentEventKind::LLM, AgentEventKind::fromRunStepKind(RunStep::KIND_LLM));
        self::assertSame(AgentEventKind::TOOL, AgentEventKind::fromRunStepKind(RunStep::KIND_TOOL));
        self::assertSame(AgentEventKind::ASSEMBLED, AgentEventKind::fromRunStepKind(RunStep::KIND_ASSEMBLED));
    }

    #[Test]
    public function fromRunStepKindReturnsNullForUnknown(): void
    {
        self::assertNull(AgentEventKind::fromRunStepKind('approval'));
    }
}

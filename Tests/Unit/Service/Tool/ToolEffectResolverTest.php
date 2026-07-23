<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolEffectResolver::class)]
final class ToolEffectResolverTest extends TestCase
{
    #[Test]
    public function usesAnExplicitDeclaration(): void
    {
        $resolver = new ToolEffectResolver(new ToolRegistry([]));

        self::assertSame(
            ToolEffect::NON_IDEMPOTENT_WRITE,
            $resolver->effectForTool(new FakeTool('send_mail', effect: ToolEffect::NON_IDEMPOTENT_WRITE)),
        );
    }

    #[Test]
    public function defaultsAnUndeclaredToolToReadOnly(): void
    {
        // A tool that does not implement ToolEffectInterface is read-only — the
        // opt-in default that keeps all 45 shipped builtins unchanged.
        $plain = self::createStub(ToolInterface::class);
        $plain->method('getSpec')->willReturn(ToolSpec::function('list_pages', '', ['type' => 'object', 'properties' => []]));

        $resolver = new ToolEffectResolver(new ToolRegistry([]));

        self::assertSame(ToolEffect::READ_ONLY, $resolver->effectForTool($plain));
    }

    #[Test]
    public function resolvesByNameThroughTheRegistry(): void
    {
        $registry = new ToolRegistry([new FakeTool('upsert_record', effect: ToolEffect::IDEMPOTENT_WRITE)]);
        $resolver = new ToolEffectResolver($registry);

        self::assertSame(ToolEffect::IDEMPOTENT_WRITE, $resolver->effectFor('upsert_record'));
    }

    #[Test]
    public function failsClosedForAnUnknownName(): void
    {
        // A stale or removed tool referenced in a persisted step must be treated
        // as the most dangerous effect — audit-critical AND never auto-retried —
        // not waved through as a repeatable read.
        $resolver = new ToolEffectResolver(new ToolRegistry([]));

        self::assertSame(ToolEffect::NON_IDEMPOTENT_WRITE, $resolver->effectFor('was_removed'));
    }
}

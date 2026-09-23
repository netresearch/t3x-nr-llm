<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Fixtures\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\RecordCreatorInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * The creator an extension would ship for its own table (ADR-197).
 *
 * Registered through the fixture extension's Configuration/Services.yaml,
 * tagged `nr_llm.tool` the way a third-party writer is, so the functional test
 * can show that the CONTAINER-built `create_record_draft` sees it and steps
 * back from `tx_writerfixture_item`. It never writes anything.
 */
final readonly class WriterFixtureItemCreatorTool implements ToolInterface, ToolEffectInterface, RecordCreatorInterface
{
    public const NAME = 'create_writer_fixture_item';

    public function getCreatedTables(): array
    {
        return ['tx_writerfixture_item'];
    }

    public function getEffect(): ToolEffect
    {
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            self::NAME,
            'Fixture creator for tx_writerfixture_item. Writes nothing.',
            ['type' => 'object', 'properties' => []],
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        return ToolResult::error('The fixture creator writes nothing.');
    }

    public function isEnabledByDefault(): bool
    {
        return false;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        return 'nrllm_writer_fixture';
    }
}

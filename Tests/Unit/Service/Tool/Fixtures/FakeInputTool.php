<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\RequiresInputInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;

/**
 * A tool that opts into typed user input (ADR-105), for the tool-loop tests.
 *
 * Captures the arguments it was executed with so a test can assert the
 * human-input overlay ({@see \Netresearch\NrLlm\Service\Tool\ToolLoopService::resumeWithInput()})
 * merged the submitted values and stripped the model's own for declared keys.
 */
final class FakeInputTool implements ToolInterface, RequiresInputInterface
{
    /** @var array<string, mixed>|null the arguments execute() last received */
    public ?array $capturedArguments = null;

    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        private readonly string $name = 'ask_user',
        private readonly array $schema = ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function($this->name, 'asks the user for input', ['type' => 'object', 'properties' => []]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $this->capturedArguments = $arguments;

        return ToolResult::text('input-tool ran');
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        return false;
    }

    public function getGroup(): string
    {
        return 'test';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return $this->schema;
    }
}

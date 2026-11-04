<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The operations the "Why this model?" readout offers (ADR-148, ADR-256).
 *
 * CoversNothing: the backend controllers are excluded from the coverage source
 * (Build/phpunit.xml), and naming one fails a coverage run.
 */
#[CoversNothing]
final class LlmModuleControllerRoutedOperationsTest extends TestCase
{
    #[Test]
    public function everyOperationADecisionIsTakenForIsOffered(): void
    {
        // Completion, embedding and stream constrain no capability but select
        // their own call history; without them the readout cannot show the
        // decision such a call gets.
        self::assertSame(
            ['chat', 'complete', 'embed', 'vision', 'tools', 'stream', 'decision'],
            array_map(static fn (ProviderOperation $operation): string => $operation->value, $this->offered()),
        );
    }

    /**
     * @return list<ProviderOperation>
     */
    private function offered(): array
    {
        $class      = new ReflectionClass(LlmModuleController::class);
        $controller = $class->newInstanceWithoutConstructor();

        /** @var list<ProviderOperation> $operations */
        $operations = $class->getMethod('routedOperations')->invoke($controller);

        return $operations;
    }
}

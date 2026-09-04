<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Fixtures;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A PSR-14 dispatcher that keeps what it was handed.
 *
 * Named rather than anonymous so the tests can state the property type they
 * assert on: an anonymous class typed as `object` costs every assertion an
 * `instanceof` that says nothing about the test's subject.
 */
final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $event): object
    {
        $this->dispatched[] = $event;

        return $event;
    }
}

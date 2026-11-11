<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Agent\A2a\Agent;

use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aCardConfirmItems;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The confirmation item names the card the form was built from.
 */
#[CoversClass(A2aCardConfirmItems::class)]
final class A2aCardConfirmItemsTest extends TestCase
{
    #[Test]
    public function theItemCarriesTheDigestOfTheCardInTheForm(): void
    {
        $params = ['items' => [], 'row' => ['interface_url' => 'https://b.example/rpc', 'interface_tenant' => 't', 'card_capabilities' => 'streaming']];

        (new A2aCardConfirmItems())->addItems($params);

        self::assertSame(
            [['label' => 'Confirm: https://b.example/rpc', 'value' => A2aRemoteAgent::cardDigest('https://b.example/rpc', 't', 'streaming')]],
            $params['items'],
        );
    }

    #[Test]
    public function aRecordWithoutAnImportedCardOffersNothingToConfirm(): void
    {
        $params = ['items' => [], 'row' => ['interface_url' => '']];

        (new A2aCardConfirmItems())->addItems($params);

        self::assertSame([], $params['items']);
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Feature;

use Netresearch\NrLlm\Service\Feature\TranslationPolisher;
use Netresearch\NrLlm\Service\Feature\TranslationService;
use Netresearch\NrLlm\Service\Feature\TranslationServiceInterface;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

/**
 * The revision after a DeepL translation (ADR-233) needs the container to hand
 * `TranslationService` its polisher. The parameter is optional, so a wiring
 * that leaves it out would not fail: every requested revision would come back
 * as "unavailable". The unit tests construct the service by hand and cannot
 * tell; this one takes it from the container.
 */
#[CoversClass(TranslationService::class)]
final class TranslationServicePolisherWiringTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function theContainerHandsTheServiceItsPolisher(): void
    {
        $service = $this->get(TranslationServiceInterface::class);
        self::assertInstanceOf(TranslationService::class, $service);

        self::assertInstanceOf(
            TranslationPolisher::class,
            (new ReflectionProperty(TranslationService::class, 'polisher'))->getValue($service),
        );
    }
}

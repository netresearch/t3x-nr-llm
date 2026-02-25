<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Exception;

use Netresearch\NrLlm\Specialized\Exception\SpecializedServiceException;
use Netresearch\NrLlm\Specialized\Exception\TranslatorException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SpecializedServiceException::class)]
class SpecializedServiceExceptionTest extends AbstractUnitTestCase
{
    #[Test]
    public function getDetailedMessageIncludesServiceAndMessage(): void
    {
        $exception = new TranslatorException('API error', 'translation');

        self::assertEquals('[translation] API error', $exception->getDetailedMessage());
    }

    #[Test]
    public function getDetailedMessageIncludesContext(): void
    {
        $exception = new TranslatorException(
            'Translation failed',
            'translation',
            ['source' => 'en', 'target' => 'de'],
        );

        $detailed = $exception->getDetailedMessage();

        self::assertStringContainsString('[translation] Translation failed', $detailed);
        self::assertStringContainsString('Context:', $detailed);
        self::assertStringContainsString('"source":"en"', $detailed);
        self::assertStringContainsString('"target":"de"', $detailed);
    }

    #[Test]
    public function getDetailedMessageExcludesEmptyContext(): void
    {
        $exception = new TranslatorException('Error', 'translation', []);

        self::assertEquals('[translation] Error', $exception->getDetailedMessage());
        self::assertStringNotContainsString('Context:', $exception->getDetailedMessage());
    }

    #[Test]
    public function getDetailedMessageExcludesNullContext(): void
    {
        $exception = new TranslatorException('Error', 'translation', null);

        self::assertEquals('[translation] Error', $exception->getDetailedMessage());
        self::assertStringNotContainsString('Context:', $exception->getDetailedMessage());
    }
}

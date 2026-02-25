<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Specialized\Exception;

use Netresearch\NrLlm\Specialized\Exception\SpeechServiceException;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SpeechServiceException::class)]
class SpeechServiceExceptionTest extends AbstractUnitTestCase
{
    #[Test]
    public function constructorSetsPropertiesCorrectly(): void
    {
        $exception = new SpeechServiceException(
            'Audio transcription failed',
            'speech',
            ['format' => 'wav', 'duration' => 120],
        );

        self::assertEquals('Audio transcription failed', $exception->getMessage());
        self::assertEquals('speech', $exception->service);
        self::assertEquals(['format' => 'wav', 'duration' => 120], $exception->context);
    }

    #[Test]
    public function textToSpeechFailureContext(): void
    {
        $exception = new SpeechServiceException(
            'TTS generation failed',
            'speech',
            ['voice' => 'alloy', 'model' => 'tts-1'],
        );

        self::assertEquals('TTS generation failed', $exception->getMessage());
        self::assertEquals('speech', $exception->service);
        self::assertNotNull($exception->context);
        self::assertEquals('alloy', $exception->context['voice']);
        self::assertEquals('tts-1', $exception->context['model']);
    }
}

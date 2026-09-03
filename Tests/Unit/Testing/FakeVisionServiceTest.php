<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Testing;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Service\Feature\VisionServiceInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrLlm\Testing\FakeVisionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(FakeVisionService::class)]
final class FakeVisionServiceTest extends TestCase
{
    #[Test]
    public function implementsTheRealInterface(): void
    {
        self::assertInstanceOf(VisionServiceInterface::class, new FakeVisionService());
    }

    #[Test]
    public function returnsTheCannedStringForASingleImage(): void
    {
        $subject = new FakeVisionService();
        $subject->altTextResult = 'a red bus';

        self::assertSame('a red bus', $subject->generateAltText('https://example.test/a.png'));
    }

    #[Test]
    public function echoesArityReturningOneResultPerImageForABatch(): void
    {
        $subject = new FakeVisionService();
        $subject->titleResult = 'canned title';

        $result = $subject->generateTitle(['https://example.test/a.png', 'https://example.test/b.png']);

        self::assertSame(['canned title', 'canned title'], $result);
    }

    #[Test]
    public function analyzeImageEchoesArityAndRecordsTheCustomPrompt(): void
    {
        $subject = new FakeVisionService();
        $subject->analyzeImageResult = 'analysis';

        self::assertSame('analysis', $subject->analyzeImage('https://example.test/a.png', 'What is this?'));
        self::assertSame('What is this?', $subject->analyzeImageCalls[0]['customPrompt']);
    }

    #[Test]
    public function analyzeImageFullReturnsADefaultResponseWhenNoneIsCanned(): void
    {
        $subject = new FakeVisionService();
        $subject->descriptionResult = 'a cat on a mat';

        $response = $subject->analyzeImageFull('https://example.test/a.png', 'describe');

        self::assertInstanceOf(VisionResponse::class, $response);
        self::assertSame('a cat on a mat', $response->description);
    }

    #[Test]
    public function analyzeImageFullReturnsTheCannedResponse(): void
    {
        $canned  = new VisionResponse('canned', 'm', new UsageStatistics(0, 0, 0));
        $subject = new FakeVisionService();
        $subject->analyzeImageFullResult = $canned;

        self::assertSame($canned, $subject->analyzeImageFull('https://example.test/a.png', 'describe'));
    }

    #[Test]
    public function recordsCallsPerMethod(): void
    {
        $options = new VisionOptions();

        $subject = new FakeVisionService();
        $subject->generateDescription('https://example.test/a.png', $options);

        self::assertCount(1, $subject->generateDescriptionCalls);
        self::assertSame('https://example.test/a.png', $subject->generateDescriptionCalls[0]['imageUrl']);
        self::assertSame($options, $subject->generateDescriptionCalls[0]['options']);
    }

    #[Test]
    public function throwsConfiguredThrowableInsteadOfReturning(): void
    {
        $subject = new FakeVisionService();
        $subject->throwable = new RuntimeException('boom');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $subject->generateAltText('https://example.test/a.png');
    }

    #[Test]
    public function throwableIsOneShotAndTheNextCallReturnsAgain(): void
    {
        $subject = new FakeVisionService();
        $subject->altTextResult = 'after';
        $subject->throwable = new RuntimeException('boom');

        try {
            $subject->generateAltText('https://example.test/a.png');
            self::fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException) {
            // one-shot: cleared before throwing
        }

        self::assertNull($subject->throwable);
        self::assertSame('after', $subject->generateAltText('https://example.test/a.png'));
    }
}

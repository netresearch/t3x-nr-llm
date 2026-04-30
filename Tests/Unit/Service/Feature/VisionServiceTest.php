<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
use Netresearch\NrLlm\Service\Budget\BackendUserContextResolverInterface;
use Netresearch\NrLlm\Service\Feature\VisionService;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\VisionOptions;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

#[CoversClass(VisionService::class)]
class VisionServiceTest extends AbstractUnitTestCase
{
    private VisionService $subject;
    private LlmServiceManagerInterface $llmManagerStub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llmManagerStub = self::createStub(LlmServiceManagerInterface::class);
        $this->subject = new VisionService($this->llmManagerStub);
    }

    /**
     * Create a subject with a mock LLM manager for expectation testing.
     *
     * @return array{subject: VisionService, llmManager: LlmServiceManagerInterface&MockObject}
     */
    private function createSubjectWithMockManager(): array
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        return [
            'subject' => new VisionService($llmManagerMock),
            'llmManager' => $llmManagerMock,
        ];
    }

    #[Test]
    public function generateAltTextReturnsSingleString(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $imageUrl = 'https://example.com/image.jpg';
        $expectedAltText = 'A red barn in a green field';

        $llmManagerMock
            ->expects(self::once())
            ->method('vision')
            ->willReturn($this->createMockVisionResponse($expectedAltText));

        $result = $subject->generateAltText($imageUrl);

        self::assertEquals($expectedAltText, $result);
    }

    #[Test]
    public function generateAltTextProcessesBatch(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $imageUrls = [
            'https://example.com/img1.jpg',
            'https://example.com/img2.jpg',
        ];

        $llmManagerMock
            ->expects(self::exactly(2))
            ->method('vision')
            ->willReturnOnConsecutiveCalls(
                $this->createMockVisionResponse('Alt text 1'),
                $this->createMockVisionResponse('Alt text 2'),
            );

        $results = $subject->generateAltText($imageUrls);

        self::assertIsArray($results);
        self::assertCount(2, $results);
    }

    #[Test]
    public function generateTitleReturnsString(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $imageUrl = 'https://example.com/image.jpg';
        $expectedTitle = 'SEO optimized title';

        $llmManagerMock
            ->expects(self::once())
            ->method('vision')
            ->willReturn($this->createMockVisionResponse($expectedTitle));

        $result = $subject->generateTitle($imageUrl);

        self::assertEquals($expectedTitle, $result);
    }

    #[Test]
    public function generateDescriptionReturnsString(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $imageUrl = 'https://example.com/image.jpg';
        $expectedDescription = 'Detailed description of the image';

        $llmManagerMock
            ->expects(self::once())
            ->method('vision')
            ->willReturn($this->createMockVisionResponse($expectedDescription));

        $result = $subject->generateDescription($imageUrl);

        self::assertEquals($expectedDescription, $result);
    }

    #[Test]
    public function analyzeImageWithCustomPrompt(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $imageUrl = 'https://example.com/chart.jpg';
        $customPrompt = 'What trends are shown in this chart?';
        $expectedAnalysis = 'The chart shows upward trends';

        $llmManagerMock
            ->expects(self::once())
            ->method('vision')
            ->with(
                self::callback(static function (array $content) use ($customPrompt): bool {
                    /** @var array{type: string, text: string} $textPart */
                    $textPart = $content[0];
                    /** @var array{type: string} $imagePart */
                    $imagePart = $content[1];

                    return $textPart['type'] === 'text'
                        && $textPart['text'] === $customPrompt
                        && $imagePart['type'] === 'image_url';
                }),
                self::anything(),
            )
            ->willReturn($this->createMockVisionResponse($expectedAnalysis));

        $result = $subject->analyzeImage($imageUrl, $customPrompt);

        self::assertEquals($expectedAnalysis, $result);
    }

    #[Test]
    public function analyzeImageFullReturnsVisionResponse(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $imageUrl = 'https://example.com/image.jpg';
        $analysis = 'Detailed analysis';

        $llmManagerMock
            ->expects(self::once())
            ->method('vision')
            ->willReturn($this->createMockVisionResponse($analysis));

        $result = $subject->analyzeImageFull($imageUrl, 'Describe this image');

        self::assertInstanceOf(VisionResponse::class, $result);
        self::assertEquals($analysis, $result->description);
    }

    #[Test]
    public function throwsOnInvalidImageUrl(): void
    {
        $invalidUrl = 'not-a-valid-url';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid image URL');

        $this->subject->generateAltText($invalidUrl);
    }

    #[Test]
    public function acceptsBase64DataUri(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $base64Uri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $llmManagerMock
            ->expects(self::once())
            ->method('vision')
            ->willReturn($this->createMockVisionResponse('Alt text'));

        $result = $subject->generateAltText($base64Uri);

        self::assertIsString($result);
    }

    #[Test]
    public function appliesDetailLevelOption(): void
    {
        ['subject' => $subject, 'llmManager' => $llmManagerMock] = $this->createSubjectWithMockManager();

        $imageUrl = 'https://example.com/image.jpg';

        $llmManagerMock
            ->expects(self::once())
            ->method('vision')
            ->with(
                self::callback(static function (array $content): bool {
                    /** @var array{image_url: array{detail: string}} $imagePart */
                    $imagePart = $content[1];

                    return $imagePart['image_url']['detail'] === 'high';
                }),
                self::anything(),
            )
            ->willReturn($this->createMockVisionResponse('Alt text'));

        $subject->generateAltText($imageUrl, new VisionOptions(detailLevel: 'high'));
    }

    #[Test]
    public function analyzeImageFullAutoPopulatesBeUserUidFromResolver(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $resolver = $this->createMock(BackendUserContextResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolveBeUserUid')
            ->willReturn(42);

        $subject = new VisionService($llmManagerMock, $resolver);

        $llmManagerMock->expects(self::once())
            ->method('vision')
            ->with(
                self::anything(),
                self::callback(static fn(VisionOptions $options): bool
                    => $options->getBeUserUid() === 42),
            )
            ->willReturn($this->createMockVisionResponse('Alt text'));

        $subject->analyzeImageFull('https://example.com/image.jpg', 'describe');
    }

    #[Test]
    public function analyzeImageFullPreservesBudgetFieldsThroughDetailLevelRebuild(): void
    {
        // Regression test for the same class of bug Gemini caught on
        // PR #177 (CompletionService stop_sequences). VisionService
        // also rebuilds VisionOptions to drop `detail_level` after
        // copying it onto the content payload — the rebuild must
        // copy `beUserUid` and `plannedCost` across, otherwise
        // BudgetMiddleware sees no metadata.
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $subject = new VisionService($llmManagerMock);

        $llmManagerMock->expects(self::once())
            ->method('vision')
            ->with(
                self::anything(),
                self::callback(static fn(VisionOptions $options): bool
                    => $options->getBeUserUid() === 13
                    && $options->getPlannedCost() === 0.07),
            )
            ->willReturn($this->createMockVisionResponse('OK'));

        $options = (new VisionOptions(detailLevel: 'high'))
            ->withBeUserUid(13)
            ->withPlannedCost(0.07);

        $subject->analyzeImageFull('https://example.com/i.jpg', 'p', $options);
    }

    #[Test]
    public function analyzeImageFullWorksWithoutResolverDependency(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $subject = new VisionService($llmManagerMock);

        $llmManagerMock->expects(self::once())
            ->method('vision')
            ->with(
                self::anything(),
                self::callback(static fn(VisionOptions $options): bool
                    => $options->getBeUserUid() === null),
            )
            ->willReturn($this->createMockVisionResponse('OK'));

        $subject->analyzeImageFull('https://example.com/i.jpg', 'p');
    }

    /**
     * Create mock VisionResponse.
     */
    private function createMockVisionResponse(string $description): VisionResponse
    {
        return new VisionResponse(
            description: $description,
            model: 'gpt-4o',
            usage: new UsageStatistics(
                promptTokens: 100,
                completionTokens: 50,
                totalTokens: 150,
            ),
            provider: 'openai',
        );
    }
}

<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Feature;

use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Domain\Model\VisionResponse;
use Netresearch\NrLlm\Exception\InvalidArgumentException;
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
    private LlmServiceManagerInterface&MockObject $llmManagerMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $this->subject = new VisionService($this->llmManagerMock);
    }

    #[Test]
    public function generateAltTextReturnsSingleString(): void
    {
        $imageUrl = 'https://example.com/image.jpg';
        $expectedAltText = 'A red barn in a green field';

        $this->mockVisionResponse($expectedAltText);

        $result = $this->subject->generateAltText($imageUrl);

        $this->assertEquals($expectedAltText, $result);
    }

    #[Test]
    public function generateAltTextProcessesBatch(): void
    {
        $imageUrls = [
            'https://example.com/img1.jpg',
            'https://example.com/img2.jpg',
        ];

        $this->llmManagerMock
            ->method('vision')
            ->willReturnOnConsecutiveCalls(
                $this->createMockVisionResponse('Alt text 1'),
                $this->createMockVisionResponse('Alt text 2')
            );

        $results = $this->subject->generateAltText($imageUrls);

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
    }

    #[Test]
    public function generateTitleReturnsString(): void
    {
        $imageUrl = 'https://example.com/image.jpg';
        $expectedTitle = 'SEO optimized title';

        $this->mockVisionResponse($expectedTitle);

        $result = $this->subject->generateTitle($imageUrl);

        $this->assertEquals($expectedTitle, $result);
    }

    #[Test]
    public function generateDescriptionReturnsString(): void
    {
        $imageUrl = 'https://example.com/image.jpg';
        $expectedDescription = 'Detailed description of the image';

        $this->mockVisionResponse($expectedDescription);

        $result = $this->subject->generateDescription($imageUrl);

        $this->assertEquals($expectedDescription, $result);
    }

    #[Test]
    public function analyzeImageWithCustomPrompt(): void
    {
        $imageUrl = 'https://example.com/chart.jpg';
        $customPrompt = 'What trends are shown in this chart?';
        $expectedAnalysis = 'The chart shows upward trends';

        $this->llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->callback(function (array $content) use ($customPrompt) {
                    return $content[0]['type'] === 'text'
                        && $content[0]['text'] === $customPrompt
                        && $content[1]['type'] === 'image_url';
                }),
                $this->anything()
            )
            ->willReturn($this->createMockVisionResponse($expectedAnalysis));

        $result = $this->subject->analyzeImage($imageUrl, $customPrompt);

        $this->assertEquals($expectedAnalysis, $result);
    }

    #[Test]
    public function analyzeImageFullReturnsVisionResponse(): void
    {
        $imageUrl = 'https://example.com/image.jpg';
        $analysis = 'Detailed analysis';

        $this->mockVisionResponse($analysis);

        $result = $this->subject->analyzeImageFull($imageUrl, 'Describe this image');

        $this->assertInstanceOf(VisionResponse::class, $result);
        $this->assertEquals($analysis, $result->description);
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
        $base64Uri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $this->mockVisionResponse('Alt text');

        $result = $this->subject->generateAltText($base64Uri);

        $this->assertIsString($result);
    }

    #[Test]
    public function appliesDetailLevelOption(): void
    {
        $imageUrl = 'https://example.com/image.jpg';

        $this->llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->callback(function (array $content) {
                    return $content[1]['image_url']['detail'] === 'high';
                }),
                $this->anything()
            )
            ->willReturn($this->createMockVisionResponse('Alt text'));

        $this->subject->generateAltText($imageUrl, new VisionOptions(detailLevel: 'high'));
    }

    /**
     * Mock vision response
     */
    private function mockVisionResponse(string $content): void
    {
        $this->llmManagerMock
            ->method('vision')
            ->willReturn($this->createMockVisionResponse($content));
    }

    /**
     * Create mock VisionResponse
     */
    private function createMockVisionResponse(string $description): VisionResponse
    {
        return new VisionResponse(
            description: $description,
            model: 'gpt-4o',
            usage: new UsageStatistics(
                promptTokens: 100,
                completionTokens: 50,
                totalTokens: 150
            ),
            provider: 'openai',
        );
    }
}

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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mutation-killing tests for VisionService.
 */
#[AllowMockObjectsWithoutExpectations]
#[CoversClass(VisionService::class)]
class VisionServiceMutationTest extends AbstractUnitTestCase
{
    private function createMockVisionResponse(string $description): VisionResponse
    {
        return new VisionResponse(
            description: $description,
            model: 'gpt-4-vision-preview',
            usage: new UsageStatistics(100, 50, 150),
            provider: 'openai',
        );
    }

    #[Test]
    public function generateAltTextSetsDefaultMaxTokensOf100WhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->anything(),
                $this->callback(fn(VisionOptions $opts) => $opts->getMaxTokens() === 100)
            )
            ->willReturn($this->createMockVisionResponse('Alt text'));

        $service = new VisionService($llmManagerMock);
        $service->generateAltText('https://example.com/image.jpg');
    }

    #[Test]
    public function generateAltTextPreservesUserProvidedMaxTokens(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->anything(),
                $this->callback(fn(VisionOptions $opts) => $opts->getMaxTokens() === 200)
            )
            ->willReturn($this->createMockVisionResponse('Alt text'));

        $service = new VisionService($llmManagerMock);
        $options = new VisionOptions(maxTokens: 200);
        $service->generateAltText('https://example.com/image.jpg', $options);
    }

    #[Test]
    public function generateAltTextSetsDefaultTemperatureOf05WhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->anything(),
                $this->callback(fn(VisionOptions $opts) => $opts->getTemperature() === 0.5)
            )
            ->willReturn($this->createMockVisionResponse('Alt text'));

        $service = new VisionService($llmManagerMock);
        $service->generateAltText('https://example.com/image.jpg');
    }

    #[Test]
    public function generateAltTextPreservesUserProvidedTemperature(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->anything(),
                $this->callback(fn(VisionOptions $opts) => $opts->getTemperature() === 0.8)
            )
            ->willReturn($this->createMockVisionResponse('Alt text'));

        $service = new VisionService($llmManagerMock);
        $options = new VisionOptions(temperature: 0.8);
        $service->generateAltText('https://example.com/image.jpg', $options);
    }

    #[Test]
    public function generateTitleSetsDefaultMaxTokensOf50WhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->anything(),
                $this->callback(fn(VisionOptions $opts) => $opts->getMaxTokens() === 50)
            )
            ->willReturn($this->createMockVisionResponse('Title'));

        $service = new VisionService($llmManagerMock);
        $service->generateTitle('https://example.com/image.jpg');
    }

    #[Test]
    public function generateTitlePreservesUserProvidedMaxTokens(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->anything(),
                $this->callback(fn(VisionOptions $opts) => $opts->getMaxTokens() === 75)
            )
            ->willReturn($this->createMockVisionResponse('Title'));

        $service = new VisionService($llmManagerMock);
        $options = new VisionOptions(maxTokens: 75);
        $service->generateTitle('https://example.com/image.jpg', $options);
    }

    #[Test]
    public function generateTitleSetsDefaultTemperatureOf07WhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->anything(),
                $this->callback(fn(VisionOptions $opts) => $opts->getTemperature() === 0.7)
            )
            ->willReturn($this->createMockVisionResponse('Title'));

        $service = new VisionService($llmManagerMock);
        $service->generateTitle('https://example.com/image.jpg');
    }

    #[Test]
    public function generateDescriptionSetsDefaultMaxTokensOf500WhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->anything(),
                $this->callback(fn(VisionOptions $opts) => $opts->getMaxTokens() === 500)
            )
            ->willReturn($this->createMockVisionResponse('Description'));

        $service = new VisionService($llmManagerMock);
        $service->generateDescription('https://example.com/image.jpg');
    }

    #[Test]
    public function generateDescriptionSetsDefaultTemperatureOf07WhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->anything(),
                $this->callback(fn(VisionOptions $opts) => $opts->getTemperature() === 0.7)
            )
            ->willReturn($this->createMockVisionResponse('Description'));

        $service = new VisionService($llmManagerMock);
        $service->generateDescription('https://example.com/image.jpg');
    }

    #[Test]
    public function generateAltTextProcessesBatchOfImages(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->exactly(3))
            ->method('vision')
            ->willReturnOnConsecutiveCalls(
                $this->createMockVisionResponse('Alt 1'),
                $this->createMockVisionResponse('Alt 2'),
                $this->createMockVisionResponse('Alt 3')
            );

        $service = new VisionService($llmManagerMock);
        $results = $service->generateAltText([
            'https://example.com/image1.jpg',
            'https://example.com/image2.jpg',
            'https://example.com/image3.jpg',
        ]);

        $this->assertIsArray($results);
        $this->assertCount(3, $results);
        $this->assertEquals('Alt 1', $results[0]);
        $this->assertEquals('Alt 2', $results[1]);
        $this->assertEquals('Alt 3', $results[2]);
    }

    #[Test]
    public function generateTitleProcessesBatchOfImages(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->exactly(2))
            ->method('vision')
            ->willReturnOnConsecutiveCalls(
                $this->createMockVisionResponse('Title 1'),
                $this->createMockVisionResponse('Title 2')
            );

        $service = new VisionService($llmManagerMock);
        $results = $service->generateTitle([
            'https://example.com/image1.jpg',
            'https://example.com/image2.jpg',
        ]);

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
    }

    #[Test]
    public function generateDescriptionProcessesBatchOfImages(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->exactly(2))
            ->method('vision')
            ->willReturnOnConsecutiveCalls(
                $this->createMockVisionResponse('Desc 1'),
                $this->createMockVisionResponse('Desc 2')
            );

        $service = new VisionService($llmManagerMock);
        $results = $service->generateDescription([
            'https://example.com/image1.jpg',
            'https://example.com/image2.jpg',
        ]);

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
    }

    #[Test]
    public function analyzeImageUsesCustomPrompt(): void
    {
        $customPrompt = 'Count the number of cats in this image';

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->callback(function (array $content) use ($customPrompt) {
                    return $content[0]['text'] === $customPrompt;
                }),
                $this->anything()
            )
            ->willReturn($this->createMockVisionResponse('3 cats'));

        $service = new VisionService($llmManagerMock);
        $result = $service->analyzeImage('https://example.com/cats.jpg', $customPrompt);

        $this->assertEquals('3 cats', $result);
    }

    #[Test]
    public function analyzeImageProcessesBatchWithCustomPrompt(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->exactly(2))
            ->method('vision')
            ->willReturnOnConsecutiveCalls(
                $this->createMockVisionResponse('Result 1'),
                $this->createMockVisionResponse('Result 2')
            );

        $service = new VisionService($llmManagerMock);
        $results = $service->analyzeImage(
            ['https://example.com/1.jpg', 'https://example.com/2.jpg'],
            'Describe this'
        );

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
    }

    #[Test]
    public function analyzeImageFullReturnsVisionResponse(): void
    {
        $expectedResponse = $this->createMockVisionResponse('Full analysis');

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->willReturn($expectedResponse);

        $service = new VisionService($llmManagerMock);
        $result = $service->analyzeImageFull('https://example.com/image.jpg', 'Analyze this');

        $this->assertInstanceOf(VisionResponse::class, $result);
        $this->assertEquals('Full analysis', $result->description);
    }

    #[Test]
    public function analyzeImageFullBuildsCorrectContent(): void
    {
        $imageUrl = 'https://example.com/test.jpg';
        $prompt = 'Test prompt';

        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->callback(function (array $content) use ($imageUrl, $prompt) {
                    return $content[0]['type'] === 'text'
                        && $content[0]['text'] === $prompt
                        && $content[1]['type'] === 'image_url'
                        && $content[1]['image_url']['url'] === $imageUrl;
                }),
                $this->anything()
            )
            ->willReturn($this->createMockVisionResponse('Result'));

        $service = new VisionService($llmManagerMock);
        $service->analyzeImageFull($imageUrl, $prompt);
    }

    #[Test]
    public function analyzeImageFullThrowsExceptionForInvalidUrl(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $service = new VisionService($llmManagerMock);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid image URL or base64 data URI');

        $service->analyzeImageFull('not-a-valid-url', 'Analyze this');
    }

    #[Test]
    public function analyzeImageFullAcceptsValidHttpUrl(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->method('vision')
            ->willReturn($this->createMockVisionResponse('Result'));

        $service = new VisionService($llmManagerMock);

        // Should not throw
        $result = $service->analyzeImageFull('http://example.com/image.jpg', 'Test');

        $this->assertInstanceOf(VisionResponse::class, $result);
    }

    #[Test]
    public function analyzeImageFullAcceptsValidHttpsUrl(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->method('vision')
            ->willReturn($this->createMockVisionResponse('Result'));

        $service = new VisionService($llmManagerMock);

        // Should not throw
        $result = $service->analyzeImageFull('https://example.com/image.jpg', 'Test');

        $this->assertInstanceOf(VisionResponse::class, $result);
    }

    #[Test]
    #[DataProvider('validBase64DataUriProvider')]
    public function analyzeImageFullAcceptsValidBase64DataUri(string $dataUri): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->method('vision')
            ->willReturn($this->createMockVisionResponse('Result'));

        $service = new VisionService($llmManagerMock);

        // Should not throw
        $result = $service->analyzeImageFull($dataUri, 'Test');

        $this->assertInstanceOf(VisionResponse::class, $result);
    }

    public static function validBase64DataUriProvider(): array
    {
        return [
            'png' => ['data:image/png;base64,iVBORw0KGgoAAAANSUhEUg=='],
            'jpeg' => ['data:image/jpeg;base64,/9j/4AAQSkZJRg=='],
            'jpg' => ['data:image/jpg;base64,/9j/4AAQSkZJRg=='],
            'gif' => ['data:image/gif;base64,R0lGODlhAQABAIAA'],
            'webp' => ['data:image/webp;base64,UklGRhIAAABXRUJQ'],
        ];
    }

    #[Test]
    public function analyzeImageFullUsesDetailLevelFromOptions(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->callback(function (array $content) {
                    return $content[1]['image_url']['detail'] === 'high';
                }),
                $this->anything()
            )
            ->willReturn($this->createMockVisionResponse('Result'));

        $service = new VisionService($llmManagerMock);
        $options = new VisionOptions(detailLevel: 'high');
        $service->analyzeImageFull('https://example.com/image.jpg', 'Test', $options);
    }

    #[Test]
    public function analyzeImageFullDefaultsDetailToAuto(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->with(
                $this->callback(function (array $content) {
                    return $content[1]['image_url']['detail'] === 'auto';
                }),
                $this->anything()
            )
            ->willReturn($this->createMockVisionResponse('Result'));

        $service = new VisionService($llmManagerMock);
        $service->analyzeImageFull('https://example.com/image.jpg', 'Test');
    }

    #[Test]
    public function generateAltTextCreatesDefaultOptionsWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->willReturn($this->createMockVisionResponse('Alt text'));

        $service = new VisionService($llmManagerMock);

        // Pass null explicitly
        $result = $service->generateAltText('https://example.com/image.jpg', null);

        $this->assertEquals('Alt text', $result);
    }

    #[Test]
    public function generateTitleCreatesDefaultOptionsWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->willReturn($this->createMockVisionResponse('Title'));

        $service = new VisionService($llmManagerMock);

        // Pass null explicitly
        $result = $service->generateTitle('https://example.com/image.jpg', null);

        $this->assertEquals('Title', $result);
    }

    #[Test]
    public function generateDescriptionCreatesDefaultOptionsWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->willReturn($this->createMockVisionResponse('Description'));

        $service = new VisionService($llmManagerMock);

        // Pass null explicitly
        $result = $service->generateDescription('https://example.com/image.jpg', null);

        $this->assertEquals('Description', $result);
    }

    #[Test]
    public function analyzeImageCreatesDefaultOptionsWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->willReturn($this->createMockVisionResponse('Analysis'));

        $service = new VisionService($llmManagerMock);

        // Pass null explicitly
        $result = $service->analyzeImage('https://example.com/image.jpg', 'Prompt', null);

        $this->assertEquals('Analysis', $result);
    }

    #[Test]
    public function analyzeImageFullCreatesDefaultOptionsWhenNull(): void
    {
        $llmManagerMock = $this->createMock(LlmServiceManagerInterface::class);
        $llmManagerMock
            ->expects($this->once())
            ->method('vision')
            ->willReturn($this->createMockVisionResponse('Analysis'));

        $service = new VisionService($llmManagerMock);

        // Pass null explicitly
        $result = $service->analyzeImageFull('https://example.com/image.jpg', 'Prompt', null);

        $this->assertInstanceOf(VisionResponse::class, $result);
    }
}

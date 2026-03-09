<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Form\Element;

use Netresearch\NrLlm\Form\Element\ModelIdElement;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * @covers \Netresearch\NrLlm\Form\Element\ModelIdElement
 */
final class ModelIdElementTest extends AbstractUnitTestCase
{
    private ModelIdElement $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $uri = self::createStub(UriInterface::class);
        $uri->method('__toString')->willReturn('/test-ajax-url');

        $uriBuilder = self::createStub(BackendUriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn($uri);

        $this->subject = new ModelIdElement($uriBuilder);
    }

    public function testRenderReturnsHtmlWithInputField(): void
    {
        $this->subject->setData([
            'parameterArray' => [
                'itemFormElValue' => 'gpt-5.2',
                'itemFormElName' => 'data[tx_nrllm_model][1][model_id]',
                'fieldConf' => [
                    'config' => [
                        'placeholder' => 'Select a model…',
                        'max' => 200,
                    ],
                ],
            ],
            'tableName' => 'tx_nrllm_model',
            'databaseRow' => [
                'provider_uid' => 42,
            ],
        ]);

        $result = $this->subject->render();

        self::assertArrayHasKey('html', $result);

        $html = $result['html'];
        self::assertIsString($html);
        self::assertStringContainsString('<input type="text"', $html);
        self::assertStringContainsString('value="gpt-5.2"', $html);
        self::assertStringContainsString('name="data[tx_nrllm_model][1][model_id]"', $html);
        self::assertStringContainsString('placeholder="Select a model…"', $html);
        self::assertStringContainsString('maxlength="200"', $html);
        self::assertStringContainsString('<button type="button"', $html);
        self::assertStringContainsString('Fetch Models</button>', $html);
        self::assertStringContainsString('data-fetch-url="/test-ajax-url"', $html);
    }

    public function testRenderWithEmptyData(): void
    {
        $this->subject->setData([]);

        $result = $this->subject->render();

        self::assertArrayHasKey('html', $result);

        $html = $result['html'];
        self::assertIsString($html);
        // Should render with defaults - empty value, default placeholder
        self::assertStringContainsString('<input type="text"', $html);
        self::assertStringContainsString('value=""', $html);
        self::assertStringContainsString('maxlength="150"', $html);
        self::assertStringContainsString('placeholder="e.g., gpt-5.3-chat-latest, claude-sonnet-4-6"', $html);
        self::assertStringContainsString('data-provider-uid="0"', $html);
    }

    public function testRenderIncludesJavaScriptModule(): void
    {
        $this->subject->setData([]);

        $result = $this->subject->render();

        self::assertArrayHasKey('javaScriptModules', $result);
        $modules = $result['javaScriptModules'];
        self::assertIsArray($modules);
        self::assertCount(1, $modules);
        self::assertInstanceOf(JavaScriptModuleInstruction::class, $modules[0]);
        self::assertSame('@netresearch/nr-llm/Backend/ModelIdField.js', $modules[0]->getName());
    }

    public function testRenderWithProviderUid(): void
    {
        $this->subject->setData([
            'parameterArray' => [
                'itemFormElValue' => '',
                'itemFormElName' => 'data[tx_nrllm_model][1][model_id]',
            ],
            'databaseRow' => [
                'provider_uid' => 99,
            ],
        ]);

        $result = $this->subject->render();

        $html = $result['html'];
        self::assertIsString($html);
        self::assertStringContainsString('data-provider-uid="99"', $html);
    }

    public function testRenderWithProviderUidAsArray(): void
    {
        $this->subject->setData([
            'parameterArray' => [
                'itemFormElValue' => '',
                'itemFormElName' => 'data[tx_nrllm_model][1][model_id]',
            ],
            'databaseRow' => [
                'provider_uid' => [7],
            ],
        ]);

        $result = $this->subject->render();

        $html = $result['html'];
        self::assertIsString($html);
        self::assertStringContainsString('data-provider-uid="7"', $html);
    }

    public function testRenderContainsTableNameAttribute(): void
    {
        $this->subject->setData([
            'tableName' => 'tx_nrllm_model',
            'parameterArray' => [],
        ]);

        $result = $this->subject->render();

        $html = $result['html'];
        self::assertIsString($html);
        self::assertStringContainsString('data-table="tx_nrllm_model"', $html);
    }

    public function testRenderContainsStatusDiv(): void
    {
        $this->subject->setData([]);

        $result = $this->subject->render();

        $html = $result['html'];
        self::assertIsString($html);
        self::assertStringContainsString('js-model-status', $html);
    }
}

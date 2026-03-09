<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Form\FieldWizard;

use Netresearch\NrLlm\Form\FieldWizard\ModelConstraintsWizard;
use Netresearch\NrLlm\Tests\Unit\AbstractUnitTestCase;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * @covers \Netresearch\NrLlm\Form\FieldWizard\ModelConstraintsWizard
 */
final class ModelConstraintsWizardTest extends AbstractUnitTestCase
{
    private ModelConstraintsWizard $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $uri = self::createStub(UriInterface::class);
        $uri->method('__toString')->willReturn('/test-constraints-url');

        $uriBuilder = self::createStub(BackendUriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn($uri);

        $this->subject = new ModelConstraintsWizard($uriBuilder);
    }

    public function testRenderReturnsHtmlWithConstraintsDiv(): void
    {
        $result = $this->subject->render();

        self::assertArrayHasKey('html', $result);

        $html = $result['html'];
        self::assertIsString($html);
        self::assertStringContainsString('js-model-constraints-config', $html);
        self::assertStringContainsString('data-constraints-url="/test-constraints-url"', $html);
        self::assertStringContainsString('style="display:none;"', $html);
    }

    public function testRenderIncludesJavaScriptModule(): void
    {
        $result = $this->subject->render();

        self::assertArrayHasKey('javaScriptModules', $result);
        $modules = $result['javaScriptModules'];
        self::assertIsArray($modules);
        self::assertCount(1, $modules);
        self::assertInstanceOf(JavaScriptModuleInstruction::class, $modules[0]);
        self::assertSame(
            '@netresearch/nr-llm/Backend/ConfigurationConstraints.js',
            $modules[0]->getName(),
        );
    }

    public function testRenderReturnsInitializedResultArrayStructure(): void
    {
        $result = $this->subject->render();

        self::assertArrayHasKey('html', $result);
        self::assertArrayHasKey('javaScriptModules', $result);
        self::assertArrayHasKey('additionalHiddenFields', $result);
        self::assertArrayHasKey('stylesheetFiles', $result);
        self::assertArrayHasKey('inlineData', $result);
    }

    public function testRenderEscapesConstraintsUrl(): void
    {
        $uri = self::createStub(UriInterface::class);
        $uri->method('__toString')->willReturn('/url?foo=1&bar=2');

        $uriBuilder = self::createStub(BackendUriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn($uri);

        $subject = new ModelConstraintsWizard($uriBuilder);
        $result = $subject->render();

        $html = $result['html'];
        self::assertIsString($html);
        // & should be escaped to &amp; in HTML attribute
        self::assertStringContainsString('data-constraints-url="/url?foo=1&amp;bar=2"', $html);
    }
}

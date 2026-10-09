<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\FormEngineUrlBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Http\Uri;

/**
 * Unit tests for FormEngineUrlBuilder.
 */
#[CoversClass(FormEngineUrlBuilder::class)]
final class FormEngineUrlBuilderTest extends TestCase
{
    /**
     * Recorded buildUriFromRoute() invocations: [route name, parameters].
     *
     * @var list<array{string, array<int|string, mixed>}>
     */
    private array $routeCalls = [];

    private FormEngineUrlBuilder $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $uriBuilder = $this->createMock(BackendUriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            /** @param array<int|string, mixed> $parameters */
            function (string $name, array $parameters = []): Uri {
                $this->routeCalls[] = [$name, $parameters];

                return new Uri(
                    $name === 'record_edit' ? '/typo3/record/edit' : '/typo3/module/' . $name,
                );
            },
        );

        $this->subject = new FormEngineUrlBuilder($uriBuilder);
    }

    #[Test]
    public function buildEditUrlTargetsRecordEditWithEditCommandAndReturnUrl(): void
    {
        $url = $this->subject->buildEditUrl('tx_nrllm_promptsnippet', 42, 'nrllm_snippets');

        self::assertSame('/typo3/record/edit', $url);
        self::assertSame(
            [
                ['nrllm_snippets', []],
                [
                    'record_edit',
                    [
                        'edit' => ['tx_nrllm_promptsnippet' => [42 => 'edit']],
                        'returnUrl' => '/typo3/module/nrllm_snippets',
                    ],
                ],
            ],
            $this->routeCalls,
        );
    }

    #[Test]
    public function buildNewUrlTargetsRecordEditWithNewCommandAndReturnUrl(): void
    {
        $url = $this->subject->buildNewUrl('tx_nrllm_promptsnippet', 'nrllm_snippets');

        self::assertSame('/typo3/record/edit', $url);
        self::assertSame(
            [
                ['nrllm_snippets', []],
                [
                    'record_edit',
                    [
                        'edit' => ['tx_nrllm_promptsnippet' => [0 => 'new']],
                        'returnUrl' => '/typo3/module/nrllm_snippets',
                    ],
                ],
            ],
            $this->routeCalls,
        );
    }

    /**
     * A new backend-authored skill is created inside its source (ADR-214
     * item 3): the source travels as FormEngine's preset value.
     */
    #[Test]
    public function buildNewUrlWithDefaultsPresetsTheFieldValues(): void
    {
        $url = $this->subject->buildNewUrlWithDefaults('tx_nrllm_skill', ['source' => 7], 'nrllm_skills');

        self::assertSame('/typo3/record/edit', $url);
        self::assertSame(
            [
                ['nrllm_skills', []],
                [
                    'record_edit',
                    [
                        'edit' => ['tx_nrllm_skill' => [0 => 'new']],
                        'returnUrl' => '/typo3/module/nrllm_skills',
                        'defVals' => ['tx_nrllm_skill' => ['source' => 7]],
                    ],
                ],
            ],
            $this->routeCalls,
        );
    }
}

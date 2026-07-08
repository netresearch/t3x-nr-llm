<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin;

use Netresearch\NrLlm\Service\Tool\Builtin\RendersTypoScriptTreeTrait;
use Netresearch\NrLlm\Tests\Unit\Service\Tool\Builtin\Fixtures\TypoScriptTreeRendererFixture;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared TypoScript-tree rendering (ADR-042): dotted-path
 * drill-down through `key.` subtrees, secret redaction by key, and the hard
 * output cap.
 */
#[CoversTrait(RendersTypoScriptTreeTrait::class)]
final class RendersTypoScriptTreeTraitTest extends TestCase
{
    private TypoScriptTreeRendererFixture $renderer;

    /** @var array<string, mixed> */
    private array $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new TypoScriptTreeRendererFixture();
        $this->tree     = [
            'config.' => [
                'no_cache'  => '0',
                'language.' => ['default' => 'en'],
            ],
            'plugin.' => [
                'tx_demo.' => [
                    'settings.' => [
                        'apiKey'   => 'super-secret-value',
                        'endpoint' => 'https://api.example.org',
                    ],
                ],
            ],
            'page' => 'PAGE',
        ];
    }

    #[Test]
    public function drillPathResolvesValueAndSubtree(): void
    {
        [$value, $subtree] = $this->renderer->drill($this->tree, 'config.no_cache');
        self::assertSame('0', $value);
        self::assertNull($subtree);

        [$value, $subtree] = $this->renderer->drill($this->tree, 'config.language');
        self::assertNull($value);
        self::assertSame(['default' => 'en'], $subtree);

        [$value, $subtree] = $this->renderer->drill($this->tree, 'nope.nothing');
        self::assertNull($value);
        self::assertNull($subtree);
    }

    #[Test]
    public function renderTreeIndentsAndRedactsSecrets(): void
    {
        $lines = $this->renderer->render($this->tree);
        $text  = implode("\n", $lines);

        self::assertStringContainsString('page = PAGE', $text);
        self::assertStringContainsString('endpoint = https://api.example.org', $text);
        // The apiKey VALUE never appears; the redaction marker does.
        self::assertStringNotContainsString('super-secret-value', $text);
        self::assertStringContainsString('apiKey = [redacted]', $text);
    }

    #[Test]
    public function topLevelListingMarksBranchesWithChildCounts(): void
    {
        $lines = $this->renderer->topLevel($this->tree);

        self::assertContains('config (+2 children)', $lines);
        self::assertContains('plugin (+1 children)', $lines);
        self::assertContains('page = PAGE', $lines);
    }

    #[Test]
    public function outputIsCappedWithExplicitMarker(): void
    {
        $huge = [];
        for ($i = 0; $i < 400; ++$i) {
            $huge['key' . $i] = 'value' . $i;
        }

        $lines = $this->renderer->render($huge);

        self::assertLessThanOrEqual(301, count($lines));
        self::assertStringContainsString('[output truncated at 300 lines]', (string)end($lines));
    }

    #[Test]
    public function redactionMatchesCredentialKeysOnly(): void
    {
        self::assertSame('[redacted]', $this->renderer->redact('apiKey', 'x'));
        self::assertSame('[redacted]', $this->renderer->redact('password', 'x'));
        self::assertSame('[redacted]', $this->renderer->redact('authorization', 'x'));
        self::assertSame('kept', $this->renderer->redact('title', 'kept'));
        self::assertSame('kept', $this->renderer->redact('monkeys', 'kept'));
    }
}

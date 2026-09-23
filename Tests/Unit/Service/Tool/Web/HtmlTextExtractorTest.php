<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Web;

use Netresearch\NrLlm\Service\Tool\Web\HtmlTextExtractor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlTextExtractor::class)]
final class HtmlTextExtractorTest extends TestCase
{
    #[Test]
    public function keepsTitleHeadingsTextAndLinksAndDropsTheChrome(): void
    {
        $html = <<<'HTML'
            <!DOCTYPE html><html><head><title> The  Title </title><style>.x{}</style></head>
            <body>
              <header>Logo</header><nav><a href="/">Home</a></nav>
              <h2>Section</h2><p>Body with <a href="sub/page?x=1">a link</a> and <a href="mailto:a@b.c">mail</a>.</p>
              <table><tr><th>Name</th><td>Value</td></tr></table>
              <img src="x.png" alt="A chart"><script>var s = 'hidden';</script><noscript>no js</noscript>
              <form><input value="q"><button>Go</button></form>
              <aside>Related</aside><footer>Imprint</footer>
            </body></html>
            HTML;

        $result = (new HtmlTextExtractor())->extract($html, 'https://example.org/dir/index.html');

        self::assertSame('The Title', $result['title']);
        self::assertStringContainsString('## Section', $result['text']);
        self::assertStringContainsString('Body with a link (https://example.org/dir/sub/page?x=1) and mail .', $result['text']);
        self::assertStringContainsString('| Name | Value', $result['text']);
        self::assertStringContainsString('[image: A chart]', $result['text']);
        foreach (['Logo', 'Home', 'hidden', 'no js', 'Go', 'Related', 'Imprint', '.x{}'] as $dropped) {
            self::assertStringNotContainsString($dropped, $result['text'], $dropped);
        }
    }

    #[Test]
    public function readsOnlyTheMainElementWhenThereIsOne(): void
    {
        $result = (new HtmlTextExtractor())->extract(
            '<body><div>Sidebar teaser</div><main><h1>Article</h1><ol><li>first</li><li>second</li></ol></main></body>',
            'https://example.org/',
        );

        self::assertSame("# Article\n- first\n- second", $result['text']);
    }

    #[Test]
    public function keepsTheArticleHeaderAndAPageWrappedInOneForm(): void
    {
        $result = (new HtmlTextExtractor())->extract(
            '<body><form id="aspnetForm"><header>Site logo</header><article><header><h1>Headline</h1></header>'
            . '<p>Story.</p><footer>By Jane</footer></article><input value="x"></form></body>',
            'https://example.org/',
        );

        self::assertSame("# Headline\nStory.\nBy Jane", $result['text']);
    }

    #[Test]
    public function toleratesBrokenMarkup(): void
    {
        $result = (new HtmlTextExtractor())->extract('<p>Unclosed <b>bold<div>next</p></i>', 'https://example.org/');

        self::assertStringContainsString('Unclosed bold', $result['text']);
        self::assertStringContainsString('next', $result['text']);
    }

    #[Test]
    public function deeplyNestedMarkupIsReducedToTextWithoutParsing(): void
    {
        // masterminds/html5 takes about 40 s on this shape (ADR-202).
        $html  = str_repeat('<div>', 40000) . 'deep &amp; text';
        $start = microtime(true);

        $result = (new HtmlTextExtractor())->extract($html, 'https://example.org/');

        self::assertSame('deep & text', $result['text']);
        self::assertLessThan(2.0, microtime(true) - $start);
    }

    #[Test]
    public function onlyTheFirstPartOfAHugePageIsParsed(): void
    {
        $html = '<p>start</p>' . str_repeat('<p>filler</p>', 30000) . '<p>BEYOND-THE-CAP</p>';

        $result = (new HtmlTextExtractor())->extract($html, 'https://example.org/');

        self::assertStringStartsWith('start', $result['text']);
        self::assertStringNotContainsString('BEYOND-THE-CAP', $result['text']);
    }

    #[Test]
    public function unclosedParagraphsAndListItemsAreNotDeepNesting(): void
    {
        $html = '<main><h1>Title</h1>' . str_repeat('<p>para <li>item ', 300) . '</main>';

        $result = (new HtmlTextExtractor())->extract($html, 'https://example.org/');

        self::assertStringStartsWith("# Title\n", $result['text']);
    }
}

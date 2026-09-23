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
    public function toleratesBrokenMarkup(): void
    {
        $result = (new HtmlTextExtractor())->extract('<p>Unclosed <b>bold<div>next</p></i>', 'https://example.org/');

        self::assertStringContainsString('Unclosed bold', $result['text']);
        self::assertStringContainsString('next', $result['text']);
    }
}

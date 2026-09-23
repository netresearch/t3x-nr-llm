<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Web;

use DOMElement;
use DOMNode;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Masterminds\HTML5;
use Throwable;

/**
 * Turns an HTML page into readable plain text for a model (ADR-202).
 *
 * Parsed with the HTML5 parser TYPO3 already ships (masterminds/html5, via
 * typo3/html-sanitizer), so malformed real-world markup is read the way a
 * browser reads it. What is kept:
 *
 * - the document title;
 * - headings, prefixed with `#` by level;
 * - paragraphs, list items (`- `), table cells and other block text;
 * - links as text: `label (https://absolute.url)`, resolved against the page
 *   URL, http(s) targets only.
 *
 * What is dropped: script, style, noscript, template, svg, canvas, iframe,
 * object, embed, form controls, and the page chrome — nav, aside, and header
 * and footer outside `<main>` and `<article>`. When the page has a `<main>` (or, failing that, one `<article>`),
 * only that element is read.
 *
 * Bounded before the parser runs: at most {@see self::MAX_PARSE_BYTES} are
 * parsed, and markup nested deeper than {@see self::MAX_NESTING_DEPTH} is not
 * parsed at all but reduced to its text. PHP 8.4's `Dom\HTMLDocument` (lexbor)
 * is not used: it is a different DOM API from the one PHP 8.2 and 8.3 offer,
 * so it would mean a second renderer for part of the support matrix.
 *
 * No readability library is used: the heuristics of one (Readability.php and
 * its ports) are a sizeable dependency for a TYPO3 extension, and the model
 * copes well with the whole main text once the chrome is gone.
 */
final readonly class HtmlTextExtractor
{
    private const DROPPED_ELEMENTS = [
        'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'object', 'embed',
        'button', 'select', 'input', 'textarea', 'nav', 'aside', 'head',
    ];

    /**
     * Page chrome only outside the content: an article's own `<header>` holds
     * its headline. `form` is not dropped at all — ASP.NET WebForms pages wrap
     * the whole body in one; its controls are dropped one by one above.
     */
    private const CHROME_QUERY = '//header[not(ancestor::main or ancestor::article)] | //footer[not(ancestor::main or ancestor::article)]';

    private const BLOCK_ELEMENTS = [
        'address', 'article', 'blockquote', 'dd', 'details', 'div', 'dl', 'dt', 'figcaption', 'figure',
        'hr', 'li', 'main', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody', 'tfoot',
        'thead', 'tr', 'ul', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    /**
     * The most HTML handed to the parser. masterminds/html5 is quadratic in the
     * nesting depth; with the depth bounded below, 256 KiB of the worst shape
     * (blocks of 256 nested elements) parse in about 0.7 s.
     */
    public const MAX_PARSE_BYTES = 262144;

    /**
     * Deeper markup is not parsed at all; its text is taken with strip_tags(),
     * which is linear. Also the recursion bound of {@see self::render()}.
     */
    public const MAX_NESTING_DEPTH = 200;

    /**
     * Elements whose end tag HTML lets an author leave out, and void elements:
     * neither deepens the tree the way a never-closed `<div>` does, so the
     * pre-scan does not count them.
     */
    private const NOT_NESTING = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr',
        'p', 'li', 'dt', 'dd', 'option', 'optgroup', 'tr', 'td', 'th', 'tbody', 'thead', 'tfoot', 'colgroup',
        'rt', 'rp', 'caption', 'html', 'head', 'body',
    ];

    /**
     * @return array{title: string, text: string}
     */
    public function extract(string $html, string $pageUrl): array
    {
        if (strlen($html) > self::MAX_PARSE_BYTES) {
            $html = mb_strcut($html, 0, self::MAX_PARSE_BYTES, 'UTF-8');
        }

        if ($this->nestingDepth($html) > self::MAX_NESTING_DEPTH) {
            return ['title' => '', 'text' => $this->plainText($html)];
        }

        try {
            $document = (new HTML5(['disable_html_ns' => true]))->loadHTML($html);
        } catch (Throwable) {
            return ['title' => '', 'text' => $this->plainText($html)];
        }

        $xpath = new DOMXPath($document);

        $titleNode = $this->first($xpath, '//title');
        $title     = $titleNode instanceof DOMNode ? $this->collapse($titleNode->textContent) : '';

        foreach ([...array_map(static fn(string $name): string => '//' . $name, self::DROPPED_ELEMENTS), self::CHROME_QUERY] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }

            // Collect first: removing while iterating a live list skips nodes.
            $remove = [];
            foreach ($nodes as $node) {
                if ($node instanceof DOMElement) {
                    $remove[] = $node;
                }
            }

            foreach ($remove as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $root = $this->contentRoot($xpath);
        if (!$root instanceof DOMNode) {
            return ['title' => $title, 'text' => ''];
        }

        $buffer = '';

        try {
            $this->render($root, $pageUrl, $buffer, 0);
        } catch (Throwable) {
            // An Error from deep recursion, or anything else the DOM throws:
            // the page still has text.
            return ['title' => $title, 'text' => $this->plainText($html)];
        }

        $lines = [];
        foreach (preg_split('/\n+/', $buffer) ?: [] as $line) {
            $line = $this->collapse($line);
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
        }

        return ['title' => $title, 'text' => implode("\n", $lines)];
    }

    private function contentRoot(DOMXPath $xpath): ?DOMNode
    {
        foreach (['//main', '//*[@role="main"]'] as $query) {
            $node = $this->first($xpath, $query);
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        $articles = $xpath->query('//article');
        if ($articles !== false && $articles->length === 1 && $articles->item(0) instanceof DOMElement) {
            return $articles->item(0);
        }

        return $this->first($xpath, '//body') ?? $xpath->document->documentElement;
    }

    private function first(DOMXPath $xpath, string $query): ?DOMElement
    {
        $nodes = $xpath->query($query);
        $node  = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function render(DOMNode $node, string $pageUrl, string &$buffer, int $depth): void
    {
        if ($depth > self::MAX_NESTING_DEPTH) {
            $buffer .= ' ' . $node->textContent . ' ';

            return;
        }

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $buffer .= $child->textContent;
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $name = strtolower($child->localName ?? $child->nodeName);

            if ($name === 'br') {
                $buffer .= "\n";
                continue;
            }

            if ($name === 'img') {
                $alt = $this->collapse($child->getAttribute('alt'));
                if ($alt !== '') {
                    $buffer .= ' [image: ' . $alt . '] ';
                }

                continue;
            }

            if ($name === 'a') {
                $label = $this->collapse($child->textContent);
                $href  = $this->absoluteHttpUrl($child->getAttribute('href'), $pageUrl);
                $buffer .= match (true) {
                    $label === '' && $href === null => '',
                    $href === null                  => ' ' . $label . ' ',
                    $label === ''                   => ' (' . $href . ') ',
                    default                         => ' ' . $label . ' (' . $href . ') ',
                };
                continue;
            }

            $isBlock = in_array($name, self::BLOCK_ELEMENTS, true);
            if ($isBlock) {
                $buffer .= "\n";
            }

            if (preg_match('/^h([1-6])$/', $name, $m) === 1) {
                $buffer .= str_repeat('#', (int)$m[1]) . ' ';
            } elseif ($name === 'li') {
                $buffer .= '- ';
            } elseif ($name === 'td' || $name === 'th') {
                $buffer .= ' | ';
            }

            $this->render($child, $pageUrl, $buffer, $depth + 1);

            if ($isBlock) {
                $buffer .= "\n";
            }
        }
    }

    /**
     * The deepest element nesting of the markup, read in one linear pass over
     * its tags — an approximation of the tree the parser would build, which
     * errs deep (an unclosed `<div>` counts forever).
     */
    private function nestingDepth(string $html): int
    {
        // Tag-like text inside scripts is counted too; that errs deep, which
        // only sends a page to the plain-text path.
        if (preg_match_all('#<(/?)([a-zA-Z][a-zA-Z0-9-]*)[^>]*?(/?)>#', $html, $tags, PREG_SET_ORDER) === false) {
            return PHP_INT_MAX;
        }

        $depth = 0;
        $max   = 0;
        foreach ($tags as $tag) {
            $name = strtolower($tag[2]);
            if (in_array($name, self::NOT_NESTING, true) || $tag[3] === '/') {
                continue;
            }

            $depth = $tag[1] === '/' ? max(0, $depth - 1) : $depth + 1;
            $max   = max($max, $depth);
        }

        return $max;
    }

    private function plainText(string $html): string
    {
        // On a backtracking limit preg_replace() returns null; keep the input.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html) ?? $html;

        return $this->collapse(html_entity_decode(strip_tags((string)preg_replace('/</', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function absoluteHttpUrl(string $href, string $pageUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        try {
            $resolved = (string)UriResolver::resolve(new Uri($pageUrl), new Uri($href));
        } catch (Throwable) {
            return null;
        }

        return preg_match('#^https?://#i', $resolved) === 1 ? $resolved : null;
    }

    private function collapse(string $text): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }
}

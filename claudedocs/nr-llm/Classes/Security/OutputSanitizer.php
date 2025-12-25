<?php

declare(strict_types=1);

namespace Netresearch\NrLlm\Security;

use DOMDocument;
use DOMXPath;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Output sanitization for LLM responses.
 *
 * Security Features:
 * - XSS prevention in LLM-generated content
 * - Content validation and filtering
 * - Markdown sanitization
 * - Code block isolation
 * - Link validation
 *
 * Attack Vectors Prevented:
 * - XSS via LLM-generated HTML
 * - Malicious script injection
 * - Phishing links
 * - Data exfiltration via embedded resources
 */
class OutputSanitizer implements SingletonInterface
{
    private HtmlParser $htmlParser;
    private array $config;

    // Allowed HTML tags for safe rendering
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'u', 's', 'code', 'pre',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li',
        'blockquote',
        'a',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ];

    // Allowed attributes for specific tags
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'rel'],
        'code' => ['class'], // For syntax highlighting
        'pre' => ['class'],
    ];

    // Allowed URL schemes
    private const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto'];

    public function __construct(?HtmlParser $htmlParser = null)
    {
        $this->htmlParser = $htmlParser ?? GeneralUtility::makeInstance(HtmlParser::class);

        // Load configuration
        $this->config = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['security']['output'] ?? [];
        $this->config = array_merge([
            'allowHtml' => true,
            'allowMarkdown' => true,
            'allowLinks' => true,
            'validateUrls' => true,
            'maxOutputLength' => 100000,
            'stripScripts' => true,
            'isolateCodeBlocks' => true,
        ], $this->config);
    }

    /**
     * Sanitize LLM response for safe rendering.
     *
     * @param string $response LLM response
     * @param string $format   Response format ('html', 'markdown', 'text')
     *
     * @return string Sanitized response
     */
    public function sanitizeResponse(string $response, string $format = 'text'): string
    {
        // Check maximum length
        if (strlen($response) > $this->config['maxOutputLength']) {
            $response = substr($response, 0, $this->config['maxOutputLength']) . "\n[Output truncated]";
        }

        return match ($format) {
            'html' => $this->sanitizeHtml($response),
            'markdown' => $this->sanitizeMarkdown($response),
            'text' => $this->sanitizeText($response),
            default => $this->sanitizeText($response),
        };
    }

    /**
     * Sanitize HTML content.
     *
     * @param string $html HTML content
     *
     * @return string Sanitized HTML
     */
    public function sanitizeHtml(string $html): string
    {
        if (!$this->config['allowHtml']) {
            return htmlspecialchars($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Remove script tags and event handlers
        if ($this->config['stripScripts']) {
            $html = $this->stripScripts($html);
        }

        // Parse and filter HTML
        $html = $this->filterAllowedTags($html);

        // Validate and sanitize URLs in links
        if ($this->config['validateUrls']) {
            $html = $this->sanitizeLinks($html);
        }

        // Isolate code blocks
        if ($this->config['isolateCodeBlocks']) {
            $html = $this->isolateCodeBlocks($html);
        }

        return $html;
    }

    /**
     * Sanitize Markdown content.
     *
     * @param string $markdown Markdown content
     *
     * @return string Sanitized Markdown
     */
    public function sanitizeMarkdown(string $markdown): string
    {
        if (!$this->config['allowMarkdown']) {
            return htmlspecialchars($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Remove inline HTML if not allowed
        if (!$this->config['allowHtml']) {
            $markdown = preg_replace('/<[^>]+>/', '', $markdown);
        }

        // Sanitize links in markdown
        if ($this->config['validateUrls']) {
            $markdown = $this->sanitizeMarkdownLinks($markdown);
        }

        // Escape potentially dangerous markdown patterns
        $markdown = $this->escapeDangerousMarkdown($markdown);

        return $markdown;
    }

    /**
     * Sanitize plain text content.
     *
     * @param string $text Plain text content
     *
     * @return string Sanitized text
     */
    public function sanitizeText(string $text): string
    {
        // Basic HTML encoding for safe rendering
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Strip script tags and event handlers.
     *
     * @param string $html HTML content
     *
     * @return string HTML without scripts
     */
    private function stripScripts(string $html): string
    {
        // Remove script tags
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);

        // Remove event handlers (onclick, onerror, etc.)
        $html = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);

        // Remove javascript: protocol
        $html = preg_replace('/javascript:/i', '', $html);

        // Remove data: protocol (can be used for XSS)
        $html = preg_replace('/data:text\/html/i', '', $html);

        // Remove vbscript: protocol
        $html = preg_replace('/vbscript:/i', '', $html);

        return $html;
    }

    /**
     * Filter HTML to only allowed tags and attributes.
     *
     * @param string $html HTML content
     *
     * @return string Filtered HTML
     */
    private function filterAllowedTags(string $html): string
    {
        // Use TYPO3's HtmlParser to parse and filter
        $allowedTags = '<' . implode('><', self::ALLOWED_TAGS) . '>';

        // First pass: strip disallowed tags
        $html = strip_tags($html, $allowedTags);

        // Second pass: filter attributes using DOMDocument for proper parsing
        if (empty($html)) {
            return '';
        }

        $dom = new DOMDocument();
        $dom->encoding = 'UTF-8';

        // Suppress warnings for malformed HTML
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $this->filterAttributes($dom);

        // Extract body content
        $html = '';
        if ($dom->documentElement) {
            foreach ($dom->documentElement->childNodes as $node) {
                $html .= $dom->saveHTML($node);
            }
        }

        return $html;
    }

    /**
     * Filter attributes on DOM elements.
     *
     * @param DOMDocument $dom DOM document
     */
    private function filterAttributes(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);

        foreach (self::ALLOWED_ATTRIBUTES as $tag => $allowedAttrs) {
            $elements = $xpath->query("//{$tag}");

            foreach ($elements as $element) {
                $attributesToRemove = [];

                foreach ($element->attributes as $attr) {
                    if (!in_array($attr->name, $allowedAttrs, true)) {
                        $attributesToRemove[] = $attr->name;
                    }
                }

                foreach ($attributesToRemove as $attrName) {
                    $element->removeAttribute($attrName);
                }
            }
        }

        // Remove all attributes from tags not in ALLOWED_ATTRIBUTES
        $tagsWithoutAllowedAttrs = array_diff(self::ALLOWED_TAGS, array_keys(self::ALLOWED_ATTRIBUTES));

        foreach ($tagsWithoutAllowedAttrs as $tag) {
            $elements = $xpath->query("//{$tag}");

            foreach ($elements as $element) {
                $attributesToRemove = [];

                foreach ($element->attributes as $attr) {
                    $attributesToRemove[] = $attr->name;
                }

                foreach ($attributesToRemove as $attrName) {
                    $element->removeAttribute($attrName);
                }
            }
        }
    }

    /**
     * Sanitize links in HTML.
     *
     * @param string $html HTML content
     *
     * @return string HTML with sanitized links
     */
    private function sanitizeLinks(string $html): string
    {
        if (!$this->config['allowLinks']) {
            // Remove all links
            $html = preg_replace('/<a\b[^>]*>(.*?)<\/a>/is', '$1', $html);
            return $html;
        }

        // Validate URLs using DOMDocument
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $links = $dom->getElementsByTagName('a');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');

            if (!$this->isValidUrl($href)) {
                // Remove href or replace with safe placeholder
                $link->removeAttribute('href');
                $link->setAttribute('data-invalid-url', 'true');
            } else {
                // Add security attributes for external links
                if ($this->isExternalUrl($href)) {
                    $link->setAttribute('rel', 'noopener noreferrer nofollow');
                    $link->setAttribute('target', '_blank');
                }
            }
        }

        $html = '';
        if ($dom->documentElement) {
            foreach ($dom->documentElement->childNodes as $node) {
                $html .= $dom->saveHTML($node);
            }
        }

        return $html;
    }

    /**
     * Sanitize links in Markdown.
     *
     * @param string $markdown Markdown content
     *
     * @return string Markdown with sanitized links
     */
    private function sanitizeMarkdownLinks(string $markdown): string
    {
        // Match markdown links: [text](url)
        $markdown = preg_replace_callback(
            '/\[([^\]]+)\]\(([^\)]+)\)/',
            function ($matches) {
                $text = $matches[1];
                $url = $matches[2];

                if (!$this->isValidUrl($url)) {
                    // Remove URL, keep text
                    return $text;
                }

                return "[{$text}]({$url})";
            },
            $markdown,
        );

        return $markdown;
    }

    /**
     * Validate URL.
     *
     * @param string $url URL to validate
     *
     * @return bool True if URL is valid and safe
     */
    private function isValidUrl(string $url): bool
    {
        // Empty URLs are invalid
        if (empty($url)) {
            return false;
        }

        // Check for valid URL scheme
        $parsedUrl = parse_url($url);

        if ($parsedUrl === false || !isset($parsedUrl['scheme'])) {
            return false;
        }

        // Only allow safe schemes
        if (!in_array(strtolower($parsedUrl['scheme']), self::ALLOWED_URL_SCHEMES, true)) {
            return false;
        }

        // Check for suspicious patterns
        if (preg_match('/javascript:|data:|vbscript:/i', $url)) {
            return false;
        }

        return true;
    }

    /**
     * Check if URL is external.
     *
     * @param string $url URL to check
     *
     * @return bool True if URL is external
     */
    private function isExternalUrl(string $url): bool
    {
        $parsedUrl = parse_url($url);

        if (!isset($parsedUrl['host'])) {
            return false;
        }

        $currentHost = GeneralUtility::getIndpEnv('HTTP_HOST');

        return $parsedUrl['host'] !== $currentHost;
    }

    /**
     * Isolate code blocks to prevent execution.
     *
     * @param string $html HTML content
     *
     * @return string HTML with isolated code blocks
     */
    private function isolateCodeBlocks(string $html): string
    {
        // Wrap code/pre blocks in safe container
        $html = preg_replace(
            '/<(code|pre)\b([^>]*)>(.*?)<\/\1>/is',
            '<div class="llm-code-block"><$1$2>$3</$1></div>',
            $html,
        );

        return $html;
    }

    /**
     * Escape dangerous markdown patterns.
     *
     * @param string $markdown Markdown content
     *
     * @return string Escaped markdown
     */
    private function escapeDangerousMarkdown(string $markdown): string
    {
        // Escape inline HTML in markdown (if HTML not allowed)
        if (!$this->config['allowHtml']) {
            $markdown = preg_replace('/<([^>]+)>/', '&lt;$1&gt;', $markdown);
        }

        return $markdown;
    }

    /**
     * Sanitize JSON output (for API responses).
     *
     * @param array $data Data to sanitize
     *
     * @return array Sanitized data
     */
    public function sanitizeJsonOutput(array $data): array
    {
        array_walk_recursive($data, function (&$value): void {
            if (is_string($value)) {
                // Ensure proper UTF-8 encoding
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

                // Remove null bytes
                $value = str_replace("\0", '', $value);
            }
        });

        return $data;
    }
}

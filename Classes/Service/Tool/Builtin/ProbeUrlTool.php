<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\EgressPolicyService;
use Netresearch\NrLlm\Service\Tool\LogExceptionReader;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * The "why does URL X answer 500/400" tool (ADR-044).
 *
 * Performs one GET against the instance's OWN frontend and reports status,
 * key headers, timing and a short tag-stripped body excerpt. On a 5xx the
 * tool automatically correlates: the newest error-level file-log entries
 * from the probe's time window (±{@see self::CORRELATION_WINDOW_SECONDS}s)
 * are appended via the shared {@see LogExceptionReader} — probe and cause
 * in one result.
 *
 * SSRF containment (fail-closed): the target is resolved through the central
 * {@see EgressPolicyService} for this tool's group — only http(s), no userinfo,
 * and the host:port must match one of the instance's own site bases/base
 * variants; relative paths resolve against the first site base. A group without
 * a declared egress policy is denied outright. Redirects are never followed — a
 * 3xx reports its Location instead, so a redirect cannot bounce the probe
 * off-host.
 */
final readonly class ProbeUrlTool implements ToolInterface
{
    use ErrorMessageSanitizerTrait;
    use SafeCastTrait;

    private const TIMEOUT_SECONDS = 15;

    private const BODY_EXCERPT_BYTES = 2048;

    private const CORRELATION_WINDOW_SECONDS = 30;

    private const REPORTED_HEADERS = ['content-type', 'location', 'cache-control', 'x-typo3-cache'];

    /**
     * The '<' of non-inline tags only: a space inserted there keeps adjacent
     * text nodes ("<td>Price</td><td>100</td>") separated after strip_tags
     * without splitting words joined by inline markup ("cyber<b>security</b>").
     */
    private const NON_INLINE_TAG_PATTERN = '/<(?!\/?(?:a|abbr|b|bdi|bdo|cite|code|data|dfn|em|i|kbd|mark|q|s|samp|small|span|strong|sub|sup|time|u|var|wbr)\b)/i';

    public function __construct(
        private EgressPolicyService $egressPolicy,
        private RequestFactory $requestFactory,
        private LogExceptionReader $logReader,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'probe_url',
            'GET a URL of THIS TYPO3 instance and report status, headers, timing and a short body '
            . 'excerpt. On a 5xx the matching exception from the TYPO3 logs is appended automatically. '
            . 'Only the instance\'s own site hosts are allowed; redirects are reported, not followed.',
            [
                'type'       => 'object',
                'properties' => [
                    'url' => [
                        'type'        => 'string',
                        'description' => 'Absolute URL on one of this instance\'s sites, or a path like "/imprint" (resolved against the first site).',
                    ],
                ],
                'required' => ['url'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $input = trim(self::toStr($arguments['url'] ?? ''));
        if ($input === '') {
            return ToolResult::text('Error: "url" is required.');
        }

        $url = $this->egressPolicy->resolveAllowedUrl($this->getGroup(), $input);
        if ($url === null) {
            return ToolResult::text(sprintf(
                'Denied: "%s" is not a URL of this instance. Allowed hosts: %s. '
                . 'Relative paths like "/imprint" are resolved against the first site.',
                $this->displayUrl($input),
                implode(', ', $this->egressPolicy->allowedHosts()) ?: '(no site configured)',
            ));
        }

        $started   = microtime(true);
        $probeTime = time();

        try {
            $response = $this->requestFactory->request($url, 'GET', [
                'timeout'         => self::TIMEOUT_SECONDS,
                'allow_redirects' => false,
                'http_errors'     => false,
            ]);
        } catch (Throwable $e) {
            return ToolResult::text(sprintf(
                'Probe of %s FAILED transport-level after %.0f ms: %s',
                $url,
                (microtime(true) - $started) * 1000,
                $this->sanitizeErrorMessage($e->getMessage()),
            ));
        }

        $elapsedMs = (microtime(true) - $started) * 1000;
        $status    = $response->getStatusCode();

        $lines   = [];
        $lines[] = sprintf('GET %s → %d %s (%.0f ms)', $url, $status, $response->getReasonPhrase(), $elapsedMs);

        foreach (self::REPORTED_HEADERS as $header) {
            if ($response->hasHeader($header)) {
                $lines[] = sprintf('%s: %s', $header, $response->getHeaderLine($header));
            }
        }

        if ($status >= 300 && $status < 400) {
            $lines[] = 'Redirect NOT followed (report only) — probe the Location target separately if it is on this instance.';
        }

        $excerpt = $this->bodyExcerpt((string)$response->getBody());
        if ($excerpt !== '') {
            $lines[] = '';
            $lines[] = 'Body excerpt:';
            $lines[] = $excerpt;
        }

        if ($status >= 500) {
            $lines[] = '';
            $lines[] = $this->correlateLogs($probeTime);
        }

        return ToolResult::text(implode("\n", $lines));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: triggers requests and exposes error internals.
        return true;
    }

    public function getGroup(): string
    {
        return 'system';
    }

    /**
     * Strip any userinfo (user:pass@) before a URL is echoed into tool output,
     * so credentials in a rejected URL never reach the LLM or the logs.
     */
    private function displayUrl(string $url): string
    {
        return preg_replace('#://[^/@\s]*@#', '://', $url) ?? $url;
    }

    private function bodyExcerpt(string $body): string
    {
        // Space before each non-inline tag so adjacent text nodes stay
        // separated after strip_tags; the collapse removes the extra spaces.
        $spaced = (string)preg_replace(self::NON_INLINE_TAG_PATTERN, ' <', $body);
        $text   = trim((string)preg_replace('/\s+/', ' ', strip_tags($spaced)));
        if ($text === '') {
            return '';
        }
        if (strlen($text) > self::BODY_EXCERPT_BYTES) {
            $text = mb_strcut($text, 0, self::BODY_EXCERPT_BYTES, 'UTF-8') . '…';
        }

        return $this->sanitizeErrorMessage($text);
    }

    /**
     * Append the newest error-log entries from the probe's time window so a
     * 5xx report carries its own cause.
     */
    private function correlateLogs(int $probeTime): string
    {
        $entries = $this->logReader->read(
            3,
            null,
            $probeTime - self::CORRELATION_WINDOW_SECONDS,
            $probeTime + self::CORRELATION_WINDOW_SECONDS,
        );

        if ($entries === []) {
            return 'No matching error in the TYPO3 file logs within ±'
                . self::CORRELATION_WINDOW_SECONDS
                . 's — the 500 may originate outside TYPO3 (web server, PHP fatal before logging).';
        }

        $lines = ['Correlated TYPO3 log error(s) from the probe window:'];
        foreach ($entries as $entry) {
            $lines[] = sprintf(
                '- [%s] %s %s: %s',
                gmdate('H:i:s', $entry->timestamp) . ' UTC',
                $entry->level,
                $entry->exceptionClass ?? $entry->component,
                $this->sanitizeErrorMessage(mb_strimwidth($entry->message, 0, 300, '…')),
            );
            if ($entry->frames !== []) {
                $lines[] = sprintf('  at %s:%d', $entry->frames[0]['file'], $entry->frames[0]['line']);
            }
        }
        $lines[] = 'Use get_last_exception for the full stack trace and source context.';

        return implode("\n", $lines);
    }
}

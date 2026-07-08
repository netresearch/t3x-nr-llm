<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\LogExceptionReader;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\ErrorMessageSanitizerTrait;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Psr\Http\Message\UriInterface;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

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
 * SSRF containment (fail-closed): only http(s), and the target host:port
 * must match one of the instance's own site bases/base variants
 * ({@see SiteFinder}); relative paths resolve against the first site base.
 * Redirects are never followed — a 3xx reports its Location instead, so a
 * redirect cannot bounce the probe off-host.
 */
final readonly class ProbeUrlTool implements ToolInterface
{
    use ErrorMessageSanitizerTrait;
    use SafeCastTrait;

    private const TIMEOUT_SECONDS = 15;

    private const BODY_EXCERPT_BYTES = 2048;

    private const CORRELATION_WINDOW_SECONDS = 30;

    private const REPORTED_HEADERS = ['content-type', 'location', 'cache-control', 'x-typo3-cache'];

    public function __construct(
        private SiteFinder $siteFinder,
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

    public function execute(array $arguments): string
    {
        $input = trim(self::toStr($arguments['url'] ?? ''));
        if ($input === '') {
            return 'Error: "url" is required.';
        }

        $url = $this->resolveAllowedUrl($input);
        if ($url === null) {
            return sprintf(
                'Denied: "%s" is not a URL of this instance. Allowed hosts: %s. '
                . 'Relative paths like "/imprint" are resolved against the first site.',
                $this->displayUrl($input),
                implode(', ', $this->allowedHosts()) ?: '(no site configured)',
            );
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
            return sprintf(
                'Probe of %s FAILED transport-level after %.0f ms: %s',
                $url,
                (microtime(true) - $started) * 1000,
                $this->sanitizeErrorMessage($e->getMessage()),
            );
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

        return implode("\n", $lines);
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
     * Resolve the model-supplied input to an absolute URL whose scheme is
     * http(s) and whose host:port matches one of this instance's sites.
     * Null when any gate denies it.
     */
    private function resolveAllowedUrl(string $input): ?string
    {
        if (str_starts_with($input, '/') && !str_starts_with($input, '//')) {
            $base = $this->firstSiteBase();
            if ($base === null) {
                return null;
            }

            return rtrim($base, '/') . $input;
        }

        $parts = parse_url($input);
        if (!is_array($parts)) {
            return null;
        }
        // Reject userinfo (user:pass@host): the URL is echoed back into the tool
        // output, so credentials must never reach the LLM or the logs.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower(self::toStr($parts['scheme'] ?? ''));
        $host   = strtolower(self::toStr($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        // Exact host:port match with scheme-defaulted ports on BOTH sides — a
        // bare-host match would let http://localhost:6379/ (Redis, …) through.
        $port     = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        $hostPort = $host . ':' . $port;
        if (in_array($hostPort, $this->allowedHosts(), true)) {
            return $input;
        }

        return null;
    }

    /**
     * Strip any userinfo (user:pass@) before a URL is echoed into tool output,
     * so credentials in a rejected URL never reach the LLM or the logs.
     */
    private function displayUrl(string $url): string
    {
        return preg_replace('#://[^/@\s]*@#', '://', $url) ?? $url;
    }

    /**
     * host[:port] values of every site base and base variant.
     *
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        $hosts = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach ($this->siteBases($site) as $base) {
                $host = strtolower($base->getHost());
                if ($host === '') {
                    continue;
                }
                // Normalise to an explicit host:port (scheme default 80/443) so
                // the match is exact and cannot be bypassed by a rogue port.
                $scheme  = strtolower($base->getScheme() ?: 'http');
                $port    = $base->getPort() ?? ($scheme === 'https' ? 443 : 80);
                $hosts[] = $host . ':' . $port;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * @return list<UriInterface>
     */
    private function siteBases(Site $site): array
    {
        $bases = [$site->getBase()];

        $variants = $site->getConfiguration()['baseVariants'] ?? null;
        if (is_array($variants)) {
            foreach ($variants as $variant) {
                if (is_array($variant) && is_string($variant['base'] ?? null)) {
                    $bases[] = new Uri($variant['base']);
                }
            }
        }

        return $bases;
    }

    private function firstSiteBase(): ?string
    {
        foreach ($this->siteFinder->getAllSites() as $site) {
            $base = (string)$site->getBase();
            if ($base !== '' && $site->getBase()->getHost() !== '') {
                return $base;
            }
        }

        return null;
    }

    private function bodyExcerpt(string $body): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', strip_tags($body)));
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

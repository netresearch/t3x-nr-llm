<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\RouteNotFoundException;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Map a URL of this instance to the page (and route arguments) that serves
 * it — routing only, no HTTP request is made (ADR-046).
 *
 * The answer to "which page is behind /imprint?" and the first step of
 * "why does URL X misbehave?": site + language + page uid/title/slug, ready
 * for a follow-up with get_page_content or probe_url.
 *
 * Security contract (see {@see ToolInterface} and ADR-042): no allowlist is
 * needed — {@see SiteMatcher} only knows THIS instance's sites, so a foreign
 * host cannot match by construction and is reported neutrally. Fail-closed
 * without a backend user; non-admins must hold PAGE_SHOW on the resolved
 * page (same neutral denial as get_page_content, so page existence cannot
 * be probed).
 */
final readonly class ResolveUrlTool implements ToolInterface
{
    use SafeCastTrait;

    private const NOT_PERMITTED = 'Page not found or not permitted.';

    private const NO_SITE = 'Not a URL of this instance (no site matches).';

    /** Render cap for the route/query argument dump. */
    private const ARGS_WIDTH = 300;

    public function __construct(
        private SiteMatcher $siteMatcher,
        private SiteFinder $siteFinder,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'resolve_url',
            'Resolve a URL of THIS TYPO3 instance to the page that serves it: site, language, page '
            . 'uid/title/slug and route arguments. Routing only — no request is sent. '
            . 'Use probe_url to actually fetch the URL.',
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

        $user = $context->actingBackendUser();
        if ($user === null) {
            return ToolResult::text(self::NOT_PERMITTED);
        }

        $url = $this->absoluteUrl($input);
        if ($url === null) {
            return ToolResult::text(self::NO_SITE);
        }

        try {
            $request     = new ServerRequest($url, 'GET');
            $routeResult = $this->siteMatcher->matchRequest($request);
        } catch (Throwable) {
            return ToolResult::text(self::NO_SITE);
        }

        if (!$routeResult instanceof SiteRouteResult) {
            return ToolResult::text(self::NO_SITE);
        }
        $site = $routeResult->getSite();
        if (!$site instanceof Site) {
            // NullSite: the host/path prefix belongs to no configured site.
            return ToolResult::text(self::NO_SITE);
        }

        try {
            $pageArguments = $site->getRouter()->matchRequest($request, $routeResult);
        } catch (RouteNotFoundException) {
            return ToolResult::text(sprintf(
                'No page route matches "%s" on site "%s" — the slug may not exist, or the page is hidden/not translated.',
                $this->pathOf($url),
                $site->getIdentifier(),
            ));
        } catch (Throwable) {
            return ToolResult::text(self::NO_SITE);
        }

        if (!$pageArguments instanceof PageArguments) {
            return ToolResult::text(self::NO_SITE);
        }

        return ToolResult::text($this->renderResult($site, $routeResult, $pageArguments, $user->isAdmin(), $context));
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Usable by non-admins; execute() self-enforces PAGE_SHOW on the page.
        return false;
    }

    public function getGroup(): string
    {
        return 'structure';
    }

    /**
     * Absolute http(s) URL for the input; a path (with or without leading
     * slash) resolves against the first configured site. Null when nothing
     * sensible can be built.
     */
    private function absoluteUrl(string $input): ?string
    {
        // Schemeless input like "imprint" or "en/imprint" is a path too —
        // "//host/…" (protocol-relative) is deliberately NOT, it falls
        // through to the scheme check below and is rejected there.
        if (!str_contains($input, '://') && !str_starts_with($input, '/')) {
            $input = '/' . $input;
        }

        if (str_starts_with($input, '/') && !str_starts_with($input, '//')) {
            $base = $this->firstSiteBase();
            if ($base === null) {
                return null;
            }

            return rtrim($base, '/') . $input;
        }

        $scheme = strtolower(self::toStr(parse_url($input, PHP_URL_SCHEME) ?: ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $input;
    }

    private function firstSiteBase(): ?string
    {
        $relativeBase = null;
        foreach ($this->siteFinder->getAllSites() as $site) {
            $base = (string)$site->getBase();
            if ($base === '') {
                continue;
            }
            if ($site->getBase()->getHost() !== '') {
                return $base;
            }
            $relativeBase ??= $base;
        }

        // Sites with a relative base (`base: /`) match ANY host in the
        // SiteMatcher, so for this routing-only resolution a placeholder
        // host suffices — no request is ever sent to it.
        if ($relativeBase !== null) {
            return 'http://localhost/' . ltrim($relativeBase, '/');
        }

        return null;
    }

    private function pathOf(string $url): string
    {
        return self::toStr(parse_url($url, PHP_URL_PATH) ?: '/');
    }

    private function renderResult(
        Site $site,
        SiteRouteResult $routeResult,
        PageArguments $pageArguments,
        bool $isAdmin,
        ToolExecutionContext $context,
    ): string {
        $pageUid = $pageArguments->getPageId();

        // Non-admins must hold PAGE_SHOW on the resolved page; a missing page
        // and a denied page are indistinguishable in the reply (fail-closed).
        if (!$isAdmin) {
            $user        = $context->actingBackendUser();
            $permsClause = $user !== null ? self::toStr($user->getPagePermsClause(Permission::PAGE_SHOW)) : '1=0';
            if ($user === null || !is_array(BackendUtility::readPageAccess($pageUid, $permsClause))) {
                return self::NOT_PERMITTED;
            }
        }

        $page = BackendUtility::getRecord('pages', $pageUid, 'uid, title, slug, doktype, hidden');
        if (!is_array($page)) {
            return self::NOT_PERMITTED;
        }

        $language = $routeResult->getLanguage();

        $lines   = [];
        $lines[] = sprintf('Site: %s (base %s)', $site->getIdentifier(), (string)$site->getBase());
        if ($language !== null) {
            $lines[] = sprintf('Language: %d (%s)', $language->getLanguageId(), (string)$language->getLocale());
        }
        $lines[] = sprintf(
            'Page: [%d] %s (doktype %d, slug %s)%s',
            self::toInt($page['uid'] ?? 0),
            self::toStr($page['title'] ?? ''),
            self::toInt($page['doktype'] ?? 0),
            self::toStr($page['slug'] ?? '') !== '' ? self::toStr($page['slug'] ?? '') : '-',
            self::toInt($page['hidden'] ?? 0) === 1 ? ' [hidden]' : '',
        );

        $routeArguments = $pageArguments->getRouteArguments();
        if ($routeArguments !== []) {
            $lines[] = 'Route arguments: ' . $this->renderArguments($routeArguments);
        }
        $queryArguments = $pageArguments->getQueryArguments();
        if ($queryArguments !== []) {
            $lines[] = 'Query arguments: ' . $this->renderArguments($queryArguments);
        }

        $lines[] = 'Use get_page_content(uid) for the content.';

        return implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    private function renderArguments(array $arguments): string
    {
        $json = json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '[unrenderable]';
        }

        return mb_strimwidth($json, 0, self::ARGS_WIDTH, '…');
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use Throwable;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Resolve the frontend TypoScript (setup or constants) effective on a page.
 *
 * Inspired by the typo3-typoscript tool of EXT:typo3_ai_mate
 * (konradmichalik, GPL-2.0-or-later), but resolved in-process via the core
 * v13/v14 TypoScript APIs (rootline → sys_template rows → FrontendTypoScript
 * factory) instead of a CLI subprocess.
 *
 * Security contract (see {@see ToolInterface} and ADR-042): ADMIN-only —
 * TypoScript constants routinely carry API keys and DSNs. On top of that,
 * values under credential-ish keys are redacted for defence in depth, and
 * the output is capped: without a `path` only the top-level keys are listed,
 * with a `path` the subtree renders up to a hard line cap. Resolution
 * failures (no site, no template) return a neutral error string.
 */
final readonly class GetTypoScriptTool implements ToolInterface
{
    use RendersTypoScriptTreeTrait;
    use SafeCastTrait;

    private const TYPE_SETUP = 'setup';

    private const TYPE_CONSTANTS = 'constants';

    public function __construct(
        protected FrontendTypoScriptFactory $typoScriptFactory,
        protected SysTemplateRepository $sysTemplateRepository,
        protected SiteFinder $siteFinder,
        protected CacheManager $cacheManager,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'get_typoscript',
            'Resolve the frontend TypoScript effective on a page. Without "path" only the top-level '
            . 'keys are listed; with a dotted "path" (e.g. "plugin.tx_form") that subtree is rendered. '
            . 'Credential-like values are redacted.',
            [
                'type'       => 'object',
                'properties' => [
                    'pageUid' => [
                        'type'        => 'integer',
                        'description' => 'The page uid to resolve the TypoScript for.',
                    ],
                    'type' => [
                        'type'        => 'string',
                        'enum'        => [self::TYPE_SETUP, self::TYPE_CONSTANTS],
                        'description' => 'Which document to resolve (default "setup").',
                    ],
                    'path' => [
                        'type'        => 'string',
                        'description' => 'Optional dotted path to drill into (e.g. "config" or "plugin.tx_form.settings").',
                    ],
                ],
                'required' => ['pageUid'],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $pageUid = self::toInt($arguments['pageUid'] ?? 0);
        if ($pageUid < 1) {
            return 'Page not found or no TypoScript template.';
        }

        $type = self::toStr($arguments['type'] ?? self::TYPE_SETUP);
        if (!in_array($type, [self::TYPE_SETUP, self::TYPE_CONSTANTS], true)) {
            $type = self::TYPE_SETUP;
        }

        $path = trim(self::toStr($arguments['path'] ?? ''));

        try {
            if ($type === self::TYPE_CONSTANTS) {
                return $this->renderConstants($this->resolveFlatConstants($pageUid), $pageUid, $path);
            }

            return $this->renderSetup($this->resolveSetup($pageUid), $pageUid, $path);
        } catch (Throwable) {
            // Deliberately neutral: rootline/site/template resolution failures
            // must not leak exception internals into the provider egress.
            return 'Page not found or no TypoScript template.';
        }
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only: TypoScript constants routinely carry credentials.
        return true;
    }

    /**
     * The fully resolved setup array (TypoScript AST notation: `key` values
     * plus `key.` subtrees).
     *
     * @return array<array-key, mixed>
     */
    private function resolveSetup(int $pageUid): array
    {
        return $this->resolveFrontendTypoScript($pageUid)->getSetupArray();
    }

    /**
     * The flattened constants (settings) map: `a.b.c` => value.
     *
     * @return array<array-key, mixed>
     */
    private function resolveFlatConstants(int $pageUid): array
    {
        return $this->resolveFrontendTypoScript($pageUid)->getFlatSettings();
    }

    private function resolveFrontendTypoScript(int $pageUid): FrontendTypoScript
    {
        $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageUid)->get();
        $site     = $this->siteFinder->getSiteByPageId($pageUid);
        $request  = (new ServerRequest())->withAttribute('site', $site);

        $sysTemplateRows = $this->sysTemplateRepository->getSysTemplateRowsByRootline($rootline, $request);

        $typoScriptCache = $this->cacheManager->getCache('typoscript');
        if (!$typoScriptCache instanceof PhpFrontend) {
            $typoScriptCache = null;
        }

        $frontendTypoScript = $this->typoScriptFactory->createSettingsAndSetupConditions(
            $site,
            $sysTemplateRows,
            [],
            $typoScriptCache,
        );

        return $this->typoScriptFactory->createSetupConfigOrFullSetup(
            true,
            $frontendTypoScript,
            $site,
            $sysTemplateRows,
            [],
            '0',
            $typoScriptCache,
            null,
        );
    }

    /**
     * @param array<array-key, mixed> $setup
     */
    private function renderSetup(array $setup, int $pageUid, string $path): string
    {
        if ($setup === []) {
            return 'Page not found or no TypoScript template.';
        }

        if ($path === '') {
            $lines = $this->renderTopLevelKeys($setup);
            array_unshift($lines, sprintf(
                'TypoScript setup for page %d — top-level keys (%d). Pass "path" to drill down:',
                $pageUid,
                count($setup),
            ));

            return implode("\n", $lines);
        }

        [$value, $subtree] = $this->drillPath($setup, $path);
        if ($value === null && $subtree === null) {
            return sprintf('No TypoScript at path "%s".', $path);
        }

        $lines = [sprintf('TypoScript setup for page %d at "%s":', $pageUid, $path)];
        if ($value !== null) {
            $lastSegment = substr((string)strrchr('.' . $path, '.'), 1);
            $lines[]     = sprintf('%s = %s', $path, $this->redactSecretValue($lastSegment, $value));
        }
        if ($subtree !== null) {
            $this->renderTree($subtree, $lines, 0);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array-key, mixed> $constants
     */
    private function renderConstants(array $constants, int $pageUid, string $path): string
    {
        if ($constants === []) {
            return 'Page not found or no TypoScript template.';
        }

        $lines = [];
        foreach ($constants as $key => $value) {
            $key   = (string)$key;
            $value = is_scalar($value) ? (string)$value : '';
            if ($path !== '' && $key !== $path && !str_starts_with($key, $path . '.')) {
                continue;
            }
            if (count($lines) >= self::MAX_LINES) {
                $lines[] = sprintf('… [output truncated at %d lines]', self::MAX_LINES);
                break;
            }
            $lastSegment = substr((string)strrchr('.' . $key, '.'), 1);
            $lines[]     = sprintf('%s = %s', $key, $this->redactSecretValue($lastSegment, $value));
        }

        if ($lines === []) {
            return sprintf('No TypoScript constants at path "%s".', $path);
        }

        array_unshift($lines, sprintf(
            'TypoScript constants for page %d%s:',
            $pageUid,
            $path !== '' ? sprintf(' at "%s"', $path) : '',
        ));

        return implode("\n", $lines);
    }

    public function getGroup(): string
    {
        return 'configuration';
    }
}

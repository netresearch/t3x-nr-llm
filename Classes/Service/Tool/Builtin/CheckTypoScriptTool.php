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
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\IncludeTree\IncludeNode\IncludeInterface;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateTreeBuilder;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Traverser\IncludeTreeTraverser;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Visitor\IncludeTreeSyntaxScannerVisitor;
use TYPO3\CMS\Core\TypoScript\Tokenizer\Line\LineInterface;
use TYPO3\CMS\Core\TypoScript\Tokenizer\LosslessTokenizer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

/**
 * Scan the TypoScript effective on a page for syntax errors (ADR-046) — the
 * "prüfe TypoScript auf Fehler" tool.
 *
 * Builds the constants and setup include trees for the page's sys_template
 * chain (rootline → sys_template rows → include tree, same resolution as
 * {@see GetTypoScriptTool}) and runs the core
 * :php:`IncludeTreeSyntaxScannerVisitor` over both — the same scanner the
 * backend's TypoScript module uses to mark broken syntax: invalid lines,
 * excess/missing braces, `@import` statements matching no file.
 *
 * Security contract (see {@see ToolInterface} and ADR-042): ADMIN-only, in
 * line with get_typoscript — and errors are reported as source + line number
 * + error kind only. The offending line's CONTENT is never echoed: a broken
 * constants line may carry an API key.
 */
final readonly class CheckTypoScriptTool implements ToolInterface
{
    use ResolvesActingBackendUserTrait;
    use SafeCastTrait;

    private const NEUTRAL_ERROR = 'Page not found or no TypoScript template.';

    /** Upper bound on reported errors per run. */
    private const MAX_ERRORS = 50;

    private const ERROR_TEXT = [
        'line.invalid'  => 'invalid line syntax',
        'brace.excess'  => 'excess closing "}"',
        'brace.missing' => 'missing closing "}"',
        'import.empty'  => '@import matches no file',
    ];

    public function __construct(
        private SysTemplateRepository $sysTemplateRepository,
        private SysTemplateTreeBuilder $treeBuilder,
        private SiteFinder $siteFinder,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'check_typoscript',
            'Scan the TypoScript effective on a page (constants and setup) for syntax errors: invalid '
            . 'lines, unbalanced braces, @import statements that match no file. Reports source and line '
            . 'number per error.',
            [
                'type'       => 'object',
                'properties' => [
                    'pageUid' => [
                        'type'        => 'integer',
                        'description' => 'The page uid whose TypoScript chain to check.',
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
            return self::NEUTRAL_ERROR;
        }

        try {
            $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageUid)->get();
            $site     = $this->siteFinder->getSiteByPageId($pageUid);
            $request  = (new ServerRequest())->withAttribute('site', $site);

            $sysTemplateRows = $this->sysTemplateRepository->getSysTemplateRowsByRootline($rootline, $request);
            if ($sysTemplateRows === []) {
                return self::NEUTRAL_ERROR;
            }

            $errors = [];
            foreach (['constants', 'setup'] as $type) {
                $root = $this->treeBuilder->getTreeBySysTemplateRowsAndSite(
                    $type,
                    $sysTemplateRows,
                    new LosslessTokenizer(),
                    $site,
                );
                $scanner = new IncludeTreeSyntaxScannerVisitor();
                (new IncludeTreeTraverser())->traverse($root, [$scanner]);
                foreach ($scanner->getErrors() as $error) {
                    $errors[] = $this->renderError($type, $error);
                }
            }
        } catch (Throwable) {
            // Deliberately neutral: rootline/site/template resolution failures
            // must not leak exception internals into the provider egress.
            return self::NEUTRAL_ERROR;
        }

        $errors = array_values(array_unique($errors));
        if ($errors === []) {
            return sprintf(
                'No TypoScript syntax errors on page %d (constants and setup of %d sys_template row%s checked).',
                $pageUid,
                count($sysTemplateRows),
                count($sysTemplateRows) === 1 ? '' : 's',
            );
        }

        $total = count($errors);
        if ($total > self::MAX_ERRORS) {
            $errors   = array_slice($errors, 0, self::MAX_ERRORS);
            $errors[] = sprintf('… %d more errors not shown', $total - self::MAX_ERRORS);
        }

        return sprintf("TypoScript syntax errors on page %d (%d):\n", $pageUid, $total)
            . '- ' . implode("\n- ", $errors);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Admin-only, like get_typoscript: the scanned chain is configuration
        // an editor has no business enumerating.
        return true;
    }

    public function getGroup(): string
    {
        return 'configuration';
    }

    /**
     * One error as "source, line N: kind" — the line CONTENT is never
     * included (constants may carry credentials).
     *
     * @param array{type: string, include: IncludeInterface, line: LineInterface, lineNumber: int} $error
     */
    private function renderError(string $type, array $error): string
    {
        $name = $error['include']->getName();

        return sprintf(
            '[%s] %s, line %d: %s',
            $type,
            $name !== '' ? $name : '(unnamed include)',
            $error['lineNumber'],
            self::ERROR_TEXT[$error['type']] ?? $error['type'],
        );
    }
}

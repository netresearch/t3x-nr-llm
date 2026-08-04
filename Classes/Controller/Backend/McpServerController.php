<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Controller\Backend;

use Netresearch\NrLlm\Domain\ValueObject\McpServerRecord;
use Netresearch\NrLlm\Service\Tool\Mcp\McpImportService;
use Netresearch\NrLlm\Service\Tool\Mcp\McpServerRepository;
use Netresearch\NrLlm\Service\Tool\Mcp\McpToolRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * The MCP server module: what is configured, what was imported, import now (ADR-116).
 *
 * The import is an explicit administrator action and lives only here. It talks
 * to a third party over the network, so it must never happen because a page
 * rendered or because a run needed a tool — {@see McpImportService} is called
 * from this one place and from nothing else.
 *
 * Both actions are admin-gated. The module registration already restricts the
 * list; the AJAX route needs its own check because a backend route bypasses the
 * module's `access` setting (ADR-037), and the import is exactly the kind of
 * outbound action that must not be reachable without it.
 */
#[AsController]
final class McpServerController extends ActionController
{
    use DefensiveLocalizationTrait;
    use RequiresBackendAdminTrait;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly McpServerRepository $servers,
        private readonly McpToolRepository $catalogue,
        private readonly McpImportService $importer,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function listAction(): ResponseInterface
    {
        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-llm/Backend/McpImport.js');
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $servers = [];
        foreach ($this->servers->findAll() as $server) {
            $servers[] = [
                'record' => $server,
                // An undeclared data class is the one configuration mistake that
                // silently costs a server all of its tools, so the view is told
                // about it rather than leaving the operator to infer it from an
                // empty list.
                'usable' => $server->dataClassEnum() !== null,
                'tools'  => $this->catalogue->findAllByServer($server->uid),
            ];
        }

        $moduleTemplate->assign('servers', $servers);

        return $moduleTemplate->renderResponse('Backend/McpServer/List');
    }

    /**
     * Import one server's advertised catalogue (AJAX, admin-gated).
     *
     * Returns the report rather than a bare success flag: a refusal, an import
     * that wrote nothing and an import that skipped half the catalogue are three
     * different outcomes, and an operator who sees only "done" cannot tell them
     * apart.
     */
    public function importAction(ServerRequestInterface $request): ResponseInterface
    {
        if (($deny = $this->denyNonAdmin()) !== null) {
            return $deny;
        }

        $uid    = $this->intFromBody($request->getParsedBody(), 'server');
        $server = $uid > 0 ? $this->servers->findByUid($uid) : null;

        if (!$server instanceof McpServerRecord) {
            return new JsonResponse([
                'success' => false,
                'error'   => $this->localize(
                    'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:error.mcp.unknownServer',
                    'Unknown MCP server',
                ),
            ], 404);
        }

        $report = $this->importer->import($server);

        // A refusal answers 200 with `success: false`, like every other action
        // in this backend: the shared front-end helper reads that shape and
        // shows the reason, while a non-2xx would land in its generic transport
        // error path and lose the message the operator needs. 404 above is the
        // exception because there the request itself was wrong.
        return new JsonResponse([
            'success'     => !$report->refused,
            'error'       => $report->refused ? ($report->skipReasons[0] ?? '') : '',
            'imported'    => $report->imported,
            'skipped'     => $report->skipped,
            'orphaned'    => $report->orphaned,
            'skipReasons' => $report->skipReasons,
        ]);
    }

    private function intFromBody(mixed $body, string $key): int
    {
        if (!is_array($body)) {
            return 0;
        }

        $value = $body[$key] ?? null;

        return is_numeric($value) ? (int)$value : 0;
    }
}

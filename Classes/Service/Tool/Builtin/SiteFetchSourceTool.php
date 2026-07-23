<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Retrieval\AccessContext;
use Netresearch\NrLlm\Service\Retrieval\RetrievalService;
use Netresearch\NrLlm\Service\Retrieval\SourceReference;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;

/**
 * Companion to site_rag_query (ADR-049): resolves one of its source ids
 * back to the full indexed text so the model can read beyond the
 * excerpt before answering.
 *
 * Security contract (see {@see ToolInterface}): the source id is
 * model-chosen — parsed against a strict grammar and re-checked against
 * the backend's public-only filters on fetch, so an id pointing at a
 * meanwhile-restricted document yields "not found" instead of content.
 * Output is capped; fail-closed without a backend user.
 */
final readonly class SiteFetchSourceTool implements ToolInterface
{
    use SafeCastTrait;

    private const MAX_OUTPUT_CHARS = 8000;

    public function __construct(
        private RetrievalService $retrievalService,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'site_fetch_source',
            'Fetch the full indexed text behind a source_id returned by site_rag_query (capped at '
            . '8000 characters). Use it to read a promising source in full before answering.',
            [
                'type' => 'object',
                'properties' => [
                    'source_id' => [
                        'type' => 'string',
                        'description' => 'A source_id exactly as returned by site_rag_query, e.g. "ke_search:42".',
                    ],
                ],
                'required' => ['source_id'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if ($user === null) {
            return ToolResult::text('Not permitted.');
        }

        $reference = SourceReference::parse(trim(self::toStr($arguments['source_id'] ?? '')));
        if ($reference === null) {
            return ToolResult::text('Invalid source_id.');
        }

        $text = $this->retrievalService->fetchSource($reference, AccessContext::forBackendUser($user));
        if ($text === null || trim($text) === '') {
            return ToolResult::text('Source not found or not permitted.');
        }

        if (mb_strlen($text) > self::MAX_OUTPUT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_OUTPUT_CHARS) . '…';
        }

        return ToolResult::text($text);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Indexed text is public website content; the backend-user gate
        // above (fail-closed) is the only narrowing needed.
        return false;
    }

    public function getGroup(): string
    {
        return 'rag';
    }
}

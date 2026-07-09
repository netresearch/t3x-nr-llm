<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Retrieval\AccessContext;
use Netresearch\NrLlm\Service\Retrieval\EvidenceList;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use Netresearch\NrLlm\Service\Retrieval\RetrievalService;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;

/**
 * Site-content RAG retrieval (ADR-049): question in, curated evidence
 * package out — source id, title, URL and a match excerpt per source,
 * retrieved from the best available search index (EXT:solr, ke_search,
 * indexed_search, database fallback) and labelled with the answering
 * backend.
 *
 * Security contract (see {@see ToolInterface}): the arguments are
 * model-chosen and clamped, never trusted. Every backend filters the
 * index public-only — evidence is what the ANONYMOUS website visitor
 * could read, which is why no per-user page narrowing applies: the
 * public website is readable by every backend user by definition.
 * Fail-closed without a backend user, like every builtin.
 */
final readonly class SiteRagQueryTool implements ToolInterface
{
    use ResolvesActingBackendUserTrait;
    use SafeCastTrait;

    private const DEFAULT_SOURCES = 8;

    public function __construct(
        private RetrievalService $retrievalService,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'site_rag_query',
            'Retrieve evidence about the website\'s own PUBLIC content for a question: returns curated '
            . 'sources (source_id, title, URL, excerpt) from the installed search index (Solr, ke_search, '
            . 'indexed_search) or a database fallback. Base statements about site content ONLY on this '
            . 'evidence and cite the source URLs; when the evidence is insufficient, say so instead of '
            . 'guessing. Use site_fetch_source(source_id) to read a source\'s full indexed text.',
            [
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'The question or search phrase (2-200 characters). Plain keywords work best.',
                    ],
                    'site' => [
                        'type' => 'string',
                        'description' => 'Optional: restrict to one site identifier (see get_site_config).',
                    ],
                    'language' => [
                        'type' => 'integer',
                        'description' => 'Optional: language id to search in (default 0).',
                    ],
                    'max_sources' => [
                        'type' => 'integer',
                        'description' => 'Maximum evidence sources (default 8, hard cap 20).',
                    ],
                ],
                'required' => ['question'],
            ],
        );
    }

    public function execute(array $arguments): string
    {
        $user = $this->actingBackendUser();
        if ($user === null) {
            return 'Not permitted.';
        }

        $question = trim(self::toStr($arguments['question'] ?? ''));
        // Re-trim after truncation: cutting at 200 chars can end on
        // whitespace and shrink the trimmed length below the minimum.
        $question = trim(mb_substr($question, 0, RetrievalQuery::MAX_QUERY_LENGTH));
        if (mb_strlen($question) < RetrievalQuery::MIN_QUERY_LENGTH) {
            return 'Question too short (minimum 2 characters).';
        }

        $maxSources = self::toInt($arguments['max_sources'] ?? self::DEFAULT_SOURCES);
        if ($maxSources < 1) {
            $maxSources = self::DEFAULT_SOURCES;
        }
        $maxSources = min($maxSources, RetrievalQuery::MAX_SOURCES);

        $site = trim(self::toStr($arguments['site'] ?? ''));
        $languageId = max(0, self::toInt($arguments['language'] ?? 0));

        $result = $this->retrievalService->search(
            RetrievalQuery::create($question, $maxSources, $site === '' ? null : $site, $languageId),
            AccessContext::forBackendUser($user),
        );

        return $this->format($question, $result);
    }

    public function isEnabledByDefault(): bool
    {
        return true;
    }

    public function requiresAdmin(): bool
    {
        // Evidence is public website content; the backend-user gate above
        // (fail-closed) is the only narrowing needed.
        return false;
    }

    public function getGroup(): string
    {
        return 'rag';
    }

    private function format(string $question, EvidenceList $result): string
    {
        $lines = [];

        if ($result->isEmpty()) {
            $lines[] = sprintf('No evidence found for "%s" (backend: %s).', $question, $result->backend);
        } else {
            $lines[] = sprintf(
                'Evidence for "%s" (backend: %s, %d sources):',
                $question,
                $result->backend,
                count($result->sources),
            );
            foreach ($result->sources as $index => $source) {
                $lines[] = sprintf('%d. %s · %s', $index + 1, $source->sourceId, $source->title);
                if ($source->url !== '') {
                    $lines[] = '   ' . $source->url;
                }
                if ($source->excerpt !== '') {
                    $lines[] = '   ' . $source->excerpt;
                }
            }
            $lines[] = 'Use site_fetch_source(source_id) for a source\'s full indexed text; cite URLs in answers.';
        }

        foreach ($result->notes as $note) {
            $lines[] = 'Note: ' . $note;
        }

        return implode("\n", $lines);
    }
}

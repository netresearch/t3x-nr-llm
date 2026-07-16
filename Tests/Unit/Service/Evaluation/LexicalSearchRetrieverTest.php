<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use Netresearch\NrLlm\Service\Evaluation\LexicalSearchRetriever;
use Netresearch\NrLlm\Service\Retrieval\RetrievalQuery;
use Netresearch\NrLlm\Service\Retrieval\RetrievalService;
use Netresearch\NrLlm\Tests\Unit\Service\Evaluation\Fixture\RecordingSearchBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LexicalSearchRetriever::class)]
final class LexicalSearchRetrieverTest extends TestCase
{
    private function retriever(RecordingSearchBackend $backend): LexicalSearchRetriever
    {
        return new LexicalSearchRetriever(new RetrievalService([$backend]));
    }

    #[Test]
    public function identifierIsStable(): void
    {
        $retriever = $this->retriever(new RecordingSearchBackend());

        self::assertSame('nr_llm.lexical', $retriever->getIdentifier());
    }

    #[Test]
    public function returnsTheEvidenceSourceIdsInRankOrder(): void
    {
        $retriever = $this->retriever(new RecordingSearchBackend(['test:page:1', 'test:page:2']));

        self::assertSame(['test:page:1', 'test:page:2'], $retriever->retrieve('Where is the office?', 3));
    }

    #[Test]
    public function passesTheLimitAsMaxSources(): void
    {
        $backend = new RecordingSearchBackend(['test:page:1']);
        $retriever = $this->retriever($backend);

        $retriever->retrieve('Where is the office?', 3);

        self::assertNotNull($backend->receivedQuery);
        self::assertSame(3, $backend->receivedQuery->maxSources);
    }

    #[Test]
    public function tooShortQuestionYieldsEmptyResultWithoutSearching(): void
    {
        $backend = new RecordingSearchBackend(['test:page:1']);
        $retriever = $this->retriever($backend);

        self::assertSame([], $retriever->retrieve(' a ', 3));
        self::assertNull($backend->receivedQuery);
    }

    #[Test]
    public function overlongQuestionIsTruncatedToTheQueryBound(): void
    {
        $backend = new RecordingSearchBackend(['test:page:1']);
        $retriever = $this->retriever($backend);

        $retriever->retrieve(str_repeat('a', RetrievalQuery::MAX_QUERY_LENGTH + 50), 3);

        self::assertNotNull($backend->receivedQuery);
        self::assertSame(RetrievalQuery::MAX_QUERY_LENGTH, mb_strlen($backend->receivedQuery->query));
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Document;

/**
 * Result of a document analysis (ADR-076).
 */
final readonly class DocumentAnalysisResult
{
    /**
     * @param string $text                   The model's answer. On the native path this is
     *                                       whole-document reasoning from a single call; on
     *                                       the rasterization fallback it is the per-page
     *                                       answers concatenated with `[Page N]` markers.
     * @param string $model                  Model that produced the answer
     * @param string $provider               Provider identifier that produced the answer
     * @param bool   $usedNativeDocumentPath true when the PDF was ingested natively by a
     *                                       document-capable provider; false when it was
     *                                       rasterized and read page-by-page by a vision model
     * @param int    $rasterizedPageCount    Number of pages rasterized on the fallback path;
     *                                       0 on the native path
     */
    public function __construct(
        public string $text,
        public string $model,
        public string $provider,
        public bool $usedNativeDocumentPath,
        public int $rasterizedPageCount = 0,
    ) {}
}

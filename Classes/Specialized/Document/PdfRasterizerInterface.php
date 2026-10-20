<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Document;

use Netresearch\NrLlm\Specialized\Exception\PdfRasterizationException;

/**
 * Rasterizes PDF pages to image blobs so a vision model can read pages of a
 * document whose provider has no native PDF ingestion (ADR-076). Kept behind
 * an interface so the concrete poppler-backed implementation (which shells
 * out to system binaries) can be substituted by a fake in unit tests, and so
 * consumers can detect a missing binary before committing to the fallback.
 *
 * All methods take an absolute local filesystem path (the caller materialises
 * in-memory PDF bytes to a temporary file first).
 */
interface PdfRasterizerInterface
{
    /**
     * @return list<int> 1-based page numbers that carry at least one embedded
     *                   raster image
     *
     * @throws PdfRasterizationException if the image inventory cannot be produced
     */
    public function imagePages(string $absolutePath): array;

    /**
     * @return string PNG bytes of the rasterized page
     *
     * @throws PdfRasterizationException if the page cannot be rendered
     */
    public function renderPage(string $absolutePath, int $page): string;

    /**
     * @return array<int, string> PNG bytes per 1-based page number, ascending,
     *                            covering every page of the document
     *
     * @throws PdfRasterizationException if the document cannot be rendered
     */
    public function renderDocument(string $absolutePath): array;

    /**
     * Whether the rasterizer's system dependencies are present. A false result
     * means rasterization-based processing is impossible on this host.
     */
    public function isAvailable(): bool;
}

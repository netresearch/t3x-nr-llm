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
     * @throws PdfRasterizationException if the image inventory cannot be produced
     *
     * @return list<int> 1-based page numbers that carry at least one embedded
     *                   raster image
     */
    public function imagePages(string $absolutePath): array;

    /**
     * @throws PdfRasterizationException if the page cannot be rendered
     *
     * @return string PNG bytes of the rasterized page
     */
    public function renderPage(string $absolutePath, int $page): string;

    /**
     * @throws PdfRasterizationException if the document cannot be rendered
     *
     * @return array<int, string> PNG bytes per 1-based page number, ascending,
     *                            covering every page of the document
     */
    public function renderDocument(string $absolutePath): array;

    /**
     * Whether the rasterizer's system dependencies are present. A false result
     * means rasterization-based processing is impossible on this host.
     */
    public function isAvailable(): bool;
}

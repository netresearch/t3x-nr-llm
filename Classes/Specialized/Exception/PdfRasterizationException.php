<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Specialized\Exception;

use Throwable;

/**
 * Thrown by a PdfRasterizerInterface implementation when a page cannot be
 * rasterized or the image-page list cannot be produced — typically because
 * the required system binary (poppler's pdftoppm/pdfimages) is missing or a
 * PDF is malformed. The service domain is fixed to 'document' so the
 * constructor keeps the (message, code, previous) shape of the ported
 * renderer's throw sites.
 */
final class PdfRasterizationException extends SpecializedServiceException
{
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, 'document', null, $code, $previous);
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Contract;

interface DocumentCapableInterface
{
    public function supportsDocuments(): bool;

    /**
     * Returns the document formats supported by this provider as Base64 inline content.
     * Example: ['pdf'].
     *
     * @return array<string>
     */
    public function getSupportedDocumentFormats(): array;
}

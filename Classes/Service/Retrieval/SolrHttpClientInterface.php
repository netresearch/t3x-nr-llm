<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Retrieval;

use Psr\Http\Message\ResponseInterface;

/**
 * One-method HTTP boundary for the Solr select API (ADR-049).
 *
 * Exists so SolrSearchBackend can be tested against a plain double:
 * TYPO3's concrete RequestFactory is `readonly` on 14.x but not on
 * 13.4, so neither PHPUnit doubles nor a subclass fixture can target
 * both branches.
 */
interface SolrHttpClientInterface
{
    public function get(string $url): ResponseInterface;
}

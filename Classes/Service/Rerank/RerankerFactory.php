<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Rerank;

use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Selects the reranker implementation from configuration (ADR-075): an
 * empty ``rerankerEndpoint`` keeps candidates in input order
 * ({@see NullReranker}), a configured endpoint enables the cross-encoder
 * sidecar ({@see HttpReranker}). Unreadable configuration fails open to
 * {@see NullReranker} — a broken install never breaks a consumer's
 * retrieval, it only skips reranking.
 */
final readonly class RerankerFactory
{
    /**
     * A cross-encoder on CPU can be slow for a wide candidate pool, so the
     * timeout is configurable (a hard-coded short timeout would silently
     * push consumers into their degradation path on modest hardware).
     */
    private const DEFAULT_TIMEOUT = 30.0;

    public function __construct(
        private RequestFactory $requestFactory,
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function create(): RerankerInterface
    {
        $endpoint = trim($this->readString('rerankerEndpoint'));

        if ($endpoint === '') {
            return new NullReranker();
        }

        return new HttpReranker($this->requestFactory, $endpoint, $this->readTimeout());
    }

    private function readTimeout(): float
    {
        $raw = trim($this->readString('rerankerTimeout'));

        if ($raw !== '' && is_numeric($raw) && (float)$raw > 0.0) {
            return (float)$raw;
        }

        return self::DEFAULT_TIMEOUT;
    }

    private function readString(string $key): string
    {
        try {
            $raw = $this->extensionConfiguration->get('nr_llm', $key);
        } catch (Throwable) {
            return '';
        }

        // Values saved through the install tool arrive as strings, but int-
        // typed template fields (rerankerTimeout, int+) may come back as int
        // when set programmatically.
        if (\is_int($raw) || \is_float($raw)) {
            return (string)$raw;
        }

        return is_string($raw) ? $raw : '';
    }
}

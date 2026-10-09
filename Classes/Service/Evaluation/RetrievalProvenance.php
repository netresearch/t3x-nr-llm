<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

use InvalidArgumentException;

/**
 * Bounded, credential-free revision declarations for a retrieval experiment.
 *
 * Revisions are opaque ASCII labels or content digests, never endpoint URLs,
 * document text or raw configuration. An alias alone does not establish fixed
 * weights. NOT_APPLICABLE is explicit; missing capability means unknown.
 *
 * @api
 */
final readonly class RetrievalProvenance
{
    public const NOT_APPLICABLE = 'not-applicable';

    public const MAX_IDENTITY_BYTES = 190;

    public function __construct(
        public string $corpusRevision,
        public string $modelRevision,
        public string $chunkingIdentity,
        public string $pipelineIdentity,
        public ?string $executionRevision = null,
    ) {
        foreach ([
            $corpusRevision,
            $modelRevision,
            $chunkingIdentity,
            $pipelineIdentity,
            $executionRevision,
        ] as $identity) {
            if ($identity !== null && (strlen($identity) > self::MAX_IDENTITY_BYTES || str_contains($identity, '://') || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:+\/-]*$/D', $identity) !== 1)) {
                throw new InvalidArgumentException(
                    'Retrieval provenance requires bounded opaque ASCII revision labels without URL schemes.',
                    1794000215,
                );
            }
        }

        if ($corpusRevision === self::NOT_APPLICABLE || $pipelineIdentity === self::NOT_APPLICABLE) {
            throw new InvalidArgumentException(
                'The retrieval corpus and pipeline must declare an applicable revision.',
                1794000216,
            );
        }
    }

    /**
     * @return array{corpusRevision: string, modelRevision: string, chunkingIdentity: string, pipelineIdentity: string, executionRevision: ?string}
     */
    public function toArray(): array
    {
        return [
            'corpusRevision' => $this->corpusRevision,
            'modelRevision' => $this->modelRevision,
            'chunkingIdentity' => $this->chunkingIdentity,
            'pipelineIdentity' => $this->pipelineIdentity,
            'executionRevision' => $this->executionRevision,
        ];
    }
}

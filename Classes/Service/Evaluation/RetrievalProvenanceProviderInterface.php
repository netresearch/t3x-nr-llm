<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Service\Evaluation;

/**
 * Optional provenance capability for a registered evaluatable retriever.
 *
 * A declaration identifies the experiment; it does not verify an immutable
 * corpus or remote model. Implementations declare stable, credential-free
 * revision labels. The existing retriever interface stays unchanged (ADR-215).
 *
 * @api
 */
interface RetrievalProvenanceProviderInterface
{
    public function getRetrievalProvenance(): RetrievalProvenance;
}

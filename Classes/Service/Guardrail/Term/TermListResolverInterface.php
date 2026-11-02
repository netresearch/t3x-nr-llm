<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Guardrail\Term;

/**
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
interface TermListResolverInterface
{
    /**
     * The visible term lists naming this configuration, oldest first.
     *
     * @return list<TermListRule>
     */
    public function forConfiguration(int $configurationUid): array;

    /**
     * The backend language of a user, '' when none is stored. For the sentence
     * of a block on a worker, where no backend user is around.
     */
    public function languageOfUser(int $userUid): string;
}

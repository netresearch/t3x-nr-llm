<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Provider\Fallback;

use Closure;
use Generator;
use Netresearch\NrLlm\Domain\DTO\FallbackChain;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Repository\LlmConfigurationRepository;

/**
 * The one candidate loop over a primary configuration's fallback chain
 * (ADR-137).
 *
 * It owns exactly the rules ADR-021 states, and nothing else:
 *
 *  - **Shallow.** Only the primary's own chain is walked; a candidate's chain
 *    is never followed, so recursion and cycles cannot occur.
 *  - **No self-retry.** The primary's identifier is removed from its own chain
 *    ({@see self::chainFor()}); a configuration that lists itself yields no
 *    candidate.
 *  - **A missing identifier is skipped**, not an error — an operator can delete
 *    a configuration another one still names.
 *  - **An inactive configuration is skipped**, for the same reason.
 *
 * What it deliberately does NOT own:
 *
 *  - **Ordering.** The health-aware reorder (ADR-063) applies to the
 *    non-streaming path only; the caller hands in the chain it wants walked.
 *  - **The primary itself.** The middleware's primary has already been
 *    attempted by the pipeline when the chain walk starts, while the streaming
 *    dispatcher opens it as its own first candidate. Whoever needs it prepends
 *    it.
 *  - **Logging.** Skips are reported to the caller, which words them for its
 *    own path.
 *
 * Resolution is lazy: {@see self::resolve()} hits the repository for the entry
 * it is asked for and no further, so a chain entry after the one that served is
 * never looked up.
 *
 * @internal
 */
final readonly class FallbackCandidateResolver
{
    public function __construct(
        private LlmConfigurationRepository $repository,
    ) {}

    /**
     * The chain to walk for this primary: its own identifier removed, so a
     * configuration listing itself does not retry against itself.
     */
    public function chainFor(LlmConfiguration $primary): FallbackChain
    {
        return $primary->getFallbackChainDTO()->without($primary->getIdentifier());
    }

    /**
     * Resolve the chain entry by entry, skipping what cannot be dispatched.
     *
     * The key of each yielded pair is the CHAIN identifier, not the resolved
     * configuration's own — the caller reports the entry it was asked to try.
     *
     * @param Closure(string, FallbackSkipReason): void $onSkip called for every entry that is not dispatchable
     *
     * @return Generator<string, LlmConfiguration>
     */
    public function resolve(FallbackChain $chain, Closure $onSkip): Generator
    {
        foreach ($chain->configurationIdentifiers as $identifier) {
            $candidate = $this->repository->findOneByIdentifier($identifier);
            if (!$candidate instanceof LlmConfiguration) {
                $onSkip($identifier, FallbackSkipReason::NotFound);

                continue;
            }

            if (!$candidate->isActive()) {
                $onSkip($identifier, FallbackSkipReason::Inactive);

                continue;
            }

            yield $identifier => $candidate;
        }
    }
}

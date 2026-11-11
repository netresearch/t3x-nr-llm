<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The registered domain handlers (spec 051 A2A-SV4). A handler that says it
 * is not available (the TCK fixture outside the Testing context) is neither
 * offered nor returned.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aDomainHandlerRegistry
{
    /**
     * @param iterable<A2aDomainHandlerInterface> $handlers
     */
    public function __construct(
        #[AutowireIterator(A2aDomainHandlerInterface::TAG)]
        private iterable $handlers,
    ) {}

    public function get(string $identifier): ?A2aDomainHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->identifier() === $identifier && $handler->isAvailable()) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function identifiers(): array
    {
        $identifiers = [];
        foreach ($this->handlers as $handler) {
            if ($handler->isAvailable()) {
                $identifiers[] = $handler->identifier();
            }
        }

        return $identifiers;
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\UseCase;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects every DI-tagged {@see UseCasePackProviderInterface} and exposes the
 * declared packs (ADR-163).
 *
 * The registry reads no database. It answers "what is declared", never "what is
 * installed" — that question needs a plan, and {@see UseCasePackInstaller}
 * answers it. Keeping the split means the registry can be constructed in a unit
 * test and, more importantly, that it cannot depend on the repositories the
 * configuration-preset bridge below it already depends on.
 */
final class UseCasePackRegistry
{
    /** @var array<string, UseCasePack> */
    private array $byIdentifier = [];

    /**
     * @param iterable<UseCasePackProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(UseCasePackProviderInterface::TAG_NAME)]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            foreach ($provider->getPacks() as $pack) {
                if (isset($this->byIdentifier[$pack->identifier])) {
                    throw new LogicException(
                        sprintf('Duplicate use-case pack identifier "%s".', $pack->identifier),
                        1791460031,
                    );
                }

                $this->byIdentifier[$pack->identifier] = $pack;
            }
        }
    }

    /**
     * @return list<UseCasePack>
     */
    public function all(): array
    {
        return array_values($this->byIdentifier);
    }

    public function findByIdentifier(string $identifier): ?UseCasePack
    {
        return $this->byIdentifier[$identifier] ?? null;
    }

    /**
     * The packs answering one use case, in declaration order.
     *
     * An empty result is a legitimate answer, and the entry step says so rather
     * than dropping the use case: "no pack for this yet, here is the technical
     * wizard" is more useful than a question with hidden options.
     *
     * @return list<UseCasePack>
     */
    public function forUseCase(UseCase $useCase): array
    {
        return array_values(array_filter(
            $this->byIdentifier,
            static fn(UseCasePack $pack): bool => $pack->useCase === $useCase,
        ));
    }
}

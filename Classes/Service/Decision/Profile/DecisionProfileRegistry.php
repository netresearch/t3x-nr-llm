<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Decision\Profile;

use Exception;
use InvalidArgumentException;
use Netresearch\NrLlm\Service\Decision\DecisionException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every declared decision profile by identifier (ADR-211).
 *
 * Built on first use, not when the container builds it, and failures stay
 * with the provider that caused them: a provider that throws while declaring
 * its profiles, or declares an invalid one, is set aside, and an identifier
 * declared twice is withheld. An invalid declaration is the same on every
 * call and is kept; any other failure may pass, so a build that met one is
 * not kept and the next lookup asks again. The other providers' profiles keep working. A
 * lookup that finds nothing while such a failure is recorded answers with the
 * failure instead of "unknown profile", because the profile asked for may be
 * the broken one.
 *
 * @internal
 */
final class DecisionProfileRegistry
{
    /** @var array<string, DecisionProfile>|null */
    private ?array $byIdentifier = null;

    /** @var array<string, DecisionException> identifier => why it is withheld */
    private array $withheld = [];

    /** @var list<DecisionException> providers set aside as a whole */
    private array $broken = [];

    /**
     * @param iterable<DecisionProfileProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(DecisionProfileProviderInterface::TAG_NAME)]
        private readonly iterable $providers,
    ) {}

    /**
     * @throws DecisionException when the profile is withheld, or when it is not found and a provider failed
     */
    public function find(string $identifier): ?DecisionProfile
    {
        $profiles = $this->profiles();
        if (isset($profiles[$identifier])) {
            return $profiles[$identifier];
        }

        if (isset($this->withheld[$identifier])) {
            throw $this->withheld[$identifier];
        }

        if ($this->broken !== []) {
            throw $this->broken[0];
        }

        return null;
    }

    /**
     * The profiles that can be used; a failed provider's are not among them.
     *
     * @return list<DecisionProfile>
     */
    public function all(): array
    {
        return array_values($this->profiles());
    }

    /**
     * @return array<string, DecisionProfile>
     */
    private function profiles(): array
    {
        if ($this->byIdentifier !== null) {
            return $this->byIdentifier;
        }

        $profiles = [];
        $declaredBy = [];
        $this->withheld = [];
        $this->broken = [];
        $lasting = true;
        foreach ($this->providers as $provider) {
            try {
                $declared = $provider->getDecisionProfiles();
            } catch (InvalidArgumentException $e) {
                // An invalid profile: the same on every call, so it is kept.
                $this->broken[] = DecisionException::invalidProfile($provider::class, $e->getMessage(), $e);

                continue;
            } catch (Exception $e) {
                // Any other failure of the provider (a read it depends on) may
                // pass: set aside for this call only, asked again next time.
                // An \Error is a defect and propagates.
                $this->broken[] = DecisionException::invalidProfile($provider::class, $e->getMessage(), $e);
                $lasting = false;

                continue;
            }

            foreach ($declared as $profile) {
                $identifier = $profile->identifier;
                if (isset($declaredBy[$identifier])) {
                    $this->withheld[$identifier] = DecisionException::invalidProfile(
                        $provider::class,
                        sprintf('the identifier "%s" is declared twice (also by %s)', $identifier, $declaredBy[$identifier]),
                    );
                    unset($profiles[$identifier]);

                    continue;
                }

                $declaredBy[$identifier] = $provider::class;
                $profiles[$identifier] = $profile;
            }
        }

        if ($lasting) {
            $this->byIdentifier = $profiles;
        }

        return $profiles;
    }
}

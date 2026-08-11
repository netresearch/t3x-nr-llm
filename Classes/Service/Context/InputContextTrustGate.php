<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Context;

use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
use Netresearch\NrLlm\Domain\ValueObject\InputContextDecision;
use Netresearch\NrLlm\Exception\InputContextTrustZoneException;
use Netresearch\NrLlm\Service\Governance\DataClassEnforcementResolver;
use Netresearch\NrLlm\Service\Governance\GovernanceEventRepositoryInterface;
use Netresearch\NrLlm\Service\Governance\TrustZoneResolver;
use Psr\Log\LoggerInterface;

/**
 * Refuses a call whose injected context is classified above the trust zone it
 * can reach (ADR-144).
 *
 * The mirror of the ADR-094 tool gate, across the same axis and using the same
 * operator switch: that gate decides what a tool may READ for a run, this one
 * what the run may SEND. One switch, because it is one question — does a
 * declared data class bind against a provider's trust zone — asked in two
 * directions.
 *
 * **Undeclared is not a class.** A source nobody classified places no
 * constraint, so an installation that has declared nothing behaves exactly as
 * it did before this gate existed. That is what makes the axis safe to ship
 * enforcing: the migration risk is not in the switch, it is in guessing a value
 * for data that already flows — and nothing here guesses.
 *
 * A configuration that injects nothing is never refused, which matters because
 * this runs for every configuration-driven operation: the vision path builds a
 * transient configuration with no snippets and no skills, and refusing it would
 * block a send that does not carry the classified content at all.
 *
 * The zone is the LEAST trusted the run can reach, fallbacks included
 * ({@see TrustZoneResolver::zoneFor()}): a configuration that can fail over to
 * an external provider really can send there.
 *
 * @internal
 */
final readonly class InputContextTrustGate
{
    public function __construct(
        private InputContextClassifier $classifier,
        private TrustZoneResolver $trustZoneResolver,
        private DataClassEnforcementResolver $enforcement,
        private ?GovernanceEventRepositoryInterface $governanceEvents = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * The gate's answer, without acting on it (ADR-157).
     *
     * The rule lives HERE and {@see self::assertPermitted()} consumes it, so
     * the classification-versus-zone comparison exists once. A simulator that
     * called `assertPermitted()` and caught the exception would get the wrong
     * answer in observe mode, where nothing is thrown for a configuration the
     * runtime records as blocked.
     *
     * Pure: it resolves, compares and reports. Recording the governance event
     * and throwing belong to the caller that is actually running the send.
     */
    public function decide(LlmConfiguration $configuration): InputContextDecision
    {
        $classification = $this->classifier->classify($configuration);
        if (!$classification->isDeclared()) {
            return InputContextDecision::undeclared();
        }

        /** @var ToolDataClass $declared a declared classification always carries one */
        $declared = $classification->effective;

        $zone = $this->trustZoneResolver->zoneFor($configuration);
        if ($zone->permits($declared)) {
            return InputContextDecision::permitted($declared, $classification->source, $zone);
        }

        return InputContextDecision::refused($declared, $classification->source, $zone, $this->enforcement->enforcing());
    }

    /**
     * Throw when this configuration may not carry the context it injects.
     *
     * In observe mode nothing is thrown and the refusal is recorded instead, so
     * an operator can see what enforcement would do before switching it on —
     * the shape ADR-113 established for the tool gate.
     */
    public function assertPermitted(LlmConfiguration $configuration, int $beUser = 0): void
    {
        $decision = $this->decide($configuration);
        if (!$decision->zoneRefused) {
            return;
        }

        /** @var ToolDataClass $declared a refusal always carries the class it refused */
        $declared = $decision->declaredClass;
        /** @var TrustZone $zone a refusal always carries the zone that refused */
        $zone = $decision->zone;

        $this->record($configuration, $beUser, $declared, $decision->source, !$decision->isObservedOnly());

        if ($decision->isObservedOnly()) {
            $this->logger?->warning(
                'Injected context is classified above the trust zone this configuration can reach. '
                . 'Enforcement is set to observe, so the call proceeds.',
                [
                    'configuration' => $configuration->getIdentifier(),
                    'trustZone'     => $zone->value,
                    'declaredClass' => $declared->value,
                    'source'        => $decision->source,
                ],
            );

            return;
        }

        throw InputContextTrustZoneException::forConfiguration(
            $configuration->getIdentifier(),
            $zone,
            $declared,
            $decision->source,
        );
    }

    /**
     * Record the decision for the governance audit.
     *
     * `detail` carries the SOURCE NAME and the observe flag — never the
     * snippet or skill text. The classification exists because that text is
     * sensitive; writing it into an audit row to explain why it must not leave
     * the installation would be the same leak by another route.
     */
    private function record(
        LlmConfiguration $configuration,
        int $beUser,
        ToolDataClass $declared,
        string $source,
        bool $enforcing,
    ): void {
        $this->governanceEvents?->record(new GovernanceEvent(
            correlationId: '',
            decision: GovernanceDecision::CONTEXT_BLOCKED->value,
            reason: $declared->value,
            provider: $configuration->getProvider()?->getIdentifier() ?? '',
            model: $configuration->getLlmModel()?->getModelId() ?? '',
            configurationIdentifier: $configuration->getIdentifier(),
            beUser: $beUser,
            toolName: '',
            agentrunUid: 0,
            guardrail: '',
            detail: $enforcing ? $source : $source . ' (observe)',
        ));
    }
}

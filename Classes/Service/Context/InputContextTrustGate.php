<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Context;

use Netresearch\NrLlm\Domain\Enum\GovernanceDecision;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceEvent;
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
     * Throw when this configuration may not carry the context it injects.
     *
     * In observe mode nothing is thrown and the refusal is recorded instead, so
     * an operator can see what enforcement would do before switching it on —
     * the shape ADR-113 established for the tool gate.
     *
     * $agentRunUid attributes the refusal to the agent run that triggered it
     * (ADR-153); 0 for a plain provider call, which has no run.
     */
    public function assertPermitted(LlmConfiguration $configuration, int $beUser = 0, int $agentRunUid = 0): void
    {
        $classification = $this->classifier->classify($configuration);
        if (!$classification->isDeclared()) {
            return;
        }

        /** @var ToolDataClass $declared a declared classification always carries one */
        $declared = $classification->effective;

        $zone = $this->trustZoneResolver->zoneFor($configuration);
        if ($zone->permits($declared)) {
            return;
        }

        $enforcing = $this->enforcement->enforcing();
        $this->record($configuration, $beUser, $declared, $classification->source, $enforcing, $agentRunUid);

        if (!$enforcing) {
            $this->logger?->warning(
                'Injected context is classified above the trust zone this configuration can reach. '
                . 'Enforcement is set to observe, so the call proceeds.',
                [
                    'configuration' => $configuration->getIdentifier(),
                    'trustZone'     => $zone->value,
                    'declaredClass' => $declared->value,
                    'source'        => $classification->source,
                ],
            );

            return;
        }

        throw InputContextTrustZoneException::forConfiguration(
            $configuration->getIdentifier(),
            $zone,
            $declared,
            $classification->source,
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
        int $agentRunUid,
    ): void {
        $this->governanceEvents?->record(new GovernanceEvent(
            // This gate runs BEFORE the ProviderCallContext exists, so there is
            // no trace id to write; the run uid below is the join key instead.
            correlationId: '',
            decision: GovernanceDecision::CONTEXT_BLOCKED->value,
            reason: $declared->value,
            provider: $configuration->getProvider()?->getIdentifier() ?? '',
            model: $configuration->getLlmModel()?->getModelId() ?? '',
            configurationIdentifier: $configuration->getIdentifier(),
            beUser: $beUser,
            toolName: '',
            agentrunUid: $agentRunUid,
            guardrail: '',
            detail: $enforcing ? $source : $source . ' (observe)',
        ));
    }
}

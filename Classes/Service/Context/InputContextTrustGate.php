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
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Domain\Model\Skill;
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
 * transient configuration with no snippets, no skills and no system prompt, and
 * refusing it would block a send that does not carry the classified content at
 * all.
 *
 * The zone is the LEAST trusted the run can reach, fallbacks included
 * ({@see TrustZoneResolver::zoneFor()}): a configuration that can fail over to
 * an external provider really can send there.
 *
 * A criteria-mode configuration has no provider relation, so its zone comes
 * from the model the caller already resolved for this call (ADR-149). The gate
 * never resolves one itself: that would invert the dependency and make the
 * gate drive routing. When no model is threaded in — routing selected nothing,
 * or the caller has none — `EXTERNAL_GLOBAL` stays the fail-closed answer,
 * which is exactly what this path did before.
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
     *
     * `$servingModel` is the model the caller resolved for this call (ADR-149).
     * A simulator that has not resolved one passes null and gets the zone the
     * configuration's own relation gives — the pre-ADR-149 answer, which for a
     * criteria-mode record is the fail-closed `EXTERNAL_GLOBAL`.
     *
     * `$forcedSnippets` / `$forcedSkills` are the sources a caller injects on
     * top of the configuration for this one run (ADR-164). They bind: the
     * ceiling is a statement about where the text may travel, and a caller
     * choosing to attach it does not make the destination more trustworthy.
     * Passing them folds them into the same classification the configuration's
     * own sources produce, so the strictest declaration wins and the refusal
     * names whichever source set it — configuration or forced.
     *
     * @param list<PromptSnippet> $forcedSnippets
     * @param list<Skill>         $forcedSkills
     */
    public function decide(
        LlmConfiguration $configuration,
        ?Model $servingModel = null,
        array $forcedSnippets = [],
        array $forcedSkills = [],
    ): InputContextDecision {
        // sources() + strictest() IS classify() when nothing is forced, so a
        // caller that injects nothing gets exactly the pre-ADR-164 answer. It
        // is also the list the ADR-151 readout folds, which is what keeps the
        // panel and the gate from disagreeing about what a run carries.
        $classification = InputContextClassifier::strictest(
            $this->classifier->sources($configuration, $forcedSnippets, $forcedSkills),
        );
        if (!$classification->isDeclared()) {
            return InputContextDecision::undeclared();
        }

        /** @var ToolDataClass $declared a declared classification always carries one */
        $declared = $classification->effective;

        $zone = $this->trustZoneResolver->zoneFor($configuration, $servingModel);
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
     *
     * `$servingModel` is the model the caller resolved for this call, where one
     * exists; null keeps the pre-ADR-149 zone.
     *
     * `$agentRunUid` attributes the refusal to the agent run that triggered it
     * (ADR-153); 0 for a plain provider call, which has no run.
     *
     * `$forcedSnippets` / `$forcedSkills` carry the run's per-call injections
     * into the rule (ADR-164) — see {@see self::decide()}.
     *
     * @param list<PromptSnippet> $forcedSnippets
     * @param list<Skill>         $forcedSkills
     */
    public function assertPermitted(
        LlmConfiguration $configuration,
        int $beUser = 0,
        ?Model $servingModel = null,
        int $agentRunUid = 0,
        array $forcedSnippets = [],
        array $forcedSkills = [],
    ): void {
        $decision = $this->decide($configuration, $servingModel, $forcedSnippets, $forcedSkills);
        if (!$decision->zoneRefused) {
            return;
        }

        /** @var ToolDataClass $declared a refusal always carries the class it refused */
        $declared = $decision->declaredClass;
        /** @var TrustZone $zone a refusal always carries the zone that refused */
        $zone = $decision->zone;

        $this->record($configuration, $beUser, $declared, $decision->source, !$decision->isObservedOnly(), $servingModel, $agentRunUid);

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
     *
     * `provider` and `model` name whatever the zone was read from. For a
     * criteria-mode configuration that is the resolved model, not the empty
     * relation — a row that says "blocked at EXTERNAL_GLOBAL" with no provider
     * in it cannot be checked against anything.
     */
    private function record(
        LlmConfiguration $configuration,
        int $beUser,
        ToolDataClass $declared,
        string $source,
        bool $enforcing,
        ?Model $servingModel,
        int $agentRunUid,
    ): void {
        $zoneModel = $servingModel ?? $configuration->getLlmModel();

        $this->governanceEvents?->record(new GovernanceEvent(
            // This gate runs BEFORE the ProviderCallContext exists, so there is
            // no trace id to write; the run uid below is the join key instead.
            correlationId: '',
            decision: GovernanceDecision::CONTEXT_BLOCKED->value,
            reason: $declared->value,
            provider: $zoneModel?->getProvider()?->getIdentifier() ?? '',
            model: $zoneModel?->getModelId() ?? '',
            configurationIdentifier: $configuration->getIdentifier(),
            beUser: $beUser,
            toolName: '',
            agentrunUid: $agentRunUid,
            guardrail: '',
            detail: $enforcing ? $source : $source . ' (observe)',
        ));
    }
}

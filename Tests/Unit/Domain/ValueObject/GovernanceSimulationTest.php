<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Enum\SimulationVerdict;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\GovernanceSimulation;
use Netresearch\NrLlm\Domain\ValueObject\InputContextDecision;
use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Domain\ValueObject\RoutingDecision;
use Netresearch\NrLlm\Domain\ValueObject\RoutingReadout;
use Netresearch\NrLlm\Domain\ValueObject\SimulationActor;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The fold from five axes to one verdict (ADR-157, ADR-167).
 *
 * Each axis gets its own refusal here, because the whole point of the record is
 * that ANY of them decides — a fold that only consulted the tool gate would
 * pass a test that only refuses through the tool gate.
 */
#[CoversClass(GovernanceSimulation::class)]
final class GovernanceSimulationTest extends TestCase
{
    #[Test]
    public function everyAxisPermittingAndNoApprovalIsAllow(): void
    {
        self::assertSame(SimulationVerdict::ALLOW, $this->simulation()->getVerdict());
    }

    #[Test]
    public function anApprovalBoundToolIsNotReportedAsAPlainAllow(): void
    {
        // The distinction the third case exists for: this call runs only after
        // a human says yes, and folding it into ALLOW would hide the axis at
        // the moment it decides.
        self::assertSame(
            SimulationVerdict::ALLOW_WITH_APPROVAL,
            $this->simulation(approvalRequired: true)->getVerdict(),
        );
    }

    #[Test]
    public function theToolGateRefusing(): void
    {
        $simulation = $this->simulation(tool: new ToolPolicyDecision(
            'read_secrets',
            false,
            ToolDataClass::SECRET_ADJACENT,
            TrustZone::EXTERNAL_GLOBAL,
            ToolDataClass::PUBLIC_CONTENT,
            ToolDenialReason::TRUST_ZONE,
        ));

        self::assertSame(SimulationVerdict::BLOCK, $simulation->getVerdict());
    }

    #[Test]
    public function theInputContextGateRefusing(): void
    {
        $simulation = $this->simulation(context: InputContextDecision::refused(
            ToolDataClass::SECRET_ADJACENT,
            'snippet "legal-policy"',
            TrustZone::EXTERNAL_GLOBAL,
            true,
        ));

        self::assertSame(SimulationVerdict::BLOCK, $simulation->getVerdict());
    }

    #[Test]
    public function observeModeOnTheInputContextGateDoesNotBlock(): void
    {
        // The send proceeds and the refusal is recorded. Reporting BLOCK would
        // describe an enforcement setting the installation does not have.
        $simulation = $this->simulation(context: InputContextDecision::refused(
            ToolDataClass::SECRET_ADJACENT,
            'snippet "legal-policy"',
            TrustZone::EXTERNAL_GLOBAL,
            false,
        ));

        self::assertSame(SimulationVerdict::ALLOW, $simulation->getVerdict());
    }

    #[Test]
    public function routingResolvingNoModel(): void
    {
        // Nothing can serve the run, so the tool gate's "Allowed" is beside the
        // point: the call cannot be sent at all.
        $simulation = $this->simulation(routing: RoutingReadout::decided(
            new RoutingDecision(null, [], RoutingPolicyMode::BALANCED),
            false,
            null,
            false,
            false,
        ));

        self::assertSame(SimulationVerdict::BLOCK, $simulation->getVerdict());
        self::assertFalse($simulation->hasServingModel());
    }

    #[Test]
    public function approvalDoesNotRescueARefusingAxis(): void
    {
        $simulation = $this->simulation(
            context: InputContextDecision::refused(
                ToolDataClass::SECRET_ADJACENT,
                'skill "hr-handbook"',
                TrustZone::EXTERNAL_GLOBAL,
                true,
            ),
            approvalRequired: true,
        );

        self::assertSame(SimulationVerdict::BLOCK, $simulation->getVerdict());
    }

    #[Test]
    public function configurationAccessRefusing(): void
    {
        // ADR-167: the actor may not use the configuration at all, so no gate
        // downstream of it gets a say.
        $simulation = $this->simulation(configurationAllowed: false);

        self::assertSame(SimulationVerdict::BLOCK, $simulation->getVerdict());
    }

    #[Test]
    public function configurationAccessRefusingIsNotRescuedByAnApprovalBoundTool(): void
    {
        $simulation = $this->simulation(approvalRequired: true, configurationAllowed: false);

        self::assertSame(SimulationVerdict::BLOCK, $simulation->getVerdict());
    }

    private function simulation(
        ?ToolPolicyDecision $tool = null,
        ?InputContextDecision $context = null,
        ?RoutingReadout $routing = null,
        bool $approvalRequired = false,
        bool $configurationAllowed = true,
    ): GovernanceSimulation {
        $model = new Model();
        $model->setModelId('gpt-4o');
        $model->setName('GPT-4o');

        return new GovernanceSimulation(
            $tool ?? new ToolPolicyDecision('get_page_tree', true, ToolDataClass::EDITOR_CONTENT, TrustZone::LOCAL, ToolDataClass::SECRET_ADJACENT),
            $context ?? InputContextDecision::undeclared(),
            $routing ?? RoutingReadout::decided(
                new RoutingDecision($model, [RoutingCandidate::eligible($model, 0.62, [])], RoutingPolicyMode::BALANCED),
                false,
                null,
                false,
                false,
            ),
            $approvalRequired,
            $configurationAllowed,
            new SimulationActor(7, 'editor', false),
        );
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Service\Governance\EffectivePolicyRow;
use Netresearch\NrLlm\Service\Governance\GovernanceProfile;
use Netresearch\NrLlm\Service\Governance\GovernanceProfileDeviation;
use Netresearch\NrLlm\Service\Governance\GovernanceProfileEvaluator;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * The Governance tab's markup, rendered (ADR-145).
 *
 * The profile diff and the simulator are ~90 lines of Fluid with conditionals,
 * an enum-backed selector and a ViewHelper whose title is built from a
 * condition. A unit test of the evaluator touches none of it: a mistyped
 * ViewHelper argument or an unbalanced inline expression parses as text and
 * fails only when an operator opens the tab.
 *
 * Rendered through the real Fluid stack with the variables the controller
 * assigns. The doc-header and module chrome are deliberately left out — they
 * belong to the module shell rather than to this feature, and driving them
 * needs a backend route this test would only be simulating.
 */
#[CoversNothing]
final class GovernanceTabRenderTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function theContainerCanBuildTheModuleController(): void
    {
        // The Governance action gained four collaborators (the profile
        // evaluator, the configuration repository, the tool gate, its
        // registry). A hand-wired controller in a test would hide an argument
        // production cannot autowire; asking the container cannot.
        $this->extbaseRequest();

        self::assertInstanceOf(LlmModuleController::class, $this->getService(LlmModuleController::class));
    }

    #[Test]
    public function theTabRendersWithoutAProfileSelected(): void
    {
        $body = $this->render(null, [], null);

        self::assertStringContainsString('privacy.level', $body, 'the ADR-140 readout is still the page spine');
        // The labels, not the enum values: f:translate resolves them, which is
        // exactly the path that broke before — a profile whose label key Fluid
        // could not reach threw instead of rendering.
        self::assertStringContainsString('Enterprise strict', $body, 'every profile is offered');
        self::assertStringContainsString('Local only', $body);
        self::assertStringNotContainsString('Profile expects', $body, 'nothing is compared until one is chosen');
    }

    #[Test]
    public function selectingAProfileRendersTheDiffWithBothValuesAndWhereToChangeIt(): void
    {
        $deviations = (new GovernanceProfileEvaluator())->deviations($this->rows(), GovernanceProfile::ENTERPRISE_STRICT);
        self::assertNotSame([], $deviations, 'the fixture must actually deviate, or this asserts nothing');

        $body = $this->render(GovernanceProfile::ENTERPRISE_STRICT, $deviations, null);

        self::assertStringContainsString('Profile expects', $body);
        self::assertStringContainsString('privacy.level', $body);
        // With no apply path (ADR-140), "wrong" alone would be half an answer.
        self::assertStringContainsString('Extension Configuration', $body);
    }

    #[Test]
    public function aCompliantInstallationSaysSoInsteadOfShowingAnEmptyTable(): void
    {
        $body = $this->render(GovernanceProfile::ENTERPRISE_STRICT, [], null);

        self::assertStringContainsString('Matches the profile', $body);
        self::assertStringNotContainsString('Profile expects', $body);
    }

    #[Test]
    public function aRefusedSimulationRendersTheDecisionAndTheTwoFactsBehindIt(): void
    {
        $body = $this->render(null, [], new ToolPolicyDecision(
            'read_secrets',
            false,
            ToolDataClass::SECRET_ADJACENT,
            TrustZone::EXTERNAL_GLOBAL,
            ToolDataClass::PUBLIC_CONTENT,
            ToolDenialReason::TRUST_ZONE,
        ));

        self::assertStringContainsString('Refused', $body);
        self::assertStringContainsString('read_secrets', $body);
        self::assertStringContainsString('secretAdjacent', $body, 'the tool data class explains the refusal');
        self::assertStringContainsString('externalGlobal', $body, 'so does the zone the call can reach');
        self::assertStringContainsString('permits at most', $body);
    }

    #[Test]
    public function anAllowedSimulationRendersAsAllowed(): void
    {
        $body = $this->render(null, [], new ToolPolicyDecision(
            'get_page_tree',
            true,
            ToolDataClass::EDITOR_CONTENT,
            TrustZone::LOCAL,
            ToolDataClass::SECRET_ADJACENT,
        ));

        self::assertStringContainsString('Allowed', $body);
        self::assertStringNotContainsString('Refused', $body);
    }

    /**
     * The Module layout renders f:flashMessages, which resolves its queue from
     * an extbase request. A plain PSR-7 request makes the LAYOUT fail before
     * this template's own markup is reached.
     */
    private function extbaseRequest(): ExtbaseRequest
    {
        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerName('Backend\\LlmModule');
        $parameters->setControllerActionName('governance');
        $parameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('extbase', $parameters);
        // The Module layout publishes backend assets, which needs the request's
        // normalized params.
        $serverRequest = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));

        // Extbase's ConfigurationManager reads the ambient request, not the one
        // handed to the view.
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        return new ExtbaseRequest($serverRequest);
    }

    /**
     * @return list<EffectivePolicyRow>
     */
    private function rows(): array
    {
        return [
            new EffectivePolicyRow('privacy.level', 'full', 'TestReader'),
            new EffectivePolicyRow('privacy.retentionDays', '90', 'TestReader'),
            new EffectivePolicyRow('tools.dataClassEnforcement', 'enforce', 'TestReader'),
            new EffectivePolicyRow('skills.minTrustLevel', 'first_party', 'TestReader'),
        ];
    }

    /**
     * @param list<GovernanceProfileDeviation> $deviations
     */
    private function render(?GovernanceProfile $profile, array $deviations, ?ToolPolicyDecision $simulation): string
    {
        $view = $this->getService(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: ['EXT:nr_llm/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:nr_llm/Resources/Private/Partials/'],
            // The template declares <f:layout name="Module" />, which ships
            // with EXT:backend rather than with this extension.
            layoutRootPaths: [
                'EXT:nr_llm/Resources/Private/Layouts/',
                'EXT:backend/Resources/Private/Layouts/',
            ],
            templatePathAndFilename: GeneralUtility::getFileAbsFileName(
                'EXT:nr_llm/Resources/Private/Templates/Backend/Governance.html',
            ),
            request: $this->extbaseRequest(),
        ));
        $view->assignMultiple([
            'policyRows'     => $this->rows(),
            'profiles'       => GovernanceProfile::cases(),
            'profile'        => $profile,
            'deviations'     => $deviations,
            'configurations' => [],
            'toolNames'      => ['get_page_tree'],
            'simulation'     => $simulation,
        ]);

        return $view->render();
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\LlmModuleController;
use Netresearch\NrLlm\Domain\Enum\ModelCapability;
use Netresearch\NrLlm\Domain\Enum\RoutingPolicyMode;
use Netresearch\NrLlm\Domain\Enum\RoutingRejectionReason;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolDenialReason;
use Netresearch\NrLlm\Domain\Enum\TrustZone;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\ValueObject\RoutingCandidate;
use Netresearch\NrLlm\Domain\ValueObject\RoutingDecision;
use Netresearch\NrLlm\Domain\ValueObject\RoutingReadout;
use Netresearch\NrLlm\Domain\ValueObject\ToolPolicyDecision;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Governance\EffectivePolicyRow;
use Netresearch\NrLlm\Service\Governance\GovernanceProfile;
use Netresearch\NrLlm\Service\Governance\GovernanceProfileDeviation;
use Netresearch\NrLlm\Service\Governance\GovernanceProfileEvaluator;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
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

    #[Test]
    public function aFixedConfigurationRendersAsNoDecisionRatherThanAsOne(): void
    {
        // The trap ADR-148 exists to avoid: a named model must not be dressed
        // up as a decision with one winning candidate.
        $model = new Model();
        $model->setModelId('gpt-4o');
        $model->setName('GPT-4o');

        $body = $this->render(null, [], null, RoutingReadout::fixed($model));

        self::assertStringContainsString('No decision: the operator named the model', $body);
        self::assertStringContainsString('gpt-4o', $body);
        self::assertStringNotContainsString('Eligible, in rank order', $body);
        self::assertStringNotContainsString('Policy mode:', $body, 'no mode was consulted, so none is shown');
    }

    #[Test]
    public function aDecisionRendersTheRankedCandidatesTheSignalsAndTheRefusals(): void
    {
        $selected = new Model();
        $selected->setModelId('gpt-4o');
        $selected->setName('GPT-4o');

        $body = $this->render(null, [], null, RoutingReadout::decided(
            new RoutingDecision($selected, [RoutingCandidate::eligible($selected, 0.62, ['quality' => 0.8])], RoutingPolicyMode::BALANCED),
            ModelCapability::TOOLS,
            true,
            false,
        ), [
            [
                'modelId'   => 'gpt-4o',
                'name'      => 'GPT-4o',
                'provider'  => 'openai',
                'score'     => '0.620',
                'reasonKey' => '',
                'signals'   => [
                    ['name' => 'quality', 'value' => '0.80', 'known' => true],
                    ['name' => 'health', 'value' => '', 'known' => false],
                ],
            ],
        ], [
            [
                'modelId'   => 'llama3',
                'name'      => 'Llama 3',
                'provider'  => 'ollama',
                'score'     => '',
                'reasonKey' => RoutingRejectionReason::ADAPTER_NOT_ALLOWED->getLabelKey(),
                'signals'   => [],
            ],
        ]);

        self::assertStringContainsString('Selected', $body);
        self::assertStringNotContainsString('No candidates at all', $body);
        self::assertStringContainsString('Eligible, in rank order', $body);
        self::assertStringContainsString('0.620', $body);
        self::assertStringContainsString('quality:', $body);
        // The distinction a Fluid truthiness check would have destroyed.
        self::assertStringContainsString('no data', $body);
        self::assertStringContainsString('Refused, and why', $body);
        self::assertStringContainsString('adapter type the criteria exclude', $body, 'the rejection reason is translated, not printed as an enum value');
        // "Balanced" alone would also match the mode selector, so assert the
        // readout's own line instead.
        self::assertStringContainsString('Policy mode:', $body);
        self::assertStringContainsString('enforcing: models that declare capabilities without it were refused.', $body);
    }

    #[Test]
    public function anObservedOperationCapabilityIsNotPresentedAsEnforced(): void
    {
        $body = $this->render(null, [], null, RoutingReadout::decided(
            new RoutingDecision(null, [], RoutingPolicyMode::PROVIDER_PRIORITY),
            ModelCapability::TOOLS,
            false,
            true,
        ));

        self::assertStringContainsString('did NOT constrain this decision', $body);
        self::assertStringContainsString('the installed setting is unchanged', $body, 'a tried mode is marked as hypothetical');
        self::assertStringContainsString('No candidates at all', $body, 'an empty catalogue is named as such');
    }

    #[Test]
    public function theFlattenedRowsCarryExactlyTheKeysTheTemplateReads(): void
    {
        // The render tests above hand-write the flattened rows, so the
        // flattener and the template are otherwise verified only against each
        // other by eye: renaming a key in candidateRows() would blank a column
        // in production while phpstan, unit and functional all stay green.
        // This asserts the two agree, by taking the keys from the controller
        // and the paths from the template file.
        $this->extbaseRequest();

        $model = new Model();
        $model->setModelId('gpt-4o');
        $model->setName('GPT-4o');

        $readout = RoutingReadout::decided(
            new RoutingDecision(
                $model,
                [
                    RoutingCandidate::eligible($model, 0.62, ['quality' => 0.8, 'health' => null]),
                    RoutingCandidate::rejected($model, RoutingRejectionReason::ADAPTER_NOT_ALLOWED),
                ],
                RoutingPolicyMode::BALANCED,
            ),
            ModelCapability::TOOLS,
            true,
            false,
        );

        $controller = $this->getService(LlmModuleController::class);
        $flatten    = new ReflectionMethod($controller, 'candidateRows');

        $eligible = $flatten->invoke($controller, $readout, true);
        $rejected = $flatten->invoke($controller, $readout, false);
        assert(is_array($eligible) && is_array($rejected));
        self::assertCount(1, $eligible);
        self::assertCount(1, $rejected);

        $candidateRow = $eligible[0];
        $refusedRow   = $rejected[0];
        assert(is_array($candidateRow) && is_array($refusedRow));
        assert(isset($candidateRow['signals']) && is_array($candidateRow['signals']));
        $signalRow = $candidateRow['signals'][0];
        assert(is_array($signalRow));

        $template = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Resources/Private/Templates/Backend/Governance.html',
        );

        // The alias is `routeCandidate`, not `candidate`: the profile selector
        // higher up the same template already loops `as="candidate"` over
        // GovernanceProfile, and two unrelated shapes under one name is how a
        // reader — and this test — reads the wrong one.
        foreach (['routeCandidate' => $candidateRow, 'signal' => $signalRow] as $alias => $row) {
            $found = preg_match_all('/\{' . $alias . '\.([a-zA-Z]+)/', $template, $matches);
            self::assertGreaterThan(
                0,
                $found,
                'the template reads no {' . $alias . '.…} path, so this test is checking nothing',
            );

            foreach (array_unique($matches[1]) as $path) {
                self::assertArrayHasKey(
                    $path,
                    $row,
                    sprintf('the template reads {%s.%s} and the controller does not produce it', $alias, $path),
                );
            }
        }

        // The other direction: the rejected row carries a reason instead of a
        // score, and nothing else.
        self::assertSame(['modelId', 'name', 'provider', 'score', 'reasonKey', 'signals'], array_keys($refusedRow));
        self::assertSame('', $refusedRow['score'], 'a refused candidate carries no score');
        self::assertSame([], $refusedRow['signals'], 'a refused candidate carries no signals');
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
     * @param list<GovernanceProfileDeviation>                                                                                                                               $deviations
     * @param list<array{modelId: string, name: string, provider: string, score: string, reasonKey: string, signals: list<array{name: string, value: string, known: bool}>}> $eligible
     * @param list<array{modelId: string, name: string, provider: string, score: string, reasonKey: string, signals: list<array{name: string, value: string, known: bool}>}> $rejected
     */
    private function render(
        ?GovernanceProfile $profile,
        array $deviations,
        ?ToolPolicyDecision $simulation,
        ?RoutingReadout $routing = null,
        array $eligible = [],
        array $rejected = [],
    ): string {
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
            // ADR-148: the routing readout, with the candidate tables already
            // flattened the way the controller flattens them.
            'routing'              => $routing,
            'routingEligible'      => $eligible,
            'routingRejected'      => $rejected,
            'routingOperations'    => [ProviderOperation::Chat, ProviderOperation::Vision, ProviderOperation::Tools],
            'routingModes'         => RoutingPolicyMode::cases(),
            'routingConfiguration' => '',
            'routingOperation'     => '',
            'routingPolicyMode'    => '',
        ]);

        return $view->render();
    }
}

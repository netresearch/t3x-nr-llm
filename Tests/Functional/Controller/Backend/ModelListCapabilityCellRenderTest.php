<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use DateTimeImmutable;
use Netresearch\NrLlm\Domain\Enum\CapabilitySource;
use Netresearch\NrLlm\Domain\Model\Model;
use Netresearch\NrLlm\Domain\Model\Provider;
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
 * The capability cell of the model list, rendered (ADR-160).
 *
 * The provenance readout is the whole point of the feature and it is Fluid:
 * a nested condition, an `f:switch` over an enum value, a date format and a
 * dynamically built title. A unit test of `getCapabilityProvenance()` touches
 * none of it — a mistyped ViewHelper argument parses as text and fails only
 * when an operator opens the module.
 *
 * Rendered with realistic rows rather than an empty grid: one model whose
 * capabilities the provider confirmed, one nobody ever asked about, and one
 * mixed record where the operator added a capability on top of the confirmed
 * set. The mixed row is the one the feature exists for.
 */
#[CoversNothing]
final class ModelListCapabilityCellRenderTest extends AbstractFunctionalTestCase
{
    private const CONFIRMED_AT = '2026-08-11 09:30:00';

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function aConfirmedCapabilityRendersAsAPlainBadgeWithItsDate(): void
    {
        $body = $this->render([$this->confirmedModel()]);

        self::assertStringContainsString('class="badge text-bg-secondary"', $body);
        self::assertStringContainsString(
            'title="Confirmed by provider discovery on 2026-08-11 09:30">chat</span>',
            $body,
        );
        self::assertStringContainsString('Last confirmed 2026-08-11 09:30', $body);
    }

    /**
     * The state every pre-provenance record and every hand-created model is
     * in. It must read as "never confirmed", not as a silent blank.
     */
    #[Test]
    public function aModelNobodyEverAskedAboutSaysSo(): void
    {
        $body = $this->render([$this->unconfirmedModel()]);

        self::assertStringContainsString('badge text-bg-warning', $body);
        self::assertStringContainsString('Never confirmed against the provider', $body);
        self::assertStringContainsString('Declared by an operator', $body);
        self::assertStringNotContainsString('Last confirmed', $body);
    }

    /**
     * The load-bearing row: `chat` came from the provider, `tools` did not.
     * One cell, two different claims, and the markup has to keep them apart.
     */
    #[Test]
    public function aMixedRowSeparatesWhatTheProviderSaidFromWhatTheOperatorAdded(): void
    {
        $body = $this->render([$this->mixedModel()]);

        self::assertStringContainsString(
            'title="Confirmed by provider discovery on 2026-08-11 09:30">chat</span>',
            $body,
        );
        self::assertStringContainsString('class="badge text-bg-warning"', $body);
        self::assertStringContainsString('Declared by an operator', $body);
        self::assertStringContainsString('tools', $body);
        self::assertStringContainsString('Last confirmed 2026-08-11 09:30', $body);
    }

    /**
     * A capability the bundled catalog supplied is dated but not confirmed,
     * and the tooltip has to say which of the two it is.
     */
    #[Test]
    public function aCatalogSourcedCapabilityIsNamedAsAnAssumption(): void
    {
        $model = $this->modelWith('Catalog model', 'chat');
        $model->recordCapabilityDiscovery(['chat'], CapabilitySource::Catalog, new DateTimeImmutable(self::CONFIRMED_AT));

        $body = $this->render([$model]);

        self::assertStringContainsString('badge text-bg-warning', $body);
        self::assertStringContainsString('From the bundled model catalog', $body);
        self::assertStringNotContainsString('Declared by an operator', $body);
    }

    #[Test]
    public function theConfirmActionIsOfferedForAModelThatHasAProvider(): void
    {
        $body = $this->render([$this->unconfirmedModel()]);

        self::assertStringContainsString('js-verify-capabilities', $body);
        self::assertStringContainsString('Confirm capabilities against the provider', $body);
    }

    /**
     * Nothing to ask, so nothing to offer — an orphaned model gets the
     * reassign action instead.
     */
    #[Test]
    public function theConfirmActionIsWithheldFromAModelWithoutAProvider(): void
    {
        $orphan = new Model();
        $orphan->_setProperty('uid', 9);
        $orphan->setName('Orphan');
        $orphan->setIdentifier('orphan');
        $orphan->setModelId('orphan');
        $orphan->setCapabilities('chat');

        $body = $this->render([$orphan]);

        self::assertStringNotContainsString('js-verify-capabilities', $body);
    }

    private function modelWith(string $name, string $capabilities): Model
    {
        $provider = new Provider();
        $provider->_setProperty('uid', 1);
        $provider->setName('Contract Provider');
        $provider->setAdapterType('openai');

        $model = new Model();
        $model->_setProperty('uid', 1);
        $model->setName($name);
        $model->setIdentifier('contract-model');
        $model->setModelId('gpt-4o');
        $model->setCapabilities($capabilities);
        $model->setProvider($provider);

        return $model;
    }

    private function confirmedModel(): Model
    {
        $model = $this->modelWith('Confirmed model', 'chat');
        $model->recordCapabilityDiscovery(['chat'], CapabilitySource::Discovery, new DateTimeImmutable(self::CONFIRMED_AT));

        return $model;
    }

    private function unconfirmedModel(): Model
    {
        return $this->modelWith('Unconfirmed model', 'chat,vision');
    }

    private function mixedModel(): Model
    {
        $model = $this->modelWith('Mixed model', 'chat,tools');
        $model->recordCapabilityDiscovery(['chat'], CapabilitySource::Discovery, new DateTimeImmutable(self::CONFIRMED_AT));

        return $model;
    }

    private function extbaseRequest(): ExtbaseRequest
    {
        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerName('Backend\\Model');
        $parameters->setControllerActionName('list');
        $parameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('extbase', $parameters);
        $serverRequest = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));

        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;

        return new ExtbaseRequest($serverRequest);
    }

    /**
     * @param list<Model> $models
     */
    private function render(array $models): string
    {
        $view = $this->getService(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: ['EXT:nr_llm/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:nr_llm/Resources/Private/Partials/'],
            layoutRootPaths: [
                'EXT:nr_llm/Resources/Private/Layouts/',
                'EXT:backend/Resources/Private/Layouts/',
            ],
            templatePathAndFilename: GeneralUtility::getFileAbsFileName(
                'EXT:nr_llm/Resources/Private/Templates/Backend/Model/List.html',
            ),
            request: $this->extbaseRequest(),
        ));

        $editUrls = [];
        foreach ($models as $model) {
            $editUrls[(int)$model->getUid()] = '/typo3/record/edit';
        }

        $view->assignMultiple([
            'models' => $models,
            'providers' => [],
            'capabilities' => Model::getAllCapabilities(),
            'editUrls' => $editUrls,
            'newUrl' => '/typo3/record/edit',
            'wizardUrl' => '/typo3/module/nrllm/wizard',
            'hasDefaultModel' => true,
            'costByModel' => [],
            'reqByModel' => [],
            'tokByModel' => [],
        ]);

        return $view->render();
    }
}

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
use Netresearch\NrLlm\Provider\OpenAi\ModelRecordProfileResolver;
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
 * The resolved reasoning flag and its source in the model list (ADR-274, R8).
 *
 * Rendered from the same map the controller assigns
 * (`ModelRecordProfileResolver::resolveForList()`), so a mistyped Fluid
 * property or a missing label key fails here instead of in the module.
 */
#[CoversNothing]
final class ModelListReasoningCellRenderTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function aTableModelSaysItReasonsAndThatTheTableSaysSo(): void
    {
        $body = $this->render([$this->model(1, 'openai', 'gpt-6-astra')]);

        self::assertStringContainsString('data-nrllm-reasoning="table"', $body);
        self::assertStringContainsString('Reasoning model', $body);
        self::assertStringContainsString('bundled model table', $body);
    }

    #[Test]
    public function anOperatorOverrideIsAttributedToTheOperator(): void
    {
        $model = $this->model(2, 'openai', 'acme-reasoner-1');
        $model->setReasoningOverride('no');

        $body = $this->render([$model]);

        self::assertStringContainsString('data-nrllm-reasoning="operator"', $body);
        self::assertStringContainsString('Not a reasoning model', $body);
        self::assertStringContainsString('set by an operator', $body);
    }

    #[Test]
    public function aThirdPartyAnswerIsNamedAsSuch(): void
    {
        $model = $this->model(3, 'openai', 'gpt-6.9-nova');
        $model->recordReasoningDiscovery(true, [], CapabilitySource::ThirdPartyCatalog, new DateTimeImmutable('2026-11-09'));

        $body = $this->render([$model]);

        self::assertStringContainsString('data-nrllm-reasoning="third_party_catalog"', $body);
        self::assertStringContainsString('not by the provider', $body);
    }

    #[Test]
    public function aModelOfAnotherAdapterShowsNoReasoningLine(): void
    {
        $body = $this->render([$this->model(4, 'anthropic', 'claude-sonnet')]);

        self::assertStringNotContainsString('data-nrllm-reasoning', $body);
    }

    private function model(int $uid, string $adapterType, string $modelId): Model
    {
        $provider = new Provider();
        $provider->_setProperty('uid', $uid);
        $provider->setName('Provider ' . $uid);
        $provider->setAdapterType($adapterType);

        $model = new Model();
        $model->_setProperty('uid', $uid);
        $model->setName('Model ' . $uid);
        $model->setIdentifier('model-' . $uid);
        $model->setModelId($modelId);
        $model->setCapabilities('chat');
        $model->setProvider($provider);

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
            $editUrls[(int) $model->getUid()] = '/typo3/record/edit';
        }

        $view->assignMultiple([
            'models'           => $models,
            'providers'        => [],
            'capabilities'     => Model::getAllCapabilities(),
            'editUrls'         => $editUrls,
            'newUrl'           => '/typo3/record/edit',
            'wizardUrl'        => '/typo3/module/nrllm/wizard',
            'hasDefaultModel'  => true,
            'costByModel'      => [],
            'reqByModel'       => [],
            'tokByModel'       => [],
            'reasoningByModel' => ModelRecordProfileResolver::resolveForList($models),
        ]);

        return $view->render();
    }
}

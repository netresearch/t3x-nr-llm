<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\SkillSourceController;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * The skills list offers "new skill" and "edit" through FormEngine only for a
 * backend source and its skills (ADR-214 item 3); a synced source and its
 * skills get neither, because their content comes from the sync.
 */
#[CoversClass(SkillSourceController::class)]
final class SkillSourceListBackendSkillsTest extends AbstractFunctionalTestCase
{
    private const BACKEND = 30;

    private const SYNCED  = 31;

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function onlyTheBackendSourceAndItsSkillsGetFormEngineLinks(): void
    {
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $pool->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', ['uid' => self::BACKEND, 'title' => 'Written here', 'type' => 'backend', 'trust_level' => 'verified']);
        $pool->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', ['uid' => self::SYNCED, 'title' => 'Upstream', 'type' => 'repo', 'url' => 'https://github.com/acme/skills', 'trust_level' => 'verified']);
        $pool->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', ['uid' => 40, 'source' => self::BACKEND, 'identifier' => 'house', 'name' => 'House', 'enabled' => 1]);
        $pool->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', ['uid' => 41, 'source' => self::SYNCED, 'identifier' => '31:SKILL.md', 'name' => 'Upstream skill', 'enabled' => 1]);

        $request    = $this->actionRequest('list');
        $controller = $this->getService(SkillSourceController::class);
        self::assertInstanceOf(SkillSourceController::class, $controller);
        $body = html_entity_decode((string)$controller->processRequest($request)->getBody());

        self::assertStringContainsString('defVals[tx_nrllm_skill][source]=' . self::BACKEND, urldecode($body));
        self::assertStringNotContainsString('defVals[tx_nrllm_skill][source]=' . self::SYNCED, urldecode($body));
        self::assertStringContainsString('edit[tx_nrllm_skill][40]=edit', urldecode($body));
        self::assertStringNotContainsString('edit[tx_nrllm_skill][41]=edit', urldecode($body));
    }

    private function actionRequest(string $action): ExtbaseRequest
    {
        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerName('Backend\\SkillSource');
        $parameters->setControllerActionName($action);
        $parameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/skills', ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $parameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;
        $GLOBALS['LANG']          = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);

        return new ExtbaseRequest($serverRequest);
    }
}

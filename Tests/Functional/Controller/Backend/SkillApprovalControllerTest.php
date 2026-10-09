<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\SkillApprovalController;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
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
 * Approving and revoking a skill version changes state only on a POST of the
 * review page's form (ADR-214 item 2). A GET of the same URL — a bookmark, a
 * prefetch, a pasted link — changes nothing and leads back to the review.
 */
#[CoversClass(SkillApprovalController::class)]
final class SkillApprovalControllerTest extends AbstractFunctionalTestCase
{
    private const SOURCE = 10;

    private const SKILL  = 5;

    private string $digest = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('BeUsers.csv');
        $this->setUpBackendUser(1);

        $pool = $this->pool();
        $pool->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', [
            'uid'         => self::SOURCE,
            'title'       => 'House skills',
            'type'        => 'repo',
            'trust_level' => 'verified',
        ]);

        $skill = new Skill();
        $skill->setName('Guide');
        $skill->setDescription('House style guide.');
        $skill->setBody('Follow the house style.');
        $skill->setSupportStatus('full');

        $this->digest = SkillVersionDigest::of($skill);

        $pool->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', [
            'uid'            => self::SKILL,
            'source'         => self::SOURCE,
            'identifier'     => '10:skills/guide/SKILL.md',
            'name'           => 'Guide',
            'description'    => 'House style guide.',
            'body'           => 'Follow the house style.',
            'body_checksum'  => hash('sha256', 'Follow the house style.'),
            'support_status' => 'full',
            'version_digest' => $this->digest,
            'trust_level'    => 'verified',
            'enabled'        => 1,
        ]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function aPostedApprovalIsStored(): void
    {
        $this->dispatch('approveVersion', 'POST');

        self::assertSame(1, $this->approvalRows(revoked: false));
    }

    #[Test]
    public function aGetOfTheApproveUrlStoresNothing(): void
    {
        $this->dispatch('approveVersion', 'GET');

        self::assertSame(0, $this->approvalRows(revoked: false));
    }

    /**
     * The core validates the route token against the MATCHED route, and
     * Extbase lets a parameter override the action. A POST that matched the
     * review route (whose token sits in an ordinary GET URL) but names
     * approveVersion must not approve.
     */
    #[Test]
    public function aPostThroughTheReviewRouteDoesNotApprove(): void
    {
        $this->dispatch('approveVersion', 'POST', routeAction: 'review');

        self::assertSame(0, $this->approvalRows(revoked: false));
    }

    #[Test]
    public function aPostThroughTheReviewRouteDoesNotRevoke(): void
    {
        $this->dispatch('approveVersion', 'POST');
        $this->dispatch('revokeVersion', 'POST', routeAction: 'review');

        self::assertSame(0, $this->approvalRows(revoked: true));
    }

    #[Test]
    public function aPostedRevocationRevokes(): void
    {
        $this->dispatch('approveVersion', 'POST');
        $this->dispatch('revokeVersion', 'POST');

        self::assertSame(1, $this->approvalRows(revoked: true));
    }

    #[Test]
    public function aGetOfTheRevokeUrlRevokesNothing(): void
    {
        $this->dispatch('approveVersion', 'POST');
        $this->dispatch('revokeVersion', 'GET');

        self::assertSame(0, $this->approvalRows(revoked: true));
        self::assertSame(1, $this->approvalRows(revoked: false));
    }

    /**
     * @param string|null $routeAction the action the matched route names; the executing action by default
     */
    private function dispatch(string $action, string $method, ?string $routeAction = null): void
    {
        $parameters = new ExtbaseRequestParameters();
        $parameters->setControllerName('Backend\\SkillApproval');
        $parameters->setControllerActionName($action);
        $parameters->setControllerExtensionName('NrLlm');
        $parameters->setArgument('skill', (string)self::SKILL);
        $parameters->setArgument('versionDigest', $this->digest);

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', $method))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/skills', ['packageName' => 'netresearch/nr-llm', 'action' => $routeAction ?? $action]))
            ->withAttribute('extbase', $parameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;
        $GLOBALS['LANG']          = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);

        $controller = $this->getService(SkillApprovalController::class);
        $controller->processRequest(new ExtbaseRequest($serverRequest));
    }

    private function approvalRows(bool $revoked): int
    {
        $queryBuilder = $this->pool()->getQueryBuilderForTable('tx_nrllm_skill_approval');
        $count        = $queryBuilder
            ->count('uid')
            ->from('tx_nrllm_skill_approval')
            ->where(
                $queryBuilder->expr()->eq('skill_uid', self::SKILL),
                $queryBuilder->expr()->eq('revoked', $revoked ? 1 : 0),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int)$count : -1;
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }
}

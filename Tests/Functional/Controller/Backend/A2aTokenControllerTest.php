<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\A2aTokenController;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClientRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSelfServicePolicy;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSelfServiceTokens;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfileRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server\A2aSelfServiceFixture;
use Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server\A2aServerFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Extbase\Mvc\ExtbaseRequestParameters;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;

/**
 * What the A2A token module shows each user (ADR-294): an editor their own
 * tokens and the form that creates one, an administrator every record with its
 * owner, and nobody anything of somebody else's.
 *
 * Installation: {@see A2aSelfServiceFixture}. Editors 20 and 21 each hold a
 * token titled `Agent of <uid>`; the technical clients are `Client 1` to
 * `Client 4`.
 */
#[CoversClass(A2aTokenController::class)]
final class A2aTokenControllerTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;
    use A2aSelfServiceFixture;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'seo', 'workspaces'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServer();
        $this->setUpSelfService();

        $tokens = $this->getService(A2aSelfServiceTokens::class);
        $now    = time();
        foreach ([20, 21] as $uid) {
            $tokens->create($this->logIn($uid), 'Agent of ' . $uid, 1, [1], $now + 30 * 86400, $now);
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anEditorSeesTheirOwnTokensAndTheFormAndNothingOfAnyoneElse(): void
    {
        $response = $this->indexAs(20);
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Agent of 20', $body);
        self::assertStringContainsString('id="nrllm-a2a-token-create"', $body);
        self::assertStringNotContainsString('Agent of 21', $body);
        foreach (['Client 1', 'Client 2', 'Client 3', 'Client 4', 'other-editor', 'a2a-technical'] as $foreign) {
            self::assertStringNotContainsString($foreign, $body);
        }
    }

    #[Test]
    public function anEditorGetsNoControlThatIssuesOrRotatesATokenOfAnotherClient(): void
    {
        $body = (string) $this->indexAs(20)->getBody();

        self::assertStringNotContainsString('js-a2a-issue', $body);
        self::assertStringContainsString('js-a2a-revoke', $body);
    }

    #[Test]
    public function theFormOffersOnlyWhatTheGroupsAllow(): void
    {
        $body = (string) $this->indexAs(20)->getBody();

        self::assertStringContainsString('name="profiles[]" value="1"', $body);
        self::assertStringContainsString('name="profiles[]" value="2"', $body);
        self::assertStringNotContainsString('name="profiles[]" value="3"', $body);
        self::assertStringContainsString('max="90"', $body);
        self::assertStringContainsString('<option value="1">Drafts</option>', $body);
        self::assertStringNotContainsString('<option value="0">', $body, 'live is not offered without the allowance');

        $this->db('be_groups')->update('be_groups', ['tx_nrllm_a2a_allow_live' => 1, 'tx_nrllm_a2a_max_expiry_days' => 200], ['uid' => 30]);
        $relaxed = (string) $this->indexAs(20)->getBody();
        self::assertStringContainsString('<option value="0">Live</option>', $relaxed);
        self::assertStringContainsString('max="200"', $relaxed);
    }

    #[Test]
    public function aGroupWithoutAnAllowedProfileCannotSubmitTheForm(): void
    {
        $this->db('be_groups')->update('be_groups', ['tx_nrllm_a2a_profiles' => ''], ['uid' => 30]);

        $body = (string) $this->indexAs(20)->getBody();

        self::assertStringContainsString('An administrator has not allowed any profile', $body);
        self::assertMatchesRegularExpression('/<button type="submit"[^>]* disabled/', $body);
    }

    #[Test]
    public function anAdministratorSeesEveryRecordWithItsOwnerAndTheAdministratorControls(): void
    {
        $body = (string) $this->indexAs(1)->getBody();

        foreach (['Agent of 20', 'Agent of 21', 'Client 1', 'Client 4', 'editor', 'a2a-technical'] as $expected) {
            self::assertStringContainsString($expected, $body);
        }

        self::assertStringContainsString('js-a2a-issue', $body);
        self::assertStringNotContainsString('id="nrllm-a2a-token-create"', $body, 'an administrator cannot be the acting user');
    }

    #[Test]
    public function aWithdrawnTokenIsShownAsWithdrawnWithItsReason(): void
    {
        $this->db('be_groups')->update('be_groups', ['custom_options' => ''], ['uid' => 30]);

        self::assertStringContainsString('Withdrawn', (string) $this->indexAs(1)->getBody());
    }

    #[Test]
    public function aUserWithoutTheGrantGetsNothing(): void
    {
        $response = $this->indexAs(24);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('Agent of', (string) $response->getBody());
        self::assertStringNotContainsString('Client 1', (string) $response->getBody());
    }

    #[Test]
    public function thePageNeverContainsATokenOrAHashOrAPrefixOfOne(): void
    {
        $stored = $this->db('tx_nrllm_a2a_client')->select(['token_hash', 'token_prefix'], 'tx_nrllm_a2a_client', [])->fetchAllAssociative();
        self::assertNotSame([], $stored);

        foreach ([20, 1] as $uid) {
            $body = (string) $this->indexAs($uid)->getBody();

            self::assertStringNotContainsString('nra2a_', $body);
            foreach ($stored as $row) {
                self::assertNotSame('', $row['token_hash']);
                self::assertStringNotContainsString($row['token_hash'], $body);
                self::assertStringNotContainsString($row['token_prefix'], $body);
            }
        }
    }

    private function logIn(int $uid): BackendUserAuthentication
    {
        $user            = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($user);

        return $user;
    }

    private function indexAs(int $uid): ResponseInterface
    {
        $this->logIn($uid);

        $controller = new A2aTokenController(
            $this->getService(ModuleTemplateFactory::class),
            $this->getService(A2aInboundClientRepository::class),
            $this->getService(A2aSelfServicePolicy::class),
            $this->getService(A2aSkillProfileRepository::class),
            $this->getService(ConnectionPool::class),
            $this->getService(PageRenderer::class),
        );

        $extbaseParameters = new ExtbaseRequestParameters();
        $extbaseParameters->setControllerName('Backend\A2aToken');
        $extbaseParameters->setControllerActionName('index');
        $extbaseParameters->setControllerExtensionName('NrLlm');

        $serverRequest = (new ServerRequest('https://typo3-testing.local/typo3/', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/module/nrllm/a2a-tokens', ['packageName' => 'netresearch/nr-llm']))
            ->withAttribute('extbase', $extbaseParameters);
        $serverRequest            = $serverRequest->withAttribute('normalizedParams', NormalizedParams::createFromRequest($serverRequest));
        $GLOBALS['TYPO3_REQUEST'] = $serverRequest;
        (new ReflectionClass($controller))->getProperty('request')->setValue($controller, new ExtbaseRequest($serverRequest));

        return $controller->indexAction();
    }
}

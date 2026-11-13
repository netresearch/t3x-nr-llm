<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\A2aTokenAjaxController;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClientRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server\A2aSelfServiceFixture;
use Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server\A2aServerFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * The AJAX actions of the A2A token module (ADR-294): who may call each, what
 * comes back, and that the owner is never taken from the request. The route
 * token is {@see A2aTokenRouteTokenTest}.
 */
#[CoversClass(A2aTokenAjaxController::class)]
final class A2aTokenAjaxControllerTest extends AbstractFunctionalTestCase
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
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest())->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function anAdministratorGeneratesAndRotatesATokenThatIsShownOnceAndStoredAsAHash(): void
    {
        $this->setUpBackendUser(1);
        $old = $this->tokens[1];

        $response = $this->controller()->issueAction($this->post('issue', ['uid' => 1]));
        $payload  = $this->payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsString($payload['token']);
        self::assertSame(1, preg_match('/^nra2a_[a-f0-9]{16}_[A-Za-z0-9_-]{43}$/', $payload['token']));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(1, substr_count((string) $response->getBody(), $payload['token']), 'the token appears once, in the response');

        $row = $this->db('tx_nrllm_a2a_client')->select(['*'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $payload['token']), $row['token_hash']);
        self::assertStringNotContainsString($payload['token'], json_encode($row, JSON_THROW_ON_ERROR));

        $repository = $this->getService(A2aInboundClientRepository::class);
        self::assertNull($repository->findByTokenPrefix((string) A2aInboundClientRepository::prefixOf($old)), 'the rotated token is dead');
        self::assertNotNull($repository->findByTokenPrefix((string) A2aInboundClientRepository::prefixOf($payload['token'])));
    }

    #[Test]
    public function aNonAdministratorCannotGenerateOrRotateAndNothingChanges(): void
    {
        $this->setUpBackendUser(20);
        $before = $this->db('tx_nrllm_a2a_client')->select(['token_hash'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchOne();

        $response = $this->controller()->issueAction($this->post('issue', ['uid' => 1]));

        self::assertSame(403, $response->getStatusCode());
        self::assertArrayNotHasKey('token', $this->payload($response));
        self::assertSame($before, $this->db('tx_nrllm_a2a_client')->select(['token_hash'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchOne());
    }

    #[Test]
    public function anAdministratorCannotIssueTheTokenOfASelfServiceRecordOrOfAMissingOne(): void
    {
        $this->setUpBackendUser(20);
        $created = $this->payload($this->controller()->createAction($this->post('create', $this->form())));
        self::assertTrue($created['success']);
        $uid  = (int) $created['uid'];
        $hash = $this->db('tx_nrllm_a2a_client')->select(['token_hash'], 'tx_nrllm_a2a_client', ['uid' => $uid])->fetchOne();

        $this->setUpBackendUser(1);
        foreach ([$uid, 9999] as $target) {
            $response = $this->controller()->issueAction($this->post('issue', ['uid' => $target]));
            self::assertSame(422, $response->getStatusCode(), (string) $target);
            self::assertArrayNotHasKey('token', $this->payload($response));
        }

        self::assertSame($hash, $this->db('tx_nrllm_a2a_client')->select(['token_hash'], 'tx_nrllm_a2a_client', ['uid' => $uid])->fetchOne());
    }

    #[Test]
    public function anEditorWithTheGrantCreatesATokenForTheirOwnAccountWhateverTheRequestClaims(): void
    {
        $this->setUpBackendUser(20);

        $response = $this->controller()->createAction($this->post('create', $this->form() + ['be_user' => 7, 'self_service' => 0, 'uid' => 1, 'task_limit' => 1000, 'enabled' => 0]));
        $payload  = $this->payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $row = $this->db('tx_nrllm_a2a_client')->select(['*'], 'tx_nrllm_a2a_client', ['uid' => $payload['uid']])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame([20, 1, 4, 1, '1'], [(int) $row['be_user'], (int) $row['self_service'], (int) $row['task_limit'], (int) $row['enabled'], $row['profiles']]);
        self::assertSame(hash('sha256', (string) $payload['token']), $row['token_hash']);
    }

    #[Test]
    public function aRequestBeyondTheGroupsLimitsIsRefusedAndStoresNothing(): void
    {
        $this->setUpBackendUser(20);
        $before = $this->rows('tx_nrllm_a2a_client');

        foreach ([['profiles' => [3]], ['days' => 500], ['days' => 0], ['workspace' => 0], ['title' => ' ']] as $override) {
            $response = $this->controller()->createAction($this->post('create', $override + $this->form()));
            self::assertSame(422, $response->getStatusCode(), json_encode($override, JSON_THROW_ON_ERROR));
            self::assertArrayNotHasKey('token', $this->payload($response));
        }

        self::assertSame($before, $this->rows('tx_nrllm_a2a_client'));
    }

    #[Test]
    public function aUserWithoutTheGrantAndAnAdministratorCannotCreateAToken(): void
    {
        $before = $this->rows('tx_nrllm_a2a_client');

        $this->setUpBackendUser(24);
        self::assertSame(403, $this->controller()->createAction($this->post('create', $this->form()))->getStatusCode());

        // The administrator passes the grant check and is refused by the service.
        $this->setUpBackendUser(1);
        self::assertSame(422, $this->controller()->createAction($this->post('create', $this->form()))->getStatusCode());

        self::assertSame($before, $this->rows('tx_nrllm_a2a_client'));
    }

    #[Test]
    public function anEditorRevokesOnlyTheirOwnTokenAndAnAdministratorAnyToken(): void
    {
        $this->setUpBackendUser(21);
        $theirs = (int) $this->payload($this->controller()->createAction($this->post('create', $this->form())))['uid'];

        $this->setUpBackendUser(20);
        foreach ([$theirs, 1] as $foreign) {
            self::assertSame(422, $this->controller()->revokeAction($this->post('revoke', ['uid' => $foreign]))->getStatusCode(), (string) $foreign);
            self::assertSame(1, (int) $this->db('tx_nrllm_a2a_client')->select(['enabled'], 'tx_nrllm_a2a_client', ['uid' => $foreign])->fetchOne(), (string) $foreign);
        }

        $this->setUpBackendUser(1);
        self::assertSame(200, $this->controller()->revokeAction($this->post('revoke', ['uid' => $theirs]))->getStatusCode());
        $row = $this->db('tx_nrllm_a2a_client')->select(['enabled', 'token_hash'], 'tx_nrllm_a2a_client', ['uid' => $theirs])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame([0, ''], [(int) $row['enabled'], $row['token_hash']]);

        $this->setUpBackendUser(24);
        self::assertSame(403, $this->controller()->revokeAction($this->post('revoke', ['uid' => 1]))->getStatusCode());
    }

    private function controller(): A2aTokenAjaxController
    {
        $controller = $this->get(A2aTokenAjaxController::class);
        self::assertInstanceOf(A2aTokenAjaxController::class, $controller);

        return $controller;
    }

    /**
     * @return array<string, mixed>
     */
    private function form(): array
    {
        return ['title' => 'My agent', 'workspace' => 1, 'days' => 30, 'profiles' => [1]];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(string $action, array $body): ServerRequest
    {
        return (new ServerRequest('https://typo3-testing.local/typo3/ajax/nrllm/a2a-token/' . $action, 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withParsedBody($body);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function rows(string $table): int
    {
        return (int) $this->db($table)->count('*', $table, []);
    }
}

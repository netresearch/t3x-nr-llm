<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Controller\Backend;

use Netresearch\NrLlm\Controller\Backend\A2aTokenAjaxController;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aAuthenticator;
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

        $response = $this->controller()->issueAction($this->postTo('issue', ['uid' => 1]));
        $payload  = $this->payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertIsString($payload['token']);
        self::assertMatchesRegularExpression('/^nra2a_[a-f0-9]{16}_[A-Za-z0-9_-]{43}$/', $payload['token']);
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

        $response = $this->controller()->issueAction($this->postTo('issue', ['uid' => 1]));

        self::assertSame(403, $response->getStatusCode());
        self::assertArrayNotHasKey('token', $this->payload($response));
        self::assertSame($before, $this->db('tx_nrllm_a2a_client')->select(['token_hash'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchOne());
    }

    #[Test]
    public function anAdministratorCannotIssueTheTokenOfASelfServiceRecordOrOfAMissingOne(): void
    {
        $this->setUpBackendUser(20);
        $created = $this->payload($this->controller()->createAction($this->postTo('create', $this->form())));
        self::assertTrue($created['success']);
        $uid  = (int) $created['uid'];
        $hash = $this->db('tx_nrllm_a2a_client')->select(['token_hash'], 'tx_nrllm_a2a_client', ['uid' => $uid])->fetchOne();

        $this->setUpBackendUser(1);
        foreach ([$uid, 9999] as $target) {
            $response = $this->controller()->issueAction($this->postTo('issue', ['uid' => $target]));
            self::assertSame(422, $response->getStatusCode(), (string) $target);
            self::assertArrayNotHasKey('token', $this->payload($response));
        }

        self::assertSame($hash, $this->db('tx_nrllm_a2a_client')->select(['token_hash'], 'tx_nrllm_a2a_client', ['uid' => $uid])->fetchOne());

        // Revoked, a self-service record is not enabled by an administrator either.
        self::assertSame(200, $this->controller()->revokeAction($this->postTo('revoke', ['uid' => $uid]))->getStatusCode());
        $response = $this->controller()->issueAction($this->postTo('issue', ['uid' => $uid, 'enable' => '1']));
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayNotHasKey('token', $this->payload($response));
        self::assertSame(0, (int) $this->db('tx_nrllm_a2a_client')->select(['enabled'], 'tx_nrllm_a2a_client', ['uid' => $uid])->fetchOne());
    }

    #[Test]
    public function anEditorWithTheGrantCreatesATokenForTheirOwnAccountWhateverTheRequestClaims(): void
    {
        $this->setUpBackendUser(20);

        $response = $this->controller()->createAction($this->postTo('create', $this->form() + ['be_user' => 7, 'self_service' => 0, 'uid' => 1, 'task_limit' => 1000, 'enabled' => 0]));
        $payload  = $this->payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $row = $this->db('tx_nrllm_a2a_client')->select(['*'], 'tx_nrllm_a2a_client', ['uid' => $payload['uid']])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame([20, 1, 4, 1, '1'], [(int) $row['be_user'], (int) $row['self_service'], (int) $row['task_limit'], (int) $row['enabled'], $row['profiles']]);
        self::assertIsString($payload['token']);
        self::assertSame(hash('sha256', $payload['token']), $row['token_hash']);
    }

    #[Test]
    public function aRequestBeyondTheGroupsLimitsIsRefusedAndStoresNothing(): void
    {
        $this->setUpBackendUser(20);
        $before = $this->rows('tx_nrllm_a2a_client');

        foreach ([['profiles' => [3]], ['days' => 500], ['days' => 0], ['days' => 10 ** 15], ['workspace' => 0], ['title' => ' ']] as $override) {
            $response = $this->controller()->createAction($this->postTo('create', $override + $this->form()));
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
        self::assertSame(403, $this->controller()->createAction($this->postTo('create', $this->form()))->getStatusCode());

        // The administrator passes the grant check and is refused by the service.
        $this->setUpBackendUser(1);
        self::assertSame(422, $this->controller()->createAction($this->postTo('create', $this->form()))->getStatusCode());

        self::assertSame($before, $this->rows('tx_nrllm_a2a_client'));
    }

    #[Test]
    public function anEditorRevokesOnlyTheirOwnTokenAndAnAdministratorAnyToken(): void
    {
        $this->setUpBackendUser(21);
        $theirs = (int) $this->payload($this->controller()->createAction($this->postTo('create', $this->form())))['uid'];

        $this->setUpBackendUser(20);
        foreach ([$theirs, 1] as $foreign) {
            self::assertSame(422, $this->controller()->revokeAction($this->postTo('revoke', ['uid' => $foreign]))->getStatusCode(), (string) $foreign);
            self::assertSame(1, (int) $this->db('tx_nrllm_a2a_client')->select(['enabled'], 'tx_nrllm_a2a_client', ['uid' => $foreign])->fetchOne(), (string) $foreign);
        }

        $this->setUpBackendUser(1);
        self::assertSame(200, $this->controller()->revokeAction($this->postTo('revoke', ['uid' => $theirs]))->getStatusCode());
        $row = $this->db('tx_nrllm_a2a_client')->select(['enabled', 'token_hash'], 'tx_nrllm_a2a_client', ['uid' => $theirs])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame([0, ''], [(int) $row['enabled'], $row['token_hash']]);

        $this->setUpBackendUser(24);
        self::assertSame(403, $this->controller()->revokeAction($this->postTo('revoke', ['uid' => 1]))->getStatusCode());
    }

    #[Test]
    public function aDisabledClientGetsNoTokenUnlessTheAdministratorEnablesItWithTheIssuing(): void
    {
        $this->setUpBackendUser(1);
        self::assertSame(200, $this->controller()->revokeAction($this->postTo('revoke', ['uid' => 1]))->getStatusCode());

        // A token for a disabled record would be refused with 401 at once.
        foreach ([[], ['enable' => '0'], ['enable' => 'yes'], ['enable' => '1.0']] as $extra) {
            $refused = $this->controller()->issueAction($this->postTo('issue', ['uid' => 1] + $extra));
            $payload = $this->payload($refused);
            self::assertSame(422, $refused->getStatusCode(), json_encode($extra, JSON_THROW_ON_ERROR));
            self::assertArrayNotHasKey('token', $payload);
            self::assertIsString($payload['error']);
            self::assertStringContainsString('disabled', $payload['error']);
        }

        $row = $this->db('tx_nrllm_a2a_client')->select(['enabled', 'token_hash'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame([0, ''], [(int) $row['enabled'], $row['token_hash']], 'a refused issuing changes nothing');

        $response = $this->controller()->issueAction($this->postTo('issue', ['uid' => 1, 'enable' => '1']));
        $payload  = $this->payload($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($payload['token']);
        $row = $this->db('tx_nrllm_a2a_client')->select(['enabled', 'token_hash'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame([1, hash('sha256', $payload['token'])], [(int) $row['enabled'], $row['token_hash']]);

        $request = (new ServerRequest('https://typo3-testing.local/a2a', 'POST'))->withHeader('Authorization', 'Bearer ' . $payload['token']);
        self::assertSame(1, $this->getService(A2aAuthenticator::class)->authenticate($request, time())->uid, 'the new token authenticates');
    }

    #[Test]
    public function aDisabledClientWithoutAUsableTechnicalUserIsNotEnabled(): void
    {
        $this->setUpBackendUser(1);
        self::assertSame(200, $this->controller()->revokeAction($this->postTo('revoke', ['uid' => 1]))->getStatusCode());

        foreach ([0, 1] as $user) {
            // No user, then an administrator: the record's save hook keeps such a record disabled too.
            $this->db('tx_nrllm_a2a_client')->update('tx_nrllm_a2a_client', ['be_user' => $user], ['uid' => 1]);

            $refused = $this->controller()->issueAction($this->postTo('issue', ['uid' => 1, 'enable' => '1']));
            $payload = $this->payload($refused);
            self::assertSame(422, $refused->getStatusCode(), (string) $user);
            self::assertArrayNotHasKey('token', $payload);
            self::assertIsString($payload['error']);
            self::assertStringContainsString('technical backend user', $payload['error']);

            $row = $this->db('tx_nrllm_a2a_client')->select(['enabled', 'token_hash'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchAssociative();
            self::assertIsArray($row);
            self::assertSame([0, ''], [(int) $row['enabled'], $row['token_hash']], (string) $user);
        }
    }

    #[Test]
    public function aRecordIdentifierThatIsNotAWholeNumberNamesNoRecord(): void
    {
        $this->setUpBackendUser(1);
        $before = $this->db('tx_nrllm_a2a_client')->select(['enabled', 'token_hash'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchAssociative();

        foreach (['1.9', '1e0', ' 1x', '-1', '0', '', '0x1'] as $uid) {
            self::assertSame(422, $this->controller()->issueAction($this->postTo('issue', ['uid' => $uid]))->getStatusCode(), 'issue ' . $uid);
            self::assertSame(422, $this->controller()->revokeAction($this->postTo('revoke', ['uid' => $uid]))->getStatusCode(), 'revoke ' . $uid);
        }

        self::assertSame($before, $this->db('tx_nrllm_a2a_client')->select(['enabled', 'token_hash'], 'tx_nrllm_a2a_client', ['uid' => 1])->fetchAssociative(), 'client 1 was neither rotated nor revoked');

        // The same value as a whole number does act on the record.
        self::assertSame(200, $this->controller()->issueAction($this->postTo('issue', ['uid' => '1']))->getStatusCode());
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
    private function postTo(string $action, array $body): ServerRequest
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
        return $this->db($table)->count('*', $table, []);
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a\Server;

use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aClientTokenAdministration;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClient;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aInboundClientRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aResumeGuard;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSelfServicePolicy;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSelfServiceTokens;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSendMessageHandler;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aServerRecordSaveHook;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfileRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aTokenAudit;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aTokenRefusedException;
use Netresearch\NrLlm\Service\Agent\A2a\Task\A2aTaskStore;
use Netresearch\NrLlm\Service\Tool\AgentRunHandle;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Stringable;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The limits of a self-service A2A token (ADR-294), at the two points that
 * enforce them and in both directions: the writer refuses what the groups of
 * the owner do not allow, and the endpoint stops a stored token the moment the
 * groups stop allowing it, then serves it again when they allow it again.
 *
 * Installation: `A2aServerFixture` (users 1 and 7, profiles 1 `summary`, 2
 * `check`, 3 `internal`, clients 1 to 4) plus group 30 holding the grant with
 * profiles 1 and 2, editors 20 and 21 in it, and draft workspace 1.
 */
#[CoversClass(A2aSelfServicePolicy::class)]
#[CoversClass(A2aSelfServiceTokens::class)]
#[CoversClass(A2aClientTokenAdministration::class)]
#[CoversClass(A2aInboundClientRepository::class)]
#[CoversClass(A2aResumeGuard::class)]
#[CoversClass(A2aServerRecordSaveHook::class)]
final class A2aSelfServiceEnforcementTest extends AbstractFunctionalTestCase
{
    use A2aServerFixture;
    use A2aSelfServiceFixture;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'seo', 'workspaces'];

    private const DAY = 86400;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServer();

        $this->setUpSelfService();
    }

    protected function tearDown(): void
    {
        $this->serverSettings(null);
        $this->getService(CacheManager::class)->getCache('ratelimiter')->flush();
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function aTokenIssuedByAnEditorActsAsTheEditorAndIsOwnedByItsRecord(): void
    {
        $uid = $this->issue(20);

        $row = $this->db('tx_nrllm_a2a_client')->select(['*'], 'tx_nrllm_a2a_client', ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);
        self::assertSame([20, 1, 1, 1, 4, '1'], [(int) $row['be_user'], (int) $row['self_service'], (int) $row['workspace'], (int) $row['enabled'], (int) $row['task_limit'], $row['profiles']]);
        self::assertStringNotContainsString($this->tokens[$uid], json_encode($row, JSON_THROW_ON_ERROR));

        $task = $this->taskOf($this->send($uid));
        self::assertSame($uid, (int) $this->db(A2aTaskStore::TABLE)->select(['owner_client_uid'], A2aTaskStore::TABLE, ['task_id' => $task['id']])->fetchOne());
    }

    #[Test]
    public function aTokenLosesItsRightsWhenItsGroupLosesThePermissionAndRegainsThemWithIt(): void
    {
        $uid = $this->issue(20);
        self::assertTrue($this->accepted($this->send($uid)));

        $this->group(['custom_options' => '']);
        self::assertSame(401, $this->errorOf($this->send($uid))['http']);

        $this->group(['custom_options' => self::GRANT]);
        self::assertTrue($this->accepted($this->send($uid)));
    }

    #[Test]
    public function theLiveWorkspaceNeedsAGroupThatAllowsIt(): void
    {
        $this->expectRefusal(fn () => $this->issue(20, workspace: 0), 'live');

        $this->group(['tx_nrllm_a2a_allow_live' => 1]);
        $uid = $this->issue(20, workspace: 0);
        self::assertTrue($this->accepted($this->send($uid)));

        $this->group(['tx_nrllm_a2a_allow_live' => 0]);
        self::assertSame(401, $this->errorOf($this->send($uid))['http'], 'allowed at the start, withdrawn when the group stops allowing it');

        $this->group(['tx_nrllm_a2a_allow_live' => 1]);
        self::assertTrue($this->accepted($this->send($uid)));
    }

    #[Test]
    public function anExpiryBeyondTheMaximumIsRefusedAndOneWithinItIsStored(): void
    {
        $this->expectRefusal(fn () => $this->issue(20, days: 91), 'expiry');
        $this->expectRefusal(fn () => $this->issue(20, days: 0), 'no expiry');

        self::assertGreaterThan(0, $this->issue(20, days: 90));

        $this->group(['tx_nrllm_a2a_max_expiry_days' => 365]);
        self::assertGreaterThan(0, $this->issue(20, days: 200));
    }

    #[Test]
    public function aTokenWithoutExpiryNeedsAnExplicitChoiceOfTheGroup(): void
    {
        $this->group(['tx_nrllm_a2a_allow_no_expiry' => 1]);
        $uid = $this->issue(20, days: 0);
        self::assertTrue($this->accepted($this->send($uid)));

        $this->group(['tx_nrllm_a2a_allow_no_expiry' => 0]);
        self::assertSame(401, $this->errorOf($this->send($uid))['http']);
    }

    #[Test]
    public function loweringTheMaximumStopsATokenThatNowReachesBeyondItAndRaisingItRestoresIt(): void
    {
        $uid = $this->issue(20, days: 60);
        self::assertTrue($this->accepted($this->send($uid)));

        $this->group(['tx_nrllm_a2a_max_expiry_days' => 30]);
        self::assertSame(401, $this->errorOf($this->send($uid))['http']);

        $this->group(['tx_nrllm_a2a_max_expiry_days' => 90]);
        self::assertTrue($this->accepted($this->send($uid)));
    }

    #[Test]
    public function onlyTheProfilesTheGroupAllowsCanBeRequested(): void
    {
        $this->expectRefusal(fn () => $this->issue(20, profiles: [3]), 'a profile that is not allowed');
        $this->expectRefusal(fn () => $this->issue(20, profiles: [1, 3]), 'one profile that is not allowed among allowed ones');
        $this->expectRefusal(fn () => $this->issue(20, profiles: []), 'no profile');

        self::assertGreaterThan(0, $this->issue(20, profiles: [1, 2]));
    }

    #[Test]
    public function aProfileTheGroupNoLongerAllowsIsTakenOutOfTheTokenAndTheRestStaysCallable(): void
    {
        $uid = $this->issue(20, profiles: [1, 2]);
        self::assertTrue($this->accepted($this->send($uid, 'summary')));

        $this->group(['tx_nrllm_a2a_profiles' => '2']);
        self::assertSame('PROFILE_NOT_ALLOWED', $this->errorOf($this->send($uid, 'summary'))['reason']);
        self::assertTrue($this->accepted($this->send($uid, 'check')));

        $this->group(['tx_nrllm_a2a_profiles' => '1,2']);
        self::assertTrue($this->accepted($this->send($uid, 'summary')));
    }

    #[Test]
    public function aGrantOnASubgroupCountsLikeTheCoreCountsIt(): void
    {
        $this->db('be_groups')->insert('be_groups', ['uid' => 31, 'pid' => 0, 'title' => 'Parent', 'subgroup' => '30']);
        $this->db('be_users')->insert('be_users', ['uid' => 22, 'username' => 'via-subgroup', 'admin' => 0, 'usergroup' => '31', 'workspace_perms' => 3]);
        $this->db('sys_workspace')->update('sys_workspace', ['members' => 'be_users_20,be_users_21,be_users_22'], ['uid' => 1]);

        $uid = $this->issue(22);
        self::assertTrue($this->accepted($this->send($uid)));

        $this->group(['custom_options' => '']);
        self::assertSame(401, $this->errorOf($this->send($uid))['http']);
    }

    #[Test]
    public function anAdministratorAndAUserWithoutTheGrantAreRefused(): void
    {
        $this->expectRefusal(fn () => $this->issue(1), 'an administrator');

        // Group 32 has every relaxation but not the grant: it contributes nothing.
        $this->expectRefusal(fn () => $this->issue(24), 'no grant');
    }

    #[Test]
    public function aWorkspaceTheOwnerCannotWorkInIsRefusedAndWithdrawsAStoredToken(): void
    {
        $this->expectRefusal(fn () => $this->issue(20, workspace: 99), 'a workspace that does not exist');

        $uid = $this->issue(20);
        $this->db('sys_workspace')->update('sys_workspace', ['members' => 'be_users_21'], ['uid' => 1]);
        self::assertSame(401, $this->errorOf($this->send($uid))['http']);

        $this->db('sys_workspace')->update('sys_workspace', ['members' => 'be_users_20,be_users_21'], ['uid' => 1]);
        self::assertTrue($this->accepted($this->send($uid)));
    }

    #[Test]
    public function anOwnerWhoBecomesAnAdministratorCanNoLongerBeTheActingUser(): void
    {
        $uid = $this->issue(20);
        self::assertTrue($this->accepted($this->send($uid)));

        $this->db('be_users')->update('be_users', ['admin' => 1], ['uid' => 20]);
        self::assertSame(401, $this->errorOf($this->send($uid))['http']);

        $this->db('be_users')->update('be_users', ['admin' => 0], ['uid' => 20]);
        self::assertTrue($this->accepted($this->send($uid)));
    }

    #[Test]
    public function anEditorSeesAndRevokesOnlyTheirOwnTokens(): void
    {
        $mine   = $this->issue(20);
        $theirs = $this->issue(21);
        $tokens = $this->getService(A2aSelfServiceTokens::class);

        self::assertSame([$mine], array_map(static fn (A2aInboundClient $c): int => $c->uid, $tokens->listOwn($this->user(20))));
        self::assertSame([$theirs], array_map(static fn (A2aInboundClient $c): int => $c->uid, $tokens->listOwn($this->user(21))));

        foreach ([$theirs, 1] as $foreign) {
            try {
                $tokens->revokeOwn($this->user(20), $foreign);
                self::fail('record ' . $foreign . ' is not the editor\'s');
            } catch (A2aTokenRefusedException $exception) {
                self::assertSame('There is no such token.', $exception->getMessage(), 'not yours answers like missing');
            }
        }

        self::assertTrue($this->accepted($this->send($theirs)), 'the other editor\'s token still works');
        self::assertTrue($this->accepted($this->send(1)), 'and so does a technical client');

        $tokens->revokeOwn($this->user(20), $mine);
        self::assertSame(401, $this->errorOf($this->send($mine))['http']);
        self::assertTrue($this->accepted($this->send($theirs)));
    }

    #[Test]
    public function anAdministratorSeesEveryTokenRevokesAnyAndRotatesOnlyTechnicalOnes(): void
    {
        $self          = $this->issue(20);
        $admin         = $this->getService(A2aClientTokenAdministration::class);
        $administrator = $this->user(1);

        $all = array_map(static fn (A2aInboundClient $c): int => $c->uid, $admin->listAll($administrator));
        self::assertContains($self, $all);
        self::assertContains(1, $all);

        try {
            $admin->issue($administrator, $self, time());
            self::fail('an administrator must not mint a credential that acts as an editor');
        } catch (A2aTokenRefusedException $exception) {
            self::assertStringContainsString('owner', $exception->getMessage());
        }

        $old   = $this->tokens[1];
        $fresh = $admin->issue($administrator, 1, time());
        self::assertNotSame($old, $fresh->token);
        $this->tokens[1] = $fresh->token;
        self::assertTrue($this->accepted($this->send(1)));
        $this->tokens[9] = $old;
        self::assertSame(401, $this->errorOf($this->send(9))['http'], 'the rotated token is dead');

        $admin->revoke($administrator, $self);
        self::assertSame(401, $this->errorOf($this->send($self))['http']);
    }

    #[Test]
    public function aNonAdministratorCannotUseTheAdministratorOperations(): void
    {
        $admin  = $this->getService(A2aClientTokenAdministration::class);
        $editor = $this->user(20);

        foreach ([
            'issue'   => fn () => $admin->issue($editor, 1, time()),
            'listAll' => fn () => $admin->listAll($editor),
            'revoke'  => fn () => $admin->revoke($editor, 1),
        ] as $name => $call) {
            $this->expectRefusal($call, $name);
        }

        self::assertTrue($this->accepted($this->send(1)), 'nothing was changed');
    }

    #[Test]
    public function aStartOrResumeOfARunIsRefusedWhenTheOwnerHasLostTheGrant(): void
    {
        $uid    = $this->issue(20);
        $task   = $this->taskOf($this->send($uid));
        $runUid = $this->db(A2aTaskStore::TABLE)->select(['run_uid'], A2aTaskStore::TABLE, ['task_id' => $task['id']])->fetchOne();
        $uuid   = $this->db('tx_nrllm_agentrun')->select(['uuid'], 'tx_nrllm_agentrun', ['uid' => $runUid])->fetchOne();
        self::assertIsString($uuid);
        self::assertIsNumeric($runUid);
        $handle = new AgentRunHandle((int) $runUid, $uuid);
        $owner  = AiActorContext::backendUser(20, false, [], [], 1);
        $guard  = $this->getService(A2aResumeGuard::class);

        self::assertNull($guard->startRefusal($handle, $owner, []));

        $this->group(['custom_options' => '']);
        $refusal = $guard->startRefusal($handle, $owner, []);
        self::assertIsString($refusal);
        self::assertStringContainsString('withdrawn', $refusal);

        $this->group(['custom_options' => self::GRANT]);
        self::assertNull($guard->startRefusal($handle, $owner, []));
    }

    #[Test]
    public function noFormWriteCanSetTheSelfServiceFlag(): void
    {
        $hook = $this->getService(A2aServerRecordSaveHook::class);

        foreach (['update' => 1, 'new' => 'NEW1'] as $status => $id) {
            $fields = ['title' => 'x', 'be_user' => 7, 'self_service' => 1, 'token_hash' => 'aa'];
            $hook->processDatamap_postProcessFieldArray($status, 'tx_nrllm_a2a_client', $id, $fields, GeneralUtility::makeInstance(DataHandler::class));

            self::assertArrayNotHasKey('self_service', $fields, $status);
            self::assertArrayNotHasKey('token_hash', $fields, $status);
            self::assertSame('x', $fields['title']);
        }
    }

    #[Test]
    public function aTechnicalUserRecordIsReadAsStored(): void
    {
        $repository = $this->getService(A2aInboundClientRepository::class);

        self::assertEquals($repository->findRawByUid(1), $repository->findByUid(1));
        self::assertEquals($repository->findRawByUid(2), $repository->findByUid(2));
    }

    #[Test]
    public function everyIssuingIsLoggedWithWhoAndWhichRecordAndNeverWithTheToken(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->lines[] = $message . ' ' . json_encode($context);
            }
        };
        $audit  = new A2aTokenAudit($logger);
        $tokens = new A2aSelfServiceTokens($this->getService(A2aInboundClientRepository::class), $this->getService(A2aSelfServicePolicy::class), $this->getService(A2aSkillProfileRepository::class), $audit);
        $now    = time();
        $issued = $tokens->create($this->user(20), 'Agent', 1, [1], $now + 30 * self::DAY, $now);
        $tokens->revokeOwn($this->user(20), $issued->clientUid);
        (new A2aClientTokenAdministration($this->getService(A2aInboundClientRepository::class), $audit))->issue($this->user(1), 1, $now);

        self::assertCount(3, $logger->lines);
        self::assertStringContainsString('"client":' . $issued->clientUid, $logger->lines[0]);
        self::assertStringContainsString('"actor":20', $logger->lines[0]);
        self::assertStringContainsString('"actor":1', $logger->lines[2]);
        foreach ($logger->lines as $line) {
            self::assertStringNotContainsString($issued->token, $line);
            self::assertStringNotContainsString(hash('sha256', $issued->token), $line);
            self::assertStringNotContainsString('nra2a_', $line);
        }
    }

    // --- helpers -------------------------------------------------------

    /**
     * Issue a token for an editor and remember it as `$this->tokens[uid]`.
     *
     * @param list<int> $profiles
     */
    private function issue(int $owner, int $workspace = 1, int $days = 30, array $profiles = [1]): int
    {
        $now                              = time();
        $issued                           = $this->getService(A2aSelfServiceTokens::class)->create($this->user($owner), 'Agent of ' . $owner, $workspace, $profiles, $days > 0 ? $now + $days * self::DAY : 0, $now);
        $this->tokens[$issued->clientUid] = $issued->token;

        return $issued->clientUid;
    }

    private function user(int $uid): BackendUserAuthentication
    {
        return $this->setUpBackendUser($uid);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function group(array $fields): void
    {
        $this->db('be_groups')->update('be_groups', $fields, ['uid' => 30]);
    }

    /**
     * @return array<string, mixed>
     */
    private function send(int $client, string $skill = 'summary'): array
    {
        return $this->call('SendMessage', ['message' => $this->message(), 'metadata' => [A2aSendMessageHandler::PROFILE_EXTENSION => $skill], 'configuration' => ['returnImmediately' => true]], $client);
    }

    /**
     * @param array<string, mixed> $reply
     */
    private function accepted(array $reply): bool
    {
        return is_array($reply['result'] ?? null);
    }

    private function expectRefusal(callable $call, string $case): void
    {
        try {
            $call();
        } catch (A2aTokenRefusedException) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail('The request was not refused: ' . $case);
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Agent\A2a;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgent;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentRepository;
use Netresearch\NrLlm\Service\Agent\A2a\Agent\A2aRemoteAgentSaveHook;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The remote agent record (spec 051 A2A-C1) and the confirming save (A2A-C2).
 */
#[CoversClass(A2aRemoteAgent::class)]
#[CoversClass(A2aRemoteAgentRepository::class)]
#[CoversClass(A2aRemoteAgentSaveHook::class)]
final class A2aRemoteAgentRepositoryTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_a2a_agent';

    private bool $usersImported = false;

    #[Test]
    public function aRowWithNothingButItsNameHasTheSafeDefaults(): void
    {
        $this->insertAgent(['identifier' => 'bare', 'title' => 'Bare']);

        $agent = $this->repository()->findByReference('bare');

        self::assertInstanceOf(A2aRemoteAgent::class, $agent);
        self::assertFalse($agent->isEnabled(), 'enabled defaults to 0');
        self::assertTrue($agent->requiresApproval(), 'requires_approval defaults to 1');
        self::assertNull($agent->getDataClass(), 'data_class has no default');
        self::assertNull($agent->getCardFetched(), 'a card never fetched has no fetch time, not 0');
        self::assertSame('', $agent->getInterfaceUrl());
        self::assertSame('', $agent->getCredential());
        self::assertSame(5, $agent->getConnectTimeoutSeconds());
        self::assertSame(30, $agent->getReadTimeoutSeconds());
        self::assertSame(1048576, $agent->getMaxResponseBytes());
        self::assertSame(3600, $agent->getMaxWaitSeconds());
        self::assertSame(5, $agent->getMinPollIntervalSeconds());
        self::assertSame(300, $agent->getMaxPollIntervalSeconds());
        self::assertSame(3, $agent->getMaxDelegationsPerRun());
    }

    #[Test]
    public function theFormDefaultsMatchTheSchema(): void
    {
        $tca = $GLOBALS['TCA'] ?? null;
        self::assertIsArray($tca);
        $table = $tca[self::TABLE] ?? null;
        self::assertIsArray($table);
        /** @var array<string, array{config: array<string, mixed>}> $columns */
        $columns   = $table['columns'];
        $dataClass = $columns['data_class']['config'];
        $items     = $dataClass['items'] ?? null;
        self::assertIsArray($items);
        $first = $items[0] ?? null;
        self::assertIsArray($first);

        self::assertSame(0, $columns['enabled']['config']['default'] ?? null);
        self::assertSame(1, $columns['requires_approval']['config']['default'] ?? null);
        self::assertArrayNotHasKey('default', $dataClass);
        self::assertSame('', $first['value'] ?? null);
        self::assertTrue($columns['card_changed']['config']['readOnly'] ?? null);
    }

    #[Test]
    public function aRecordWithoutDataClassDoesNothing(): void
    {
        $this->insertAgent(['identifier' => 'nodc', 'title' => 'x', 'enabled' => 1, 'interface_url' => 'https://a.example/rpc']);

        $agent = $this->repository()->findByReference('nodc');

        self::assertNotNull($agent);
        self::assertStringContainsString('no data class', (string) $agent->importRefusal());
        self::assertStringContainsString('no data class', (string) $agent->useRefusal());
    }

    #[Test]
    public function aRecordIsUsableOnlyEnabledWithAnImportedUnchangedCard(): void
    {
        $base = ['title' => 'x', 'data_class' => ToolDataClass::PUBLIC_CONTENT->value];
        $base += ['card_url' => 'https://a.example/.well-known/agent-card.json'];
        $this->insertAgent($base + ['identifier' => 'off', 'interface_url' => 'https://a.example/rpc']);
        $this->insertAgent($base + ['identifier' => 'nocard', 'enabled' => 1]);
        $this->insertAgent($base + ['identifier' => 'changed', 'enabled' => 1, 'interface_url' => 'https://a.example/rpc', 'card_changed' => 1]);
        $this->insertAgent($base + ['identifier' => 'ok', 'enabled' => 1, 'interface_url' => 'https://a.example/rpc']);

        self::assertStringContainsString('not enabled', (string) $this->repository()->findByReference('off')?->useRefusal());
        self::assertStringContainsString('not been imported', (string) $this->repository()->findByReference('nocard')?->useRefusal());
        self::assertStringContainsString('save the record again', (string) $this->repository()->findByReference('changed')?->useRefusal());
        self::assertNull($this->repository()->findByReference('ok')?->useRefusal());
        self::assertNull($this->repository()->findByReference('off')?->importRefusal(), 'a disabled record may import its card');
    }

    #[Test]
    public function aRecordIsFoundByUidAsWellAsByIdentifier(): void
    {
        $uid = $this->insertAgent(['identifier' => 'byuid', 'title' => 'x']);

        self::assertSame('byuid', $this->repository()->findByReference((string) $uid)?->getIdentifier());
        self::assertNull($this->repository()->findByReference('missing'));
    }

    #[Test]
    public function anInterfaceOffTheCardUrlsOriginIsUsedOnlyWhenConfirmed(): void
    {
        $base = ['title' => 'x', 'data_class' => ToolDataClass::PUBLIC_CONTENT->value, 'enabled' => 1, 'card_url' => 'https://a.example/card.json', 'interface_url' => 'https://b.example/rpc'];
        $this->insertAgent($base + ['identifier' => 'unconfirmed']);
        $this->insertAgent($base + ['identifier' => 'other', 'confirmed_interface' => 'https://c.example/rpc']);
        $this->insertAgent($base + ['identifier' => 'confirmed', 'confirmed_interface' => 'https://b.example/rpc']);

        self::assertStringContainsString('was not confirmed', (string) $this->repository()->findByReference('unconfirmed')?->useRefusal());
        self::assertStringContainsString('was not confirmed', (string) $this->repository()->findByReference('other')?->useRefusal());
        self::assertNull($this->repository()->findByReference('confirmed')?->useRefusal());
    }

    #[Test]
    public function anInterfaceUrlWithoutACertainOriginIsNeverUsedEvenWhenConfirmed(): void
    {
        $url = 'https://agent.example@evil.example/rpc';
        $this->insertAgent(['identifier' => 'userinfo', 'title' => 'x', 'data_class' => ToolDataClass::PUBLIC_CONTENT->value, 'enabled' => 1, 'card_url' => 'https://agent.example/card.json', 'interface_url' => $url, 'confirmed_interface' => $url]);

        self::assertStringContainsString('not a plain http or https URL', (string) $this->repository()->findByReference('userinfo')?->useRefusal());
    }

    #[Test]
    public function anOrdinarySaveDoesNotConfirmAChangedCard(): void
    {
        $uid = $this->insertAgent(['identifier' => 'save', 'title' => 'Before', 'interface_url' => 'https://a.example/rpc', 'card_changed' => 1]);

        $this->process($uid, ['title' => 'After']);

        self::assertSame(['title' => 'After', 'card_changed' => 1, 'confirmed_interface' => ''], $this->row($uid, ['title', 'card_changed', 'confirmed_interface']));
    }

    #[Test]
    public function anAdministratorConfirmsTheCardTheFormShowed(): void
    {
        $uid = $this->insertAgent(['identifier' => 'confirm', 'title' => 'x', 'interface_url' => 'https://b.example/rpc', 'interface_tenant' => 't', 'card_capabilities' => 'streaming', 'card_changed' => 1]);

        $this->process($uid, ['card_confirm' => A2aRemoteAgent::cardDigest('https://b.example/rpc', 't', 'streaming')]);

        self::assertSame(
            ['card_changed' => 0, 'card_confirm' => '', 'confirmed_interface' => 'https://b.example/rpc'],
            $this->row($uid, ['card_changed', 'card_confirm', 'confirmed_interface']),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cardsTheFormDidNotShow(): iterable
    {
        yield 'another interface URL' => ['https://c.example/rpc', 't', 'streaming'];
        yield 'another tenant' => ['https://b.example/rpc', 'u', 'streaming'];
        yield 'other capabilities' => ['https://b.example/rpc', 't', 'push'];
    }

    #[Test]
    #[DataProvider('cardsTheFormDidNotShow')]
    public function aConfirmationOfACardThatChangedSinceTheFormOpenedConfirmsNothing(string $url, string $tenant, string $capabilities): void
    {
        $uid = $this->insertAgent(['identifier' => 'stale', 'title' => 'x', 'interface_url' => 'https://b.example/rpc', 'interface_tenant' => 't', 'card_capabilities' => 'streaming', 'card_changed' => 1]);

        $handler = $this->process($uid, ['card_confirm' => A2aRemoteAgent::cardDigest($url, $tenant, $capabilities)], false);

        self::assertSame(['card_changed' => 1, 'confirmed_interface' => ''], $this->row($uid, ['card_changed', 'confirmed_interface']));
        self::assertStringContainsString('changed after the form was opened', implode("\n", $handler->errorLog));
    }

    #[Test]
    public function theCommandLineUserCannotConfirm(): void
    {
        $uid = $this->insertAgent(['identifier' => 'cli', 'title' => 'x', 'interface_url' => 'https://b.example/rpc', 'card_changed' => 1]);
        $this->importFixture('BeUsers.csv');
        $this->usersImported                = true;
        $handler                            = GeneralUtility::makeInstance(DataHandler::class);
        $handler->BE_USER                   = $this->setUpBackendUser(1);
        $handler->BE_USER->user['username'] = '_cli_';

        $fields = ['card_confirm' => A2aRemoteAgent::cardDigest('https://b.example/rpc', '', '')];
        (new A2aRemoteAgentSaveHook())->processDatamap_postProcessFieldArray('update', self::TABLE, $uid, $fields, $handler);

        self::assertSame(['card_confirm' => ''], $fields);
    }

    #[Test]
    public function aUserWhoIsNoAdministratorCannotConfirm(): void
    {
        $uid = $this->insertAgent(['identifier' => 'editor', 'title' => 'x', 'interface_url' => 'https://b.example/rpc', 'card_changed' => 1]);
        $this->importFixture('BeUsers.csv');
        $this->usersImported = true;
        $handler             = GeneralUtility::makeInstance(DataHandler::class);
        $handler->BE_USER    = $this->setUpBackendUser(2);

        $fields = ['card_confirm' => A2aRemoteAgent::cardDigest('https://b.example/rpc', '', '')];
        (new A2aRemoteAgentSaveHook())->processDatamap_postProcessFieldArray('update', self::TABLE, $uid, $fields, $handler);

        self::assertSame(['card_confirm' => ''], $fields);
    }

    #[Test]
    public function aUserWhoIsNoAdministratorCannotEditTheRecordAtAll(): void
    {
        // An editor whose group may read and modify the table, on a folder the
        // editor owns: only adminOnly stands between this user and the record.
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Agents', 'doktype' => 254, 'perms_userid' => 2, 'perms_user' => 31, 'perms_everybody' => 31,
        ]);
        $uid = $this->insertAgent(['pid' => 1, 'identifier' => 'locked', 'title' => 'x', 'card_url' => 'https://a.example/card.json', 'interface_url' => 'https://a.example/rpc']);
        $this->importFixture('BeUsers.csv');
        $this->getConnectionPool()->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10, 'pid' => 0, 'title' => 'A2A editors', 'tables_select' => self::TABLE, 'tables_modify' => self::TABLE, 'db_mountpoints' => '1',
        ]);
        $this->getConnectionPool()->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '10'], ['uid' => 2]);
        $editor          = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($editor);

        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([self::TABLE => [$uid => ['card_url' => 'https://evil.example/card.json', 'title' => 'y']]], []);
        $handler->process_datamap();

        self::assertNotSame([], $handler->errorLog);
        self::assertSame(['card_url' => 'https://a.example/card.json', 'title' => 'x', 'interface_url' => 'https://a.example/rpc'], $this->row($uid, ['card_url', 'title', 'interface_url']));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function submittedCredentials(): iterable
    {
        yield 'a new secret for the same identifier' => [['value' => 'another-secret', '_vault_identifier' => 'cred-a', '_vault_checksum' => 'abc'], true];
        yield 'a cleared secret' => [['value' => '', '_vault_identifier' => 'cred-a', '_vault_checksum' => ''], true];
        yield 'a plain new value' => ['another-secret', true];
        yield 'the field untouched' => [['value' => '', '_vault_identifier' => 'cred-a', '_vault_checksum' => 'abc'], false];
    }

    #[Test]
    #[DataProvider('submittedCredentials')]
    public function aNewSecretValueUnderTheSameIdentifierDropsTheCardToo(mixed $submitted, bool $dropped): void
    {
        $uid = $this->insertAgent(['identifier' => 'rot', 'title' => 'x', 'credential' => 'cred-a', 'interface_url' => 'https://a.example/rpc', 'confirmed_interface' => 'https://a.example/rpc']);
        $this->importFixture('BeUsers.csv');
        $this->usersImported = true;
        $handler             = GeneralUtility::makeInstance(DataHandler::class);
        $handler->BE_USER    = $this->setUpBackendUser(1);
        $handler->datamap    = [self::TABLE => [$uid => ['credential' => $submitted]]];

        // What nr-vault leaves for the field: the same identifier, so the
        // field does not reach this hook as a change.
        $fields = ['title' => 'x'];
        (new A2aRemoteAgentSaveHook())->processDatamap_postProcessFieldArray('update', self::TABLE, $uid, $fields, $handler);

        self::assertSame($dropped, ($fields['interface_url'] ?? null) === '');
    }

    #[Test]
    public function aBackendWriteCannotSetTheFieldsTheImportOwns(): void
    {
        $uid = $this->insertAgent(['identifier' => 'ro', 'title' => 'x']);

        $this->process($uid, ['card_changed' => 1, 'interface_url' => 'https://evil.example/rpc', 'confirmed_interface' => 'https://evil.example/rpc', 'card_capabilities' => 'x']);

        self::assertSame(
            ['card_changed' => 0, 'interface_url' => '', 'confirmed_interface' => '', 'card_capabilities' => ''],
            $this->row($uid, ['card_changed', 'interface_url', 'confirmed_interface', 'card_capabilities']),
        );
    }

    #[Test]
    public function changingTheCardUrlOrTheCredentialDropsTheImportedCard(): void
    {
        $imported = ['title' => 'x', 'data_class' => ToolDataClass::PUBLIC_CONTENT->value, 'enabled' => 1, 'card_url' => 'https://a.example/card.json', 'credential' => 'cred-a', 'card_json' => '{}', 'interface_url' => 'https://a.example/rpc', 'interface_tenant' => 't', 'card_capabilities' => 'streaming', 'confirmed_interface' => 'https://a.example/rpc'];
        $byUrl    = $this->insertAgent($imported + ['identifier' => 'url']);
        $byCred   = $this->insertAgent($imported + ['identifier' => 'cred']);
        $byTitle  = $this->insertAgent($imported + ['identifier' => 'title']);

        $this->process($byUrl, ['card_url' => 'https://b.example/card.json']);
        $this->process($byCred, ['credential' => 'cred-b']);
        $this->process($byTitle, ['title' => 'y']);

        $dropped = ['card_json' => '', 'interface_url' => '', 'interface_tenant' => '', 'card_capabilities' => '', 'confirmed_interface' => ''];
        $fields  = array_keys($dropped);
        self::assertSame($dropped, $this->row($byUrl, $fields));
        self::assertSame($dropped, $this->row($byCred, $fields));
        self::assertSame('https://a.example/rpc', $this->row($byTitle, ['interface_url'])['interface_url'], 'another field changes nothing');
        self::assertStringContainsString('not been imported', (string) $this->repository()->findByReference('url')?->useRefusal());
    }

    /**
     * @param array<string, int|string> $fields
     */
    private function process(int $uid, array $fields, bool $expectNoError = true): DataHandler
    {
        if (!$this->usersImported) {
            $this->importFixture('BeUsers.csv');
            $this->usersImported = true;
        }

        $admin           = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($admin);

        $handler = GeneralUtility::makeInstance(DataHandler::class);
        $handler->start([self::TABLE => [$uid => $fields]], []);
        $handler->process_datamap();

        if ($expectNoError) {
            self::assertSame([], $handler->errorLog);
        }

        return $handler;
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, int|string>
     */
    private function row(int $uid, array $fields): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable(self::TABLE)
            ->select($fields, self::TABLE, ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);

        $typed = [];
        foreach ($fields as $field) {
            $value         = $row[$field] ?? null;
            $text          = is_scalar($value) ? (string) $value : '';
            $typed[$field] = $field === 'card_changed' ? (int) $text : $text;
        }

        return $typed;
    }

    /**
     * @param array<string, int|string> $values
     */
    private function insertAgent(array $values): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, $values);

        return (int) $connection->lastInsertId();
    }

    private function repository(): A2aRemoteAgentRepository
    {
        return $this->getService(A2aRemoteAgentRepository::class);
    }
}

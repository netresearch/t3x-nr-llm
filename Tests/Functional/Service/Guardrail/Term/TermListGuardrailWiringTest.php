<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Guardrail\Term;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Exception\GuardrailViolationException;
use Netresearch\NrLlm\Provider\Middleware\GuardrailMiddleware;
use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
use Netresearch\NrLlm\Provider\Middleware\ProviderOperation;
use Netresearch\NrLlm\Service\Guardrail\GuardrailRegistry;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListGuardrail;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolver;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListResolverInterface;
use Netresearch\NrLlm\Service\Guardrail\Term\TermListRule;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Unit\Fixture\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * A term list against the real container, table and language files (ADR-238).
 */
#[CoversClass(TermListResolver::class)]
#[CoversClass(TermListGuardrail::class)]
final class TermListGuardrailWiringTest extends AbstractFunctionalTestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function theResolverReturnsTheVisibleListsNamingTheConfiguration(): void
    {
        $this->importFixture('TermLists.csv');

        $lists = $this->getService(TermListResolverInterface::class)->forConfiguration(1);

        self::assertSame(['Marke', 'Recht'], array_map(static fn (TermListRule $list): string => $list->name, $lists));
        self::assertSame(['Rabatt', 'Billigprodukt'], $lists[0]->forbiddenTerms);
        self::assertSame('Bitte anders formulieren.', $lists[0]->message);
        self::assertSame(['Impressum'], $lists[1]->requiredTerms);
        self::assertSame('shop', $lists[1]->siteIdentifier);
        self::assertSame('de', $lists[1]->language);
    }

    #[Test]
    public function theLanguageOfABackendUserIsReadFromItsRow(): void
    {
        $this->getService(ConnectionPool::class)->getConnectionForTable('be_users')->insert('be_users', ['uid' => 77, 'username' => 'redaktion', 'lang' => 'de']);

        self::assertSame('de', $this->getService(TermListResolverInterface::class)->languageOfUser(77));
        self::assertSame('', $this->getService(TermListResolverInterface::class)->languageOfUser(78));
    }

    #[Test]
    public function aConfigurationIsMatchedByItsWholeUid(): void
    {
        $this->importFixture('TermLists.csv');

        self::assertSame([], $this->getService(TermListResolverInterface::class)->forConfiguration(99));
        // List 5 names 12; a substring search would hand it to 1 and 2 as well.
        self::assertSame(
            ['Marke'],
            array_map(static fn (TermListRule $list): string => $list->name, $this->getService(TermListResolverInterface::class)->forConfiguration(2)),
        );
    }

    #[Test]
    public function aMissingTableReadsAsNoListWithAWarning(): void
    {
        $connectionPool = $this->getService(ConnectionPool::class);
        $connection     = $connectionPool->getConnectionForTable('tx_nrllm_term_list');
        $logger         = new RecordingLogger();

        // Renamed and put back: a database server keeps the schema across tests.
        $connection->executeStatement('ALTER TABLE tx_nrllm_term_list RENAME TO tx_nrllm_term_list_away');

        try {
            self::assertSame([], (new TermListResolver($connectionPool, $logger))->forConfiguration(1));
        } finally {
            $connection->executeStatement('ALTER TABLE tx_nrllm_term_list_away RENAME TO tx_nrllm_term_list');
        }

        self::assertNotNull($logger->firstMatching('warning', 'schema update'));
    }

    #[Test]
    public function theGuardrailIsCollectedAndCannotBeDeselected(): void
    {
        $registry = $this->getService(GuardrailRegistry::class);

        self::assertTrue($registry->isMandatoryIdentifier('term-list'));
        self::assertNotContains('term-list', $registry->selectableIdentifiers());
    }

    #[Test]
    public function aForbiddenTermIsBlockedInGermanAndRecordedDespiteAGuardrailSelection(): void
    {
        $this->importFixture('TermLists.csv');
        $this->importFixture('BeUsers.csv');
        $backendUser               = $this->setUpBackendUser(1);
        $backendUser->user['lang'] = 'de';
        $backendUser->uc['lang']   = 'de';
        $GLOBALS['LANG']           = $this->getService(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', 2);
        $configuration->setAllowedGuardrails('provider-content-filter');

        $context = ProviderCallContext::forConfiguration(ProviderOperation::Chat, $configuration);

        try {
            $this->getService(GuardrailMiddleware::class)->handle(
                $context,
                static fn (): CompletionResponse => new CompletionResponse('Heute ein RABATT für alle', 'm', UsageStatistics::fromTokens(1, 1)),
            );
            self::fail('The term list did not block.');
        } catch (GuardrailViolationException $blocked) {
            self::assertSame(
                'Dieser Text wurde nicht ausgegeben. Er enthält Begriffe, die die Liste „Marke“ nicht zulässt: Rabatt. Bitte anders formulieren.',
                $blocked->getMessage(),
            );
        }

        $detail = $this->getConnection()->fetchOne('SELECT detail FROM tx_nrllm_governance_event');
        self::assertIsString($detail);
        self::assertStringContainsString('Rabatt', $detail);
        self::assertStringNotContainsString('für alle', $detail);
    }

    #[Test]
    public function aConfigurationNoListNamesPassesUnchanged(): void
    {
        $this->importFixture('TermLists.csv');

        $configuration = new LlmConfiguration();
        $configuration->_setProperty('uid', 99);

        $response = new CompletionResponse('Rabatt', 'm', UsageStatistics::fromTokens(1, 1));

        $result = $this->getService(GuardrailMiddleware::class)->handle(
            ProviderCallContext::forConfiguration(ProviderOperation::Chat, $configuration),
            static fn (): CompletionResponse => $response,
        );

        self::assertInstanceOf(CompletionResponse::class, $result);
        self::assertSame('Rabatt', $result->content);
    }
}

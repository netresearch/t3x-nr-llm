<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\Builtin\CreateRedirectTool;
use Netresearch\NrLlm\Service\Tool\Builtin\DisableRedirectTool;
use Netresearch\NrLlm\Service\Tool\Builtin\ListRedirectsTool;
use Netresearch\NrLlm\Service\Tool\Builtin\UpdateRedirectTool;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\InterferesWithAnUpdateHook;
use Netresearch\NrLlm\Tests\Fixtures\DataHandler\RegistersTheInterferingHookTrait;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Redirects\Service\RedirectService;

/**
 * The redirect tools against a real database, the real DataHandler and the
 * real EXT:redirects (ADR-257): what an administrator approves is what
 * visitors get — the redirect service finds a created redirect, follows a
 * changed one and stops at a disabled one — and nobody else may do it.
 */
#[CoversClass(CreateRedirectTool::class)]
#[CoversClass(UpdateRedirectTool::class)]
#[CoversClass(DisableRedirectTool::class)]
#[CoversClass(ListRedirectsTool::class)]
final class RedirectToolsTest extends AbstractFunctionalTestCase
{
    use AssertsGermanPreviewTrait;
    use RegistersTheInterferingHookTrait;

    /** @var non-empty-string[] */
    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'frontend', 'redirects'];

    /**
     * EXT:redirects keeps the redirects of a host in the page cache, which the
     * test instance gives a null backend. Restored to core's database backend,
     * so "visitors get it at once" needs core's own cache rebuild to hold.
     *
     * @var array<string, mixed>
     */
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => ['pages' => ['backend' => Typo3DatabaseBackend::class]]]],
    ];

    private const EXISTING = 10;

    private const DISABLED = 11;

    private const PAGE = 1;

    private CreateRedirectTool $create;

    private UpdateRedirectTool $update;

    private DisableRedirectTool $disable;

    private ListRedirectsTool $list;

    protected function setUp(): void
    {
        parent::setUp();

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        $this->importFixture('BeUsers.csv');
        $connectionPool->getConnectionForTable('pages')->insert('pages', ['uid' => self::PAGE, 'pid' => 0, 'title' => 'Home', 'slug' => '/']);

        $redirects = $connectionPool->getConnectionForTable('sys_redirect');
        $redirects->insert('sys_redirect', [
            'uid'               => self::EXISTING, 'pid' => 0, 'source_host' => '*', 'source_path' => '/old', 'target' => '/new',
            'target_statuscode' => 301, 'disabled' => 0,
        ]);
        $redirects->insert('sys_redirect', [
            'uid'               => self::DISABLED, 'pid' => 0, 'source_host' => '*', 'source_path' => '/paused', 'target' => '/elsewhere',
            'target_statuscode' => 302, 'disabled' => 1,
        ]);

        $GLOBALS['LANG'] = $this->getService(LanguageServiceFactory::class)->create('default');

        $translator    = new ApprovalPreviewTranslator($this->getService(LanguageServiceFactory::class));
        $linkService   = $this->getService(LinkService::class);
        $this->create  = new CreateRedirectTool($connectionPool, $translator, $linkService);
        $this->update  = new UpdateRedirectTool($connectionPool, $translator, $linkService);
        $this->disable = new DisableRedirectTool($connectionPool, $translator, $linkService);
        $this->list    = new ListRedirectsTool($connectionPool);
    }

    protected function tearDown(): void
    {
        $this->unregisterInterferingHook();
        unset($GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function aCreatedRedirectIsFoundByTheRedirectServiceAtOnce(): void
    {
        // Warm the redirect cache first: a redirect that only lands in the
        // table is not followed until core's hook rebuilds the cache.
        self::assertNull($this->redirectService()->matchRedirect('www.example.com', '/summer-sale'));

        $result = $this->create->execute(
            ['source_path' => '/summer-sale', 'target' => 't3://page?uid=1', 'target_statuscode' => 302],
            $this->admin(),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame(WriteKind::CREATED, $result->writeKind);
        self::assertSame('sys_redirect', $result->writeTarget?->table);

        $match = $this->redirectService()->matchRedirect('www.example.com', '/summer-sale');
        self::assertIsArray($match);
        self::assertSame('t3://page?uid=1', $match['target'] ?? null);
        self::assertSame(302, (int) ($match['target_statuscode'] ?? 0));
        self::assertSame($result->writeTarget?->uid, (int) ($match['uid'] ?? 0));
    }

    #[Test]
    public function aNonAdministratorCreatesNothingAndIsToldNothing(): void
    {
        $context = ToolExecutionContext::fromBackendUser($this->setUpBackendUser(2));

        $result = $this->create->execute(['source_path' => '/x', 'target' => '/y'], $context);

        self::assertTrue($result->isError);
        self::assertSame('Redirect not found or not permitted.', $result->content);
        self::assertSame(['Redirect not found or not permitted.'], $this->create->previewCall(['source_path' => '/x', 'target' => '/y'], $context));
        self::assertSame(2, $this->redirectCount());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedRedirects(): iterable
    {
        yield 'the path of an existing redirect' => [['source_path' => '/old', 'target' => '/other'], 'redirect [10] already covers host * and path /old'];
        yield 'the same path with a trailing slash' => [['source_path' => '/old/', 'target' => '/other'], 'redirect [10] already covers'];
        yield 'a path with a query string that ignores query strings' => [['source_path' => '/index.php?id=12', 'target' => '/new'], 'respect_query_parameters'];
        yield 'a percent-encoded path' => [['source_path' => '/%C3%BCber-uns', 'target' => '/about'], 'Write it decoded: /über-uns'];
        yield 'a path without a slash' => [['source_path' => 'old-page', 'target' => '/new'], 'must start with a slash'];
        yield 'a regular expression that does not compile' => [['source_path' => '#^/old(#', 'is_regexp' => true, 'target' => '/new'], 'does not compile'];
        yield 'a target that is the source' => [['source_path' => '/loop', 'target' => '/loop'], 'loop'];
        yield 'a URL target on the same path' => [['source_host' => 'www.example.com', 'source_path' => '/loop', 'target' => 'http://www.example.com/loop'], 'loop'];
        yield 'the path respecting a query string it does not have' => [['source_path' => '/old-only', 'respect_query_parameters' => true, 'target' => '/b'], 'has none'];
        yield 'a status code the module does not offer' => [['source_path' => '/a', 'target' => '/b', 'target_statuscode' => 200], 'target_statuscode'];
        yield 'a host with a path' => [['source_host' => 'www.example.com/shop', 'source_path' => '/a', 'target' => '/b'], 'source_host'];
        yield 'a file that does not exist' => [['source_path' => '/a', 'target' => 't3://file?uid=999'], 'target file does not exist'];
        yield 'a page that does not exist' => [['source_path' => '/a', 'target' => 't3://page?uid=999'], 'target page does not exist'];
        yield 'a javascript target' => [['source_path' => '/a', 'target' => 'javascript:alert(1)'], 'must be a t3:// link'];
        yield 'an unknown argument' => [['source_path' => '/a', 'target' => '/b', 'hitcount' => 5], '"hitcount" is not an argument'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[Test]
    #[DataProvider('refusedRedirects')]
    public function aRedirectThatWouldNotWorkIsRefusedOnTheCardAndInTheCall(array $arguments, string $reason): void
    {
        $preview = $this->create->previewCall($arguments, $this->admin());
        $result  = $this->create->execute($arguments, $this->admin());

        self::assertCount(1, $preview);
        self::assertStringContainsString($reason, $preview[0]);
        self::assertTrue($result->isError);
        self::assertStringContainsString($reason, $result->content);
        self::assertSame(2, $this->redirectCount());
    }

    #[Test]
    public function theSamePathOnAnotherDomainIsAMoveNotALoop(): void
    {
        $result = $this->create->execute(['source_path' => '/jobs', 'target' => 'http://careers.example.org/jobs'], $this->admin());

        self::assertFalse($result->isError, $result->content);
        self::assertSame('http://careers.example.org/jobs', $this->redirectService()->matchRedirect('www.example.com', '/jobs')['target'] ?? null);
    }

    #[Test]
    public function aPathWithAQueryStringWorksWhenTheRedirectRespectsIt(): void
    {
        $result = $this->create->execute(
            ['source_path' => '/index.php?id=12', 'respect_query_parameters' => true, 'target' => '/new-home'],
            $this->admin(),
        );

        self::assertFalse($result->isError, $result->content);
        self::assertSame('/new-home', $this->redirectService()->matchRedirect('www.example.com', '/index.php', 'id=12')['target'] ?? null);
    }

    #[Test]
    public function aTargetWithTheModulesAdditionalParametersIsKeptAndAcceptedWhenSet(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool->getConnectionForTable('sys_redirect')->update('sys_redirect', ['target' => 't3://page?uid=1 - - - &tx_news_pi1[news]=5'], ['uid' => self::EXISTING]);

        // A stored value the module took and these tools would not, left alone by the call.
        $connectionPool->getConnectionForTable('sys_redirect')->update('sys_redirect', ['source_path' => 'legacy-without-slash'], ['uid' => self::DISABLED]);
        $legacy = $this->update->execute(['uid' => self::DISABLED, 'target_statuscode' => 301], $this->admin());
        self::assertFalse($legacy->isError, $legacy->content);

        $connectionPool->getConnectionForTable('sys_redirect')->update('sys_redirect', ['description' => str_repeat('x', 2500)], ['uid' => self::EXISTING]);
        $unrelated = $this->update->execute(['uid' => self::EXISTING, 'target_statuscode' => 302], $this->admin());
        $created   = $this->create->execute(['source_path' => '/news-5', 'target' => 't3://page?uid=1 - - - &tx_news_pi1[news]=5'], $this->admin());

        self::assertFalse($unrelated->isError, $unrelated->content);
        self::assertFalse($created->isError, $created->content);
    }

    #[Test]
    public function anUpgradeToHttpsAndANonAsciiTargetAreAccepted(): void
    {
        $upgrade = $this->create->execute(['source_host' => 'www.example.com', 'source_path' => '/shop', 'target' => 'https://www.example.com/shop'], $this->admin());
        $umlaut  = $this->create->execute(['source_path' => '/about', 'target' => 'https://www.example.de/über-uns'], $this->admin());

        self::assertFalse($upgrade->isError, $upgrade->content);
        self::assertFalse($umlaut->isError, $umlaut->content);
    }

    #[Test]
    public function enablingARedirectThatAnActiveOneNowCoversIsRefusedAndADisabledOneShadowsNothing(): void
    {
        $created = $this->create->execute(['source_path' => '/paused', 'target' => '/today'], $this->admin());
        $enable  = $this->update->execute(['uid' => self::DISABLED, 'disabled' => false], $this->admin());

        self::assertFalse($created->isError, $created->content);
        self::assertTrue($enable->isError);
        self::assertStringContainsString('already covers', $enable->content);
    }

    #[Test]
    public function changingADisabledRedirectSaysItStaysDisabled(): void
    {
        $preview = $this->update->previewCall(['uid' => self::DISABLED, 'target' => '/later'], $this->admin());
        $result  = $this->update->execute(['uid' => self::DISABLED, 'target' => '/later'], $this->admin());

        self::assertContains('The redirect stays disabled, so visitors are not redirected until it is enabled again.', $preview);
        self::assertNotContains('Visitors are redirected this way as soon as you approve.', $preview);
        self::assertStringContainsString('It stays disabled', $result->content);
        self::assertNull($this->redirectService()->matchRedirect('www.example.com', '/paused'));
    }

    #[Test]
    public function anExpiredRedirectIsNoDuplicateAndATimedOneNamesItsWindow(): void
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool->getConnectionForTable('sys_redirect')->insert('sys_redirect', [
            'uid'               => 13, 'pid' => 0, 'source_host' => '*', 'source_path' => '/summer-sale', 'target' => '/sale-2025',
            'target_statuscode' => 302, 'endtime' => 1735689600,
        ]);

        $preview = $this->update->previewCall(['uid' => 13, 'target' => '/sale'], $this->admin());
        $list    = $this->list->execute(['search' => 'summer'], $this->admin());
        $created = $this->create->execute(['source_path' => '/summer-sale', 'target' => '/sale-2026'], $this->admin());

        self::assertContains('Visitors are redirected this way only between its start time — and its stop time 2025-01-01T00:00:00Z (UTC).', $preview);
        self::assertStringContainsString('served until 2025-01-01T00:00:00Z', $list->content);
        self::assertFalse($created->isError, $created->content);
        self::assertSame('/sale-2026', $this->redirectService()->matchRedirect('www.example.com', '/summer-sale')['target'] ?? null);
    }

    #[Test]
    public function theCreatePreviewShowsEveryFieldAndWritesNothing(): void
    {
        $preview = $this->create->previewCall(['source_path' => '/summer-sale', 'target' => '/sale', 'force_https' => true], $this->admin());

        self::assertStartsWithHeading($preview, 'default');
        self::assertSame('Create a redirect', $preview[0]);
        self::assertContains('Visitors are redirected this way as soon as you approve.', $preview);
        $joined = implode("\n", $preview);
        self::assertStringContainsString('/summer-sale', $joined);
        self::assertStringContainsString('/sale', $joined);
        self::assertStringContainsString(': yes', $joined);
        self::assertStringNotContainsString('hit', strtolower($joined));
        self::assertSame(2, $this->redirectCount());
    }

    #[Test]
    public function anUpdateChangesTheTargetVisitorsAreSentTo(): void
    {
        self::assertSame('/new', $this->redirectService()->matchRedirect('www.example.com', '/old')['target'] ?? null);

        $result = $this->update->execute(['uid' => self::EXISTING, 'target' => '/newer'], $this->admin());

        self::assertFalse($result->isError, $result->content);
        self::assertSame(WriteKind::UPDATED, $result->writeKind);
        self::assertSame('/newer', $this->redirectService()->matchRedirect('www.example.com', '/old')['target'] ?? null);
    }

    #[Test]
    public function theUpdatePreviewShowsTheCurrentAndTheProposedValue(): void
    {
        $preview = $this->update->previewCall(['uid' => self::EXISTING, 'target' => '/newer', 'target_statuscode' => 301], $this->admin());

        self::assertStartsWithHeading($preview, 'default');
        self::assertSame('Redirect: from “*/old” to “/new”', $preview[1]);
        self::assertContains('Target: currently “/new”, proposed “/newer”', $preview);
        self::assertStringContainsString(': unchanged,', $preview[3] ?? '');
        self::assertSame('Technical details: table sys_redirect, UID 10', $preview[array_key_last($preview)]);
        self::assertSame('/new', $this->redirectService()->matchRedirect('www.example.com', '/old')['target'] ?? null);
    }

    #[Test]
    public function anUpdateThatChangesNothingWritesNothing(): void
    {
        $result = $this->update->execute(['uid' => self::EXISTING, 'target' => '/new'], $this->admin());

        self::assertFalse($result->isError, $result->content);
        self::assertStringContainsString('nothing was written', $result->content);
        self::assertNull($result->writeTarget);
    }

    #[Test]
    public function anUpdateMayNotMoveARedirectOntoAnotherOnesPath(): void
    {
        $result = $this->update->execute(['uid' => self::DISABLED, 'source_path' => '/old'], $this->admin());

        self::assertTrue($result->isError);
        self::assertStringContainsString('redirect [10] already covers', $result->content);
    }

    #[Test]
    public function anUpdateEnablesADisabledRedirectAgain(): void
    {
        self::assertNull($this->redirectService()->matchRedirect('www.example.com', '/paused'));

        $preview = $this->update->previewCall(['uid' => self::DISABLED, 'disabled' => false], $this->admin());
        $result  = $this->update->execute(['uid' => self::DISABLED, 'disabled' => false], $this->admin());

        self::assertContains('Currently: disabled. New: active', $preview);
        self::assertFalse($result->isError, $result->content);
        self::assertSame('/elsewhere', $this->redirectService()->matchRedirect('www.example.com', '/paused')['target'] ?? null);
    }

    #[Test]
    public function disablingStopsTheRedirectAndKeepsTheRecord(): void
    {
        self::assertIsArray($this->redirectService()->matchRedirect('www.example.com', '/old'));

        $result = $this->disable->execute(['uid' => self::EXISTING], $this->admin());

        self::assertFalse($result->isError, $result->content);
        self::assertSame(WriteKind::TEXT_UNCHANGED, $result->writeKind);
        self::assertNull($this->redirectService()->matchRedirect('www.example.com', '/old'));
        self::assertSame(2, $this->redirectCount());
    }

    #[Test]
    public function theDisablePreviewIsInTheActingUsersLanguage(): void
    {
        $admin               = $this->setUpBackendUser(1);
        $admin->user['lang'] = 'de';

        $german = $this->disable->previewCall(['uid' => self::EXISTING], ToolExecutionContext::fromBackendUser($admin));

        self::assertSame([
            'Weiterleitung deaktivieren',
            'Weiterleitung: von „*/old“ nach „/new“',
            'Derzeit: aktiv. Neu: deaktiviert. Besucher werden nicht mehr weitergeleitet. Die Weiterleitung bleibt erhalten und kann wieder aktiviert werden.',
            'Technische Details: Tabelle sys_redirect, UID 10',
        ], $german);
        self::assertGermanEditorLines($german, ['sys_redirect']);
    }

    #[Test]
    public function anAlreadyDisabledRedirectIsNotWrittenAgain(): void
    {
        $preview = $this->disable->previewCall(['uid' => self::DISABLED], $this->admin());
        $result  = $this->disable->execute(['uid' => self::DISABLED], $this->admin());

        self::assertContains('The redirect is already disabled. Nothing will be written.', $preview);
        self::assertStringContainsString('already disabled; nothing was written', $result->content);
        self::assertNull($result->writeTarget);
    }

    #[Test]
    public function aMissingRedirectAndAForbiddenOneReadTheSame(): void
    {
        $editor = ToolExecutionContext::fromBackendUser($this->setUpBackendUser(2));

        self::assertSame('Redirect not found or not permitted.', $this->disable->execute(['uid' => 999], $this->admin())->content);
        self::assertSame('Redirect not found or not permitted.', $this->disable->execute(['uid' => self::EXISTING], $editor)->content);
        self::assertFalse($this->disable->mayViewerReadPreview(['uid' => self::EXISTING], $this->setUpBackendUser(2)));
        self::assertTrue($this->disable->mayViewerReadPreview(['uid' => self::EXISTING], $this->setUpBackendUser(1)));
    }

    #[Test]
    public function theListNamesUidSourceTargetAndStateAndFilters(): void
    {
        $all      = $this->list->execute([], $this->admin());
        $filtered = $this->list->execute(['search' => 'paus'], $this->admin());
        $editor   = $this->list->execute([], ToolExecutionContext::fromBackendUser($this->setUpBackendUser(2)));

        self::assertSame("[11] */paused → /elsewhere (302) — disabled\n[10] */old → /new (301)", $all->content);
        self::assertSame('[11] */paused → /elsewhere (302) — disabled', $filtered->content);
        self::assertTrue($editor->isError);
    }

    #[Test]
    public function aHookThatDropsTheTargetMakesTheUpdateFailInsteadOfReportSuccess(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropRedirectColumn = 'target';

        $result = $this->update->execute(['uid' => self::EXISTING, 'target' => '/newer'], $this->admin());

        self::assertTrue($result->isError);
        self::assertStringContainsString('does not hold the requested values', $result->content);
    }

    #[Test]
    public function aHookThatEmptiesTheTargetOnCreateMakesTheCreateFail(): void
    {
        $this->registerInterferingHook();
        InterferesWithAnUpdateHook::$dropColumnOnCreate = 'target_statuscode';

        $result = $this->create->execute(['source_path' => '/sale', 'target' => '/shop', 'target_statuscode' => 302], $this->admin());

        self::assertFalse($result->isError);
        self::assertStringContainsString('does not hold the requested', $result->content);
        self::assertSame(WriteCompleteness::PARTIAL, $result->writeCompleteness);
        self::assertSame(WriteKind::CREATED, $result->writeKind);
    }

    #[Test]
    public function aQrCodeRecordIsNeitherListedNorChanged(): void
    {
        $tca     = $GLOBALS['TCA'] ?? null;
        $table   = is_array($tca) ? ($tca['sys_redirect'] ?? null) : null;
        $columns = is_array($table) ? ($table['columns'] ?? null) : null;
        if (!is_array($columns) || !isset($columns['redirect_type'])) {
            self::markTestSkipped('QR code redirects arrived with TYPO3 14.');
        }

        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);
        $connectionPool->getConnectionForTable('sys_redirect')->insert('sys_redirect', [
            'uid'               => 12, 'pid' => 0, 'source_host' => '*', 'source_path' => '/qr', 'target' => '/flyer',
            'target_statuscode' => 307, 'redirect_type' => 'qrcode',
        ]);

        $list    = $this->list->execute([], $this->admin());
        $update  = $this->update->execute(['uid' => 12, 'target' => '/other'], $this->admin());
        $disable = $this->disable->execute(['uid' => 12], $this->admin());

        self::assertStringNotContainsString('[12]', $list->content);
        self::assertStringContainsString('is a "qrcode" record', $update->content);
        self::assertStringContainsString('is a "qrcode" record', $disable->content);
    }

    private function admin(): ToolExecutionContext
    {
        return ToolExecutionContext::fromBackendUser($this->setUpBackendUser(1));
    }

    private function redirectService(): RedirectService
    {
        $service = $this->get(RedirectService::class);
        self::assertInstanceOf(RedirectService::class, $service);

        return $service;
    }

    private function redirectCount(): int
    {
        $connectionPool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $connectionPool);

        // Disabled rows count: disabling keeps the record.
        $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_redirect');
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder->count('uid')->from('sys_redirect')->where($queryBuilder->expr()->eq('deleted', 0))->executeQuery()->fetchOne();
    }
}

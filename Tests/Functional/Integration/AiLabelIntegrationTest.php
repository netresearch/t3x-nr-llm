<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Integration;

use B13\AiLabel\Domain\Enum\AiOrigin;
use B13\AiLabel\Domain\Model\AiMetadata;
use Netresearch\NrLlm\Domain\Enum\FieldFillOutcome;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\AiActorContext;
use Netresearch\NrLlm\Domain\ValueObject\FieldFillTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Event\AfterAiRecordWrittenEvent;
use Netresearch\NrLlm\Event\AiLabelWriteEvent;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillSettings;
use Netresearch\NrLlm\Service\Agent\FieldFill\FieldFillWriter;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Netresearch\NrLlm\Service\Tool\AiLabel\RecordAiLabelWrite;
use Netresearch\NrLlm\Service\Tool\RecordTextReader;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Tests\Functional\Fixtures\DiscardAiLabelDraftHook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[CoversClass(RecordAiLabelWrite::class)]
final class AiLabelIntegrationTest extends AbstractFunctionalTestCase
{
    protected array $testExtensionsToLoad = ['netresearch/nr-vault', 'b13/ai-label', 'netresearch/nr-llm'];

    protected array $coreExtensionsToLoad = ['extbase', 'fluid', 'workspaces'];

    private BackendUserAuthentication $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importFixture('AiLabel/Integration.csv');
        $this->actor            = $this->setUpBackendUser(1);
        $this->actor->workspace = 0;
    }

    /**
     * @return iterable<string, array{WriteKind, bool, AiOrigin}>
     */
    public static function origins(): iterable
    {
        yield 'new generated content' => [WriteKind::CREATED, true, AiOrigin::Generated];
        yield 'new record alone proves only a contribution' => [WriteKind::CREATED, false, AiOrigin::Manipulated];
        yield 'field fill or update' => [WriteKind::UPDATED, false, AiOrigin::Manipulated];
    }

    #[Test]
    #[DataProvider('origins')]
    public function writesThroughTheRealApiAndKeepsTheBoolean(
        WriteKind $kind,
        bool $generated,
        AiOrigin $expected,
    ): void {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        $this->dispatch($kind, $generated);
        $metadata = $this->metadata(7);
        self::assertSame($expected, $metadata->getOrigin());
        self::assertFalse($metadata->isReviewed());
        self::assertSame(
            1,
            (int) $this
                ->connection('tt_content')
                ->select(['tx_nrllm_ai_written'], 'tt_content', ['uid' => 7])
                ->fetchOne(),
        );
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        self::assertArrayNotHasKey('LANG', $GLOBALS);
        self::assertGreaterThan(
            0,
            $this
                ->connection('sys_history')
                ->count(
                    'uid',
                    'sys_history',
                    ['tablename' => 'tt_content', 'recuid' => 7],
                ),
        );
    }

    #[Test]
    public function anotherAiWritePreservesGeneratedOriginButResetsHumanReview(): void
    {
        $this
            ->connection('tt_content')
            ->update(
                'tt_content',
                [
                    'tx_ailabel_metadata' => [
                        'origin'             => AiOrigin::Generated->value,
                        'reviewed_by'        => 1,
                        'reviewed_timestamp' => 1234,
                    ],
                ],
                ['uid' => 7],
            );
        $this->dispatch(WriteKind::UPDATED);
        self::assertSame(AiOrigin::Generated, $this->metadata(7)->getOrigin());
        self::assertSame(0, $this->metadata(7)->getReviewedBy());
        self::assertSame(0, $this->metadata(7)->getReviewedTimestamp());
    }

    #[Test]
    public function onlyTheExistingWorkspaceVersionReceivesTheLabel(): void
    {
        $this
            ->connection('sys_workspace')
            ->insert('sys_workspace', ['uid' => 1, 'title' => 'Draft']);
        $this
            ->connection('tt_content')
            ->insert(
                'tt_content',
                [
                    'uid'        => 70,
                    'pid'        => 1,
                    'header'     => 'Draft',
                    'CType'      => 'text',
                    't3ver_wsid' => 1,
                    't3ver_oid'  => 7,
                ],
            );
        $this->actor->workspace = 1;
        $this->dispatch(WriteKind::UPDATED);
        self::assertSame(AiOrigin::Human, $this->metadata(7)->getOrigin());
        self::assertSame(
            AiOrigin::Manipulated,
            $this->metadata(70)->getOrigin(),
        );
    }

    #[Test]
    public function aMissingDraftIsNotRecreatedOrWrittenLive(): void
    {
        $this
            ->connection('sys_workspace')
            ->insert('sys_workspace', ['uid' => 1, 'title' => 'Draft']);
        $this->actor->workspace = 1;
        $this->expectException(RuntimeException::class);
        try {
            $this->dispatch(WriteKind::UPDATED);
        } finally {
            self::assertSame(AiOrigin::Human, $this->metadata(7)->getOrigin());
            self::assertSame(
                1,
                $this->connection('tt_content')->count('uid', 'tt_content', []),
            );
        }
    }

    #[Test]
    public function imageMetadataAndNonTextOperationsAreNotLabelled(): void
    {
        foreach ([WriteKind::DELETED, WriteKind::TEXT_UNCHANGED] as $kind) {
            $this->dispatch($kind);
        }

        $dispatcher = $this->getService(EventDispatcherInterface::class);
        $write      = new AfterAiRecordWrittenEvent(
            'run-uuid',
            new RecordReference('sys_file_metadata', 999),
            WriteKind::UPDATED,
        );
        $dispatcher->dispatch(new AiLabelWriteEvent($write, $this->actor));
        self::assertSame(AiOrigin::Human, $this->metadata(7)->getOrigin());
    }

    #[Test]
    public function anUnprivilegedActorCannotBorrowAnAmbientAdministratorsPrivileges(): void
    {
        $this
            ->connection('be_users')
            ->insert('be_users', ['uid' => 2, 'username' => 'no-access', 'admin' => 0]);
        $unprivileged            = $this->setUpBackendUser(2);
        $unprivileged->workspace = 0;
        $GLOBALS['BE_USER']      = $this->actor;
        $this->expectException(RuntimeException::class);
        try {
            $dispatcher = $this->getService(EventDispatcherInterface::class);
            $write      = new AfterAiRecordWrittenEvent(
                'run-uuid',
                new RecordReference('tt_content', 7),
                WriteKind::UPDATED,
            );
            $dispatcher->dispatch(new AiLabelWriteEvent($write, $unprivileged));
        } finally {
            self::assertSame($this->actor, $GLOBALS['BE_USER']);
            self::assertSame(AiOrigin::Human, $this->metadata(7)->getOrigin());
        }
    }

    private function dispatch(WriteKind $kind, bool $generated = false): void
    {
        $dispatcher = $this->getService(EventDispatcherInterface::class);
        $write      = new AfterAiRecordWrittenEvent(
            'run-uuid',
            new RecordReference('tt_content', 7),
            $kind,
            $this->actor->workspace,
        );
        $dispatcher->dispatch($write);
        $dispatcher->dispatch(
            new AiLabelWriteEvent($write, $this->actor, $generated),
        );
    }

    private function metadata(int $uid): AiMetadata
    {
        $raw = $this
            ->connection('tt_content')
            ->select(['tx_ailabel_metadata'], 'tt_content', ['uid' => $uid])
            ->fetchOne();

        return AiMetadata::fromJsonString(is_string($raw) ? $raw : null);
    }

    private function connection(string $table): Connection
    {
        return $this->getService(ConnectionPool::class)->getConnectionForTable($table);
    }

    #[Test]
    public function fillingAFieldAlsoLabelsTheRecordWithoutAnAmbientUser(): void
    {
        $this
            ->connection('tt_content')
            ->update('tt_content', ['header' => ''], ['uid' => 7]);
        // @phpstan-ignore-next-line TYPO3 configuration globals are mixed.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_llm']['fieldFill']['fields'] = 'tt_content.header';
        $writer                                                                    = new FieldFillWriter(
            new FieldFillSettings(
                $this->getService(ExtensionConfiguration::class),
            ),
            $this->getService(RecordTextReader::class),
            new ActingBackendUserResolver(),
            $this->getService(LanguageServiceFactory::class),
            $this->getService(EventDispatcherInterface::class),
        );
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        $outcome = $writer->write(
            new FieldFillTarget('tt_content', 7, 'header'),
            'AI title',
            AiActorContext::backendUser(1, workspaceId: 0),
            'fill-run',
        );
        self::assertSame(FieldFillOutcome::WRITTEN, $outcome);
        self::assertSame(AiOrigin::Manipulated, $this->metadata(7)->getOrigin());
        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        self::assertArrayNotHasKey('LANG', $GLOBALS);
    }

    #[Test]
    public function aContentContainerWithoutGeneratedBodyTextIsOnlyModified(): void
    {
        $this
            ->connection('tt_content')
            ->update('tt_content', ['bodytext' => ''], ['uid' => 7]);
        $this->dispatch(WriteKind::CREATED, true);
        self::assertSame(AiOrigin::Manipulated, $this->metadata(7)->getOrigin());
    }

    #[Test]
    public function aNewRecordInADraftWorkspaceIsLabelledThere(): void
    {
        $this
            ->connection('sys_workspace')
            ->insert('sys_workspace', ['uid' => 1, 'title' => 'Draft']);
        $this
            ->connection('tt_content')
            ->update('tt_content', ['t3ver_wsid' => 1, 't3ver_state' => 1], ['uid' => 7]);
        $this->actor->workspace = 1;
        $this->dispatch(WriteKind::CREATED, true);
        self::assertSame(AiOrigin::Generated, $this->metadata(7)->getOrigin());
    }

    #[Test]
    public function aWorkspaceMismatchCannotSendTheLabelElsewhere(): void
    {
        $write = new AfterAiRecordWrittenEvent(
            'run-uuid',
            new RecordReference('tt_content', 7),
            WriteKind::UPDATED,
            2,
        );
        $this->expectException(RuntimeException::class);
        try {
            $this
                ->getService(EventDispatcherInterface::class)
                ->dispatch(new AiLabelWriteEvent($write, $this->actor));
        } finally {
            self::assertSame(AiOrigin::Human, $this->metadata(7)->getOrigin());
        }
    }

    #[Test]
    public function aDraftDiscardedImmediatelyBeforeTheLabelSaveIsNotRecreated(): void
    {
        $this
            ->connection('sys_workspace')
            ->insert('sys_workspace', ['uid' => 1, 'title' => 'Draft']);
        $this
            ->connection('tt_content')
            ->insert(
                'tt_content',
                [
                    'uid'        => 70,
                    'pid'        => 1,
                    'header'     => 'Draft',
                    'CType'      => 'text',
                    't3ver_wsid' => 1,
                    't3ver_oid'  => 7,
                ],
            );
        $this->actor->workspace = 1;
        // @phpstan-ignore-next-line TYPO3 hook configuration is mixed.
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = DiscardAiLabelDraftHook::class;
        GeneralUtility::setSingletonInstance(
            DiscardAiLabelDraftHook::class,
            new DiscardAiLabelDraftHook(
                $this->getService(ConnectionPool::class),
            ),
        );
        DiscardAiLabelDraftHook::$uid = 70;
        $this->expectException(RuntimeException::class);
        try {
            $this->dispatch(WriteKind::UPDATED);
        } finally {
            DiscardAiLabelDraftHook::$uid = 0;
            self::assertSame(
                1,
                $this->connection('tt_content')->count('uid', 'tt_content', []),
            );
            self::assertSame(AiOrigin::Human, $this->metadata(7)->getOrigin());
        }
    }

    /**
     * @return iterable<string, array{bool, string, int}>
     */
    public static function draftVisibility(): iterable
    {
        foreach ([false, true] as $new) {
            $kind = $new ? 'new' : 'version';
            yield $kind . ' hidden' => [$new, 'hidden', 1];
            yield $kind . ' scheduled' => [$new, 'starttime', time() + 3600];
            yield $kind . ' expired' => [$new, 'endtime', 1];
        }
    }

    #[Test]
    #[DataProvider('draftVisibility')]
    public function draftLabelsDoNotDependOnFrontendVisibility(
        bool $new,
        string $field,
        int $value,
    ): void {
        $this
            ->connection('sys_workspace')
            ->insert('sys_workspace', ['uid' => 1, 'title' => 'Draft']);
        $uid = $new ? 7 : 70;
        if ($new) {
            $this
                ->connection('tt_content')
                ->update(
                    'tt_content',
                    ['t3ver_wsid' => 1, 't3ver_state' => 1, $field => $value],
                    ['uid' => 7],
                );
        } else {
            $this
                ->connection('tt_content')
                ->insert(
                    'tt_content',
                    [
                        'uid'        => 70,
                        'pid'        => 1,
                        'header'     => 'Draft',
                        'CType'      => 'text',
                        't3ver_wsid' => 1,
                        't3ver_oid'  => 7,
                        $field       => $value,
                    ],
                );
        }

        $this->actor->workspace = 1;
        $this->dispatch(WriteKind::UPDATED);
        // A metadata assertion must not apply frontend visibility filters either.
        $raw = $this
            ->connection('tt_content')
            ->executeQuery('SELECT tx_ailabel_metadata FROM tt_content WHERE uid = ?', [$uid])
            ->fetchOne();
        self::assertIsString($raw);
        self::assertSame(
            AiOrigin::Manipulated,
            AiMetadata::fromJsonString($raw)->getOrigin(),
        );
        if (!$new) {
            self::assertSame(AiOrigin::Human, $this->metadata(7)->getOrigin());
        }
    }
}

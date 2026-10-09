<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Updates;

use Netresearch\NrLlm\Domain\Enum\SkillAuditEvent;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Service\Privacy\ContentRedactor;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicy;
use Netresearch\NrLlm\Service\Skill\SkillAuditRepository;
use Netresearch\NrLlm\Service\Skill\SkillAuditService;
use Netresearch\NrLlm\Service\Skill\SkillMarkdownParser;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use Netresearch\NrLlm\Updates\SkillVersionDigestUpdateWizard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(SkillVersionDigestUpdateWizard::class)]
final class SkillVersionDigestUpdateWizardTest extends AbstractFunctionalTestCase
{
    private const TABLE = 'tx_nrllm_skill';

    #[Test]
    public function aRowStillAsTheSyncWroteItGetsItsDigestAndProcessMarkerAndStaysEnabled(): void
    {
        $this->insert(1, []);
        $this->insert(2, ['raw_frontmatter' => '{"name":"Guide","description":"House style.","allowed-tools":"GetTca","process":true}']);

        $wizard = $this->wizard();
        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());
        self::assertFalse($wizard->updateNecessary());

        $first = $this->row(1);
        self::assertTrue(SkillVersionDigest::isWellFormed(self::str($first['version_digest'] ?? '')));
        self::assertSame(1, (int)$first['enabled']);
        self::assertSame('', $first['disabled_by']);
        self::assertSame(0, (int)$first['process']);
        self::assertSame(1, (int)$this->row(2)['process']);
        self::assertNotSame($first['version_digest'], $this->row(2)['version_digest'], 'the process marker is part of the version');
        self::assertSame([], $this->auditEvents());
    }

    /**
     * @return iterable<string, array{array<string, int|string>, string}>
     */
    public static function editsAfterTheSync(): iterable
    {
        yield 'name' => [['name' => 'Renamed in the backend'], 'name'];
        yield 'description' => [['description' => 'Edited'], 'description'];
        yield 'allowed tools' => [['allowed_tools' => '["GetTca","DeleteRecord"]'], 'allowed_tools'];
        yield 'support status' => [['support_status' => 'full'], 'support_status'];
        yield 'body' => [['body' => 'Edited body.'], 'body'];
    }

    /**
     * @param array<string, int|string> $edit
     */
    #[Test]
    #[DataProvider('editsAfterTheSync')]
    public function aRowEditedAfterTheSyncIsDisabledAuditedAndLeftWithoutADigest(array $edit, string $field): void
    {
        $this->insert(1, $edit);

        $this->wizard()->executeUpdate();

        $row = $this->row(1);
        self::assertSame('', $row['version_digest']);
        self::assertSame(0, (int)$row['enabled']);
        self::assertSame('sync', $row['disabled_by'], 'the wizard disables as the sync does, so the skill keeps restricting');
        self::assertSame([SkillAuditEvent::VERSION_DIGEST_UNVERIFIED->value], $this->auditEvents());
        self::assertStringContainsString($field, $this->auditDetails()[0]);
    }

    /**
     * A sync that runs between the wizard's read and its write stores a digest
     * over its own fields. The wizard's write is conditioned on the row still
     * being legacy, so neither its digest nor its disable replaces the sync's.
     * Called through reflection because the interleaving cannot be produced
     * from outside a single executeUpdate().
     */
    #[Test]
    public function aRowASyncHasDigestedMeanwhileIsNotOverwritten(): void
    {
        $this->insert(1, []);
        $skill = new Skill();
        $skill->_setProperty('uid', 1);
        // The sync wrote its version after the wizard read the row.
        $this->pool()->getConnectionForTable(self::TABLE)->update(self::TABLE, ['version_digest' => '1:' . str_repeat('c', 64)], ['uid' => 1]);

        $affected = (new ReflectionMethod(SkillVersionDigestUpdateWizard::class, 'update'))
            ->invoke($this->wizard(), $skill, ['enabled' => 0, 'version_digest' => '1:' . str_repeat('d', 64)]);

        self::assertSame(0, $affected);
        self::assertSame('1:' . str_repeat('c', 64), $this->row(1)['version_digest']);
        self::assertSame(1, (int)$this->row(1)['enabled']);
    }

    /**
     * MySQL counts an UPDATE that changes nothing as no affected row. An
     * edited row that was already disabled must still be audited.
     */
    #[Test]
    public function anEditedRowThatWasAlreadyDisabledIsStillAudited(): void
    {
        $this->insert(1, ['name' => 'Renamed in the backend', 'enabled' => 0]);

        $this->wizard()->executeUpdate();

        self::assertSame([SkillAuditEvent::VERSION_DIGEST_UNVERIFIED->value], $this->auditEvents());
    }

    private function wizard(): SkillVersionDigestUpdateWizard
    {
        return new SkillVersionDigestUpdateWizard($this->pool(), new SkillMarkdownParser(), new SkillAuditService($this->auditRepository()));
    }

    /**
     * A row as the sync wrote it before ADR-214: a body that references
     * scripts is "partial", the string-form tools are stored as a list.
     *
     * @param array<string, int|string> $override
     */
    private function insert(int $uid, array $override): void
    {
        $body = 'Follow the house style. See scripts/check.sh.';
        $row  = [
            'uid'             => $uid,
            'pid'             => 0,
            'source'          => 10,
            'identifier'      => '10:skills/' . $uid . '/SKILL.md',
            'name'            => 'Guide',
            'description'     => 'House style.',
            'body'            => $body,
            'body_checksum'   => hash('sha256', $body),
            'raw_frontmatter' => '{"name":"Guide","description":"House style.","allowed-tools":"GetTca"}',
            'support_status'  => 'partial',
            'allowed_tools'   => '["GetTca"]',
            'trust_level'     => 'verified',
            'enabled'         => 1,
            'version_digest'  => '',
        ];
        // The body checksum stays the one the sync wrote, whatever the edit.
        $this->pool()->getConnectionForTable(self::TABLE)->insert(self::TABLE, array_merge($row, $override));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $uid): array
    {
        $row = $this->pool()->getConnectionForTable(self::TABLE)->select(['*'], self::TABLE, ['uid' => $uid])->fetchAssociative();
        self::assertIsArray($row);

        return $row;
    }

    private function auditRepository(): SkillAuditRepository
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['privacy' => ['level' => 'full']]);

        return new SkillAuditRepository($this->pool(), new PrivacyPolicy($extensionConfiguration, new ContentRedactor()));
    }

    /**
     * @return list<string>
     */
    private function auditEvents(): array
    {
        return array_map(static fn(array $row): string => self::str($row['event'] ?? ''), $this->auditRepository()->findBySourceUid(10));
    }

    /**
     * @return list<string>
     */
    private function auditDetails(): array
    {
        return array_map(static fn(array $row): string => self::str($row['detail'] ?? ''), $this->auditRepository()->findBySourceUid(10));
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);

        return $pool;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}

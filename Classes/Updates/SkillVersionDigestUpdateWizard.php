<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Updates;

use Netresearch\NrLlm\Domain\Enum\SkillAuditEvent;
use Netresearch\NrLlm\Domain\Model\Skill;
use Netresearch\NrLlm\Service\Skill\Exception\SkillParseException;
use Netresearch\NrLlm\Service\Skill\SkillAuditService;
use Netresearch\NrLlm\Service\Skill\SkillFrontmatter;
use Netresearch\NrLlm\Service\Skill\SkillMarkdownParser;
use Netresearch\NrLlm\Service\Skill\SkillVersionDigest;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Install\Attribute\UpgradeWizard;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;

/**
 * Write the version digest onto skills synced before it existed (ADR-214
 * item 1), without blessing earlier edits.
 *
 * ``name`` and ``description`` were editable in FormEngine before this change,
 * and ``support_status`` and ``allowed_tools`` were read-only only in
 * FormEngine, so the stored fields of a synced row may differ from what the
 * sync wrote. Hashing them as they stand would turn such an edit into an
 * approvable version. So before it writes a digest the wizard proves the row
 * is still what the sync wrote:
 *
 * - the body verifies against ``body_checksum``;
 * - ``name``, ``description`` and ``support_status`` equal what the parser
 *   derives from the stored ``raw_frontmatter`` and body, by the same rules
 *   the sync used ({@see SkillMarkdownParser::fromFrontmatter()});
 * - ``allowed_tools`` declares the same tools the stored ``raw_frontmatter``
 *   does ({@see SkillFrontmatter::allowedTools()}).
 *
 * ``raw_frontmatter`` itself is the reference because the sync wrote it and
 * FormEngine only ever showed it read-only. That is a limit: read-only is a
 * FormEngine setting the DataHandler does not enforce, and before this change
 * ``body_checksum`` and ``raw_frontmatter`` were not ``exclude`` fields. A
 * DataHandler write that changed body, checksum and frontmatter together and
 * consistently passes the wizard. It still cannot instruct without an
 * administrator's approval of the version shown, and the next sync compares
 * it with the source again. ADR-214 names name, description
 * and allowed_tools; ``support_status`` is checked as well because it is part
 * of the digest and carried no ``exclude`` either.
 *
 * A row that passes gets its process marker from ``raw_frontmatter`` and its
 * digest. A row that fails is disabled, audited as
 * ``version_digest_unverified`` and left without a digest: it keeps the
 * body-only legacy check, can never instruct, and the next sync rewrites it.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
#[UpgradeWizard('nrllmAdr214SkillVersionDigest')]
final readonly class SkillVersionDigestUpdateWizard implements UpgradeWizardInterface
{
    use SafeCastTrait;

    private const TABLE = 'tx_nrllm_skill';

    public function __construct(
        private ConnectionPool $connectionPool,
        private SkillMarkdownParser $parser,
        private SkillAuditService $audit,
    ) {}

    public function getTitle(): string
    {
        return 'Write the version digest onto existing skills';
    }

    public function getDescription(): string
    {
        return 'Skill versions are now identified by a digest over the body and the frontmatter fields the model '
            . 'reads (ADR-214). This wizard writes it onto every skill synced before. It first checks that each '
            . 'row is still what the sync wrote: the body against its checksum, and name, description, support '
            . 'status and allowed tools against the stored frontmatter. A row that was edited after the sync is '
            . 'disabled, recorded in the skill audit trail and left without a digest until the next sync rewrites it.';
    }

    public function executeUpdate(): bool
    {
        foreach ($this->legacyRows() as $row) {
            $skill    = $this->hydrate($row);
            $mismatch = $this->mismatches($row, $skill);
            if ($mismatch === []) {
                $skill->setVersionDigest(SkillVersionDigest::of($skill));
                $this->update($skill, [
                    'process'        => $skill->isProcess() ? 1 : 0,
                    'version_digest' => $skill->getVersionDigest(),
                ]);
                continue;
            }

            $skill->setEnabled(false);
            if ($this->update($skill, ['enabled' => 0]) === 0) {
                continue;
            }

            $this->audit->recordSkillEvent(
                SkillAuditEvent::VERSION_DIGEST_UNVERIFIED,
                $skill,
                'stored fields differ from what the sync wrote: ' . implode(', ', $mismatch),
            );
        }

        return true;
    }

    public function updateNecessary(): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $count = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('version_digest', $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int)$count > 0;
    }

    /**
     * @return array<int, class-string>
     */
    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    /**
     * The names of the stored fields that differ from what the sync wrote, or
     * [] when the row verifies. The process marker is not compared: it is new
     * with this change and is taken from ``raw_frontmatter``.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function mismatches(array $row, Skill $skill): array
    {
        if (!hash_equals($skill->getBodyChecksum(), hash('sha256', $skill->getBody()))) {
            return ['body'];
        }

        $frontmatter = $this->storedFrontmatter(self::toStr($row['raw_frontmatter'] ?? ''));
        if ($frontmatter === null) {
            return ['raw_frontmatter'];
        }

        try {
            $parsed = $this->parser->fromFrontmatter($skill->getIdentifier(), $frontmatter, $skill->getBody());
        } catch (SkillParseException) {
            return ['raw_frontmatter'];
        }

        $mismatch = [];
        if ($skill->getName() !== $parsed->name) {
            $mismatch[] = 'name';
        }

        if ($skill->getDescription() !== $parsed->description) {
            $mismatch[] = 'description';
        }

        if ($skill->getSupportStatus() !== $parsed->supportStatus->value) {
            $mismatch[] = 'support_status';
        }

        if (
            SkillVersionDigest::normaliseTools($skill->getAllowedToolsList())
            !== SkillVersionDigest::normaliseTools(SkillFrontmatter::allowedTools($frontmatter))
        ) {
            $mismatch[] = 'allowed_tools';
        }

        if ($mismatch === []) {
            $skill->setProcess(SkillFrontmatter::isProcess($frontmatter));
        }

        return $mismatch;
    }

    /**
     * @return array<string, mixed>|null the decoded mapping, or null when the stored value is not one
     */
    private function storedFrontmatter(string $stored): ?array
    {
        $decoded = json_decode($stored, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        $mapping = [];
        foreach ($decoded as $key => $value) {
            $mapping[(string)$key] = $value;
        }

        return $mapping;
    }

    /**
     * Every skill row without a digest, deleted ones excluded.
     *
     * @return list<array<string, mixed>>
     */
    private function legacyRows(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('version_digest', $queryBuilder->createNamedParameter('')),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Skill
    {
        $skill = new Skill();
        $skill->_setProperty('uid', self::toInt($row['uid'] ?? 0));
        $skill->setSource(self::toInt($row['source'] ?? 0));
        $skill->setIdentifier(self::toStr($row['identifier'] ?? ''));
        $skill->setName(self::toStr($row['name'] ?? ''));
        $skill->setDescription(self::toStr($row['description'] ?? ''));
        $skill->setBody(self::toStr($row['body'] ?? ''));
        $skill->setBodyChecksum(self::toStr($row['body_checksum'] ?? ''));
        $skill->setSourceSha(self::toStr($row['source_sha'] ?? ''));
        $skill->setRawFrontmatter(self::toStr($row['raw_frontmatter'] ?? ''));
        $skill->setSupportStatus(self::toStr($row['support_status'] ?? ''));
        $skill->setAllowedTools(self::toStr($row['allowed_tools'] ?? ''));
        $skill->setTrustLevel(self::toStr($row['trust_level'] ?? ''));
        $skill->setInjectionScan(self::toStr($row['injection_scan'] ?? ''));
        $skill->setEnabled(self::toInt($row['enabled'] ?? 0) === 1);

        return $skill;
    }

    /**
     * @param array<string, int|string> $values
     */
    private function update(Skill $skill, array $values): int
    {
        // Only while the row is still legacy: a sync that ran between the read
        // and this write has stored a digest over its own fields, and neither
        // the digest computed from the old fields nor a disable may replace it.
        return $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            $values,
            ['uid' => (int)$skill->getUid(), 'version_digest' => ''],
        );
    }
}

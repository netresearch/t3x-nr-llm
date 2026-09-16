<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolving ONE `sys_file` and its live, default-language `sys_file_metadata`
 * row, for the acting user — the question both metadata writers ask before they
 * write anything.
 *
 * It exists because the second one was written by copying the first. The
 * duplication detector put 93 lines of {@see UpdateFalAssetMetaTool} against
 * {@see SetFileAlternativeTextTool} — 5.9 % new duplicated lines against a 3 %
 * gate — and that is the same measurement, and the same answer,
 * :ref:`ADR-146 <adr-146>` reached for the three writers it added: two copies
 * made in one sitting are copy-paste, not two decisions.
 *
 * ADR-135 and ADR-146 both declined to retrofit the shipped writers into a
 * shared trait, and this does not contradict them. Those records declined to
 * route working code through a trait that answered a DIFFERENT question, for a
 * commonality that was argued rather than measured. Here the two files contain
 * the same query, verbatim, and the second copy is three days old.
 *
 * WHAT IS SHARED is the resolution and the three pins that decide WHICH row a
 * write lands on. WHAT IS NOT is the refusal vocabulary, the fields, the
 * read-back and the preview — each tool keeps its own, because those are what
 * the tools differ in.
 */
trait ResolvesOneFalAssetTrait
{
    /** The only language these tools address; see each tool's class docblock. */
    private const FAL_DEFAULT_LANGUAGE = 0;

    /** The only workspace these tools address; see {@see self::fetchFalMetadata()}. */
    private const FAL_LIVE_WORKSPACE = 0;

    private const FAL_FILE_TABLE = 'sys_file';

    private const FAL_METADATA_TABLE = 'sys_file_metadata';

    /**
     * The file row and its default-language metadata row, or null when the
     * acting user may not reach the file, no file carries the uid, or the file
     * carries no metadata record.
     *
     * All four collapse into one answer on purpose, and the CALLER turns that
     * null into its own neutral refusal: a uid in a forbidden storage, a uid
     * outside the acting user's mounts, a uid no file carries and a file
     * without a metadata record must be indistinguishable, or the model can
     * probe `sys_file` for existence.
     *
     * @param non-empty-string ...$metadataColumns the metadata columns the caller needs, beside `uid`
     *
     * @return array{array<string, mixed>, array<string, mixed>}|null
     */
    private function resolveFalAsset(BackendUserAuthentication $user, int $uid, string ...$metadataColumns): ?array
    {
        $file = $this->fetchFalFile($uid);
        if ($file === null) {
            return null;
        }

        // nr_llm's own barrier, and it has to run here: the storage allow-list
        // is this extension's configuration and the DataHandler cannot consult
        // it. Core's FileMetadataPermissionsAspect then asks the strictly
        // narrower question (a WRITABLE file mount, `editMeta`) inside the
        // write itself.
        if (!$this->storageGate->isFileAccessible($user, self::toInt($file['storage'] ?? 0), self::toStr($file['identifier'] ?? ''))) {
            return null;
        }

        // These tools write the default-language record, so the acting user
        // needs access to the default language — a user restricted to other
        // languages may not write it (checked against the explicit user,
        // ADR-083).
        if (!$user->checkLanguageAccess(self::FAL_DEFAULT_LANGUAGE)) {
            return null;
        }

        $metadata = $this->fetchFalMetadata($uid, ...$metadataColumns);

        return $metadata === null ? null : [$file, $metadata];
    }

    /**
     * The `sys_file` row, or null when no file carries that uid.
     *
     * `sys_file` has no enable columns (no `deleted`, no `hidden`), so there is
     * no restriction to keep — the storage gate is the access decision.
     *
     * @return array<string, mixed>|null
     */
    private function fetchFalFile(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::FAL_FILE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('uid', 'storage', 'identifier', 'name')
            ->from(self::FAL_FILE_TABLE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The LIVE, DEFAULT-language metadata row of a file, or null when it has
     * none.
     *
     * A file is looked up by `file`, not by uid, so more than one row can match
     * and every pin below decides WHICH row a caller then writes:
     *
     * - the LANGUAGE, because `sys_file_metadata` is language-aware and
     *   `removeAll()` drops the language restriction — an arbitrary translation
     *   could otherwise be picked up and written instead of the original;
     * - the WORKSPACE, because the table is workspace-aware
     *   (`ctrl.versioningWS`) and a draft version carries the same `file` and
     *   the same `sys_language_uid = 0` as the live row it versions. Without the
     *   restriction a tool could write a stranger's unpublished draft from the
     *   live workspace, leave the live value untouched and still report success:
     *   both callers verify by re-reading the uid they wrote, so they would
     *   confirm the wrong row rather than catch it;
     * - the ORDER, because two candidate rows and no `ORDER BY` leave the choice
     *   to the database.
     *
     * Core's own {@see \TYPO3\CMS\Core\Resource\Index\MetaDataRepository::findByFileUid()}
     * pins the same three for the same query.
     *
     * @param non-empty-string ...$columns
     *
     * @return array<string, mixed>|null
     */
    private function fetchFalMetadata(int $fileUid, string ...$columns): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::FAL_METADATA_TABLE);
        // Live only. Both tools refuse outside the live workspace anyway, and a
        // preview must resolve the same row the write would target.
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, self::FAL_LIVE_WORKSPACE));

        $row = $queryBuilder
            ->select('uid', ...$columns)
            ->from(self::FAL_METADATA_TABLE)
            ->where(
                $queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter(self::FAL_DEFAULT_LANGUAGE, Connection::PARAM_INT),
                ),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Prompt;

use Netresearch\NrLlm\Domain\Model\PromptSnippet;
use Netresearch\NrLlm\Service\Skill\SkillInvisibleCharacters;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Marks and unmarks a snippet for a caller's system message (ADR-214 item 8).
 *
 * The marking form shows the composed block and the snippet's tags and posts
 * the digest of what it rendered. The mark is refused when that digest is not
 * the digest of the block and tags as stored now — the rule item 2 applies to skill approvals — and when
 * the block holds characters a browser does not show but a model reads, which
 * the administrator could not have reviewed.
 *
 * Written through a plain update: the column is excluded and not part of any
 * form, so FormEngine cannot write it, and this service is the only writer.
 * Every mark and unmark is logged with the snippet, the digest and the acting
 * backend user, so who let a block into the caller's system channel can be
 * read back.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class SnippetCallerMarkService
{
    public const MARKED = 'marked';

    public const REFUSED_STALE = 'stale';

    public const REFUSED_EMPTY = 'empty';

    public const REFUSED_INVISIBLE = 'invisible';

    private const TABLE = 'tx_nrllm_promptsnippet';

    public function __construct(
        private ConnectionPool $connectionPool,
        private SnippetCallerMark $mark = new SnippetCallerMark(),
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * What the review page shows: the block, the tags the mark is bound to,
     * their digest, whether they are marked, and the characters that keep
     * the block from being marked.
     *
     * @return array{block: string, tags: list<string>, digest: string, marked: bool, staleMark: bool, invisible: list<string>}
     */
    public function review(PromptSnippet $snippet): array
    {
        $block = $this->mark->blockOf($snippet);

        return [
            'block'     => $block,
            'tags'      => $this->mark->tagsOf($snippet),
            'digest'    => $this->mark->digestOf($snippet),
            'marked'    => $this->mark->isMarked($snippet),
            'staleMark' => $snippet->getCallerSystemDigest() !== '' && !$this->mark->isMarked($snippet),
            'invisible' => SkillInvisibleCharacters::findIn(['name' => $snippet->getName(), 'text' => $snippet->getSnippet()]),
        ];
    }

    /**
     * @param string $postedDigest the digest of the block the form rendered
     *
     * @return self::MARKED|self::REFUSED_*
     */
    public function mark(PromptSnippet $snippet, string $postedDigest): string
    {
        $current = $this->mark->digestOf($snippet);
        if ($current === '') {
            return self::REFUSED_EMPTY;
        }

        if (!hash_equals($current, $postedDigest)) {
            return self::REFUSED_STALE;
        }

        if (SkillInvisibleCharacters::findIn(['name' => $snippet->getName(), 'text' => $snippet->getSnippet()]) !== []) {
            return self::REFUSED_INVISIBLE;
        }

        $this->write($snippet, $current);

        return self::MARKED;
    }

    public function unmark(PromptSnippet $snippet): void
    {
        $this->write($snippet, '');
    }

    private function write(PromptSnippet $snippet, string $digest): void
    {
        $uid = (int) $snippet->getUid();
        if ($uid <= 0) {
            return;
        }

        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['caller_system_digest' => $digest],
            ['uid' => $uid],
            ['caller_system_digest' => Connection::PARAM_STR],
        );
        $snippet->setCallerSystemDigest($digest);

        $user = $GLOBALS['BE_USER'] ?? null;
        $this->logger->notice(
            $digest === '' ? 'Prompt snippet unmarked for caller system messages' : 'Prompt snippet marked for caller system messages',
            [
                'snippet' => $uid,
                'digest'  => $digest,
                'beUser'  => $user instanceof BackendUserAuthentication && is_array($user->user) && is_numeric($user->user['uid'] ?? null) ? (int) $user->user['uid'] : 0,
            ],
        );
    }
}

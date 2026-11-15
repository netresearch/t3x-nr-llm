<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Agent\A2a\Server\Handler;

use Netresearch\NrLlm\Domain\ValueObject\AssignmentScope;
use Netresearch\NrLlm\Domain\ValueObject\ChatMessage;
use Netresearch\NrLlm\Domain\ValueObject\ScopedPage;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aErrorCode;
use Netresearch\NrLlm\Service\Agent\A2a\Protocol\A2aMessage;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aRpcError;
use Netresearch\NrLlm\Service\Agent\A2a\Server\A2aSkillProfile;
use Netresearch\NrLlm\Service\Agent\AgentRunRequest;
use Netresearch\NrLlm\Service\Tool\ActingBackendUserResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The page metadata skill (ADR-296, spec 055): writes the named metadata
 * fields of one page in one language as a draft in the client's workspace.
 *
 * The client sends the request of {@see A2aPageMetadataRequest}. Before a task
 * exists the handler checks it, finds the row to write (the page itself for
 * language 0, its translation otherwise, as the client's workspace shows them)
 * and refuses the request when there is none: version 1 does not create a
 * translation. The run is then bound to the scope of the assignment (ADR-295):
 * the page, depth 0, the table `pages` and the one language. The scope is
 * fixed here, from the checked values, so no text of the client and no answer
 * of the model can widen it, and a queued, paused or resumed run keeps it.
 *
 * The user message of the run is built from the checked values only. The
 * field list is an instruction to the model in this handler; the write tool
 * accepts any of its fields on the scoped row, and this handler does not hold
 * the call to the list. A client that is not pinned to a draft workspace never
 * reaches this handler: the server refuses a writing profile for it first. The run
 * may use `get_page_content` and `update_page_metadata`, and `ask_choice` when
 * the profile grants it, and nothing else; it runs only when the profile grants
 * the first two and may write. Its LLM configuration must
 * require a draft workspace and a scope, so the same configuration reached
 * through another profile cannot write without them either.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class A2aPageMetadataDomainHandler implements A2aDomainHandlerInterface
{
    public const IDENTIFIER = 'nr_llm.page_metadata.v1';

    public const WRITE_TOOL = 'update_page_metadata';

    public const READ_TOOL = 'get_page_content';

    /** The one further tool the run may use when the profile grants it: a question to the client. */
    public const QUESTION_TOOL = 'ask_choice';

    /**
     * One refusal for a page that does not exist, is deleted, lies outside
     * the technical user's mounts or permissions, or a language the user may
     * not edit: a client learns nothing about pages its user may not read.
     */
    private const NOT_AVAILABLE = '"page" and "language" do not name a page this skill may work on in the client\'s workspace';

    /** The t3ver_state of a row deleted in a workspace. */
    private const DELETE_PLACEHOLDER = 2;

    private LoggerInterface $logger;

    private ActingBackendUserResolver $users;

    public function __construct(
        private ConnectionPool $connectionPool,
        ?LoggerInterface $logger = null,
        ?ActingBackendUserResolver $users = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->users  = $users ?? new ActingBackendUserResolver();
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function decide(A2aMessage $message, A2aSkillProfile $profile, A2aRunContext $context): A2aHandlerDecision
    {
        $user = $this->checkSetup($profile, $context);

        $request   = A2aPageMetadataRequest::fromText($message->text());
        $workspace = $context->actor->workspaceId ?? 0;
        $target    = $this->targetRow($request, $workspace, $user);

        $scope = new AssignmentScope([new ScopedPage($request->page, 0)], [], ['pages'], [$request->language]);

        return A2aHandlerDecision::run(new AgentRunRequest(
            $context->configuration,
            [ChatMessage::user($this->instruction($request, $target))],
            $context->actor->withAssignmentScope($scope),
            allowedToolNames: $this->tools($context),
            origin: $context->origin,
        ));
    }

    /**
     * The read and the write tool, and the question tool when the profile
     * grants it; no other tool the profile lists.
     *
     * @return list<string>
     */
    private function tools(A2aRunContext $context): array
    {
        return in_array(self::QUESTION_TOOL, $context->toolNames, true)
            ? [self::READ_TOOL, self::WRITE_TOOL, self::QUESTION_TOOL]
            : [self::READ_TOOL, self::WRITE_TOOL];
    }

    public function artifacts(string $answer): array
    {
        return [['artifactId' => Uuid::v7()->toRfc4122(), 'parts' => [['text' => $answer]]]];
    }

    /**
     * A profile, configuration or technical user that cannot run this skill
     * as decided is an error of the installation, not of the client: the
     * skill is unavailable until an administrator fixes it. A technical user
     * that cannot act in the client's pinned workspace (no member, disabled)
     * is such an error; it does not depend on the page requested.
     *
     * @throws A2aRpcError
     */
    private function checkSetup(A2aSkillProfile $profile, A2aRunContext $context): BackendUserAuthentication
    {
        $user    = $this->users->resolve($context->actor);
        $problem = match (true) {
            !$profile->mayWrite                                    => 'the profile may not write',
            !in_array(self::WRITE_TOOL, $context->toolNames, true) => 'the profile does not grant update_page_metadata',
            !in_array(self::READ_TOOL, $context->toolNames, true)  => 'the profile does not grant get_page_content',
            !$context->configuration->getRequireDraftWorkspace()   => 'its LLM configuration does not require a draft workspace',
            !$context->configuration->getRequireAssignmentScope()  => 'its LLM configuration does not require the scope of an assignment',
            default                                                => null,
        };

        if (!$user instanceof BackendUserAuthentication) {
            $this->unavailable($profile, $problem ?? "the client's technical user cannot act in its pinned workspace");
        }

        if ($problem !== null) {
            $this->unavailable($profile, $problem);
        }

        return $user;
    }

    /**
     * @throws A2aRpcError
     */
    private function unavailable(A2aSkillProfile $profile, string $problem): never
    {
        $this->logger->warning('A2A page metadata skill cannot run: ' . $problem, ['profile' => $profile->uid]);

        throw A2aRpcError::a2a(A2aErrorCode::UNSUPPORTED_OPERATION, 'This skill is not available.', A2aRpcError::REASON_PROFILE_UNAVAILABLE);
    }

    /**
     * The uid of the row the run writes: the default-language page, or its
     * translation in the requested language, as the workspace shows them.
     * Only a page the client's technical user may see, in a language it may
     * edit, is told apart, and the row to write must be one it may edit;
     * everything else gets {@see self::NOT_AVAILABLE}. The default-language
     * page of a translation is only looked at, so seeing it is enough.
     *
     * @throws A2aRpcError
     */
    private function targetRow(A2aPageMetadataRequest $request, int $workspace, BackendUserAuthentication $user): int
    {
        $page = $this->visibleRow($this->rows(['uid' => $request->page], $workspace), $workspace);
        if ($page === null || !$this->may($user, $page, Permission::PAGE_SHOW, $request->language)) {
            throw A2aRpcError::invalidParams('message.parts', self::NOT_AVAILABLE);
        }

        if ($this->int($page['sys_language_uid'] ?? 0) !== 0 || $this->int($page['l10n_parent'] ?? 0) !== 0) {
            // Told apart only for a translation in a language the user may edit.
            if (!$this->may($user, $page, Permission::PAGE_SHOW, $this->int($page['sys_language_uid'] ?? 0))) {
                throw A2aRpcError::invalidParams('message.parts', self::NOT_AVAILABLE);
            }

            throw A2aRpcError::invalidParams('message.parts', '"page" names a translation; give the uid of the default-language page');
        }

        if ($request->language === 0) {
            if (!$this->may($user, $page, Permission::PAGE_EDIT, 0)) {
                throw A2aRpcError::invalidParams('message.parts', self::NOT_AVAILABLE);
            }

            return $request->page;
        }

        $translation = $this->visibleRow($this->rows(['l10n_parent' => $request->page, 'sys_language_uid' => $request->language], $workspace), $workspace);
        if ($translation === null) {
            throw A2aRpcError::invalidParams('message.parts', 'the page has no translation in "language" in the client\'s workspace; this skill does not create one');
        }

        if (!$this->may($user, $translation, Permission::PAGE_EDIT, $request->language)) {
            throw A2aRpcError::invalidParams('message.parts', self::NOT_AVAILABLE);
        }

        return $this->int($translation['uid'] ?? 0);
    }

    /**
     * The live rows and the rows new in the workspace that match, not deleted.
     * A workspace version (t3ver_oid > 0) is read through its live row.
     *
     * @param array<string, int> $where
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $where, int $workspace): array
    {
        $query = $this->connectionPool->getQueryBuilderForTable('pages');
        $query->getRestrictions()->removeAll();
        $query->select('*')->from('pages')
            ->where(
                $query->expr()->eq('deleted', $query->createNamedParameter(0, Connection::PARAM_INT)),
                $query->expr()->eq('t3ver_oid', $query->createNamedParameter(0, Connection::PARAM_INT)),
                $query->expr()->in('t3ver_wsid', $query->createNamedParameter([0, $workspace], Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('t3ver_wsid')
            ->addOrderBy('uid');
        foreach ($where as $field => $value) {
            $query->andWhere($query->expr()->eq($field, $query->createNamedParameter($value, Connection::PARAM_INT)));
        }

        $rows = [];
        foreach ($query->executeQuery()->fetchAllAssociative() as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The first of the rows the workspace still shows. A row deleted in the
     * workspace is left out: `workspaceOL()` lays the delete placeholder
     * (t3ver_state 2) over the live row instead of dropping it.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<array-key, mixed>|null
     */
    private function visibleRow(array $rows, int $workspace): ?array
    {
        foreach ($rows as $row) {
            $uid = $this->int($row['uid'] ?? 0);
            BackendUtility::workspaceOL('pages', $row, $workspace);
            if (is_array($row) && $this->int($row['t3ver_state'] ?? 0) !== self::DELETE_PLACEHOLDER) {
                $row['uid'] = $uid;

                return $row;
            }
        }

        return null;
    }

    /**
     * Whether the technical user holds the page permission on the row and may
     * edit the language. `calcPerms()` reads the row's own permission fields
     * and answers no permission for a page outside the user's web mounts. The
     * tool checks the edit permission and the row's language again when it
     * writes.
     *
     * @param array<array-key, mixed> $page
     */
    private function may(?BackendUserAuthentication $user, array $page, int $permission, int $language): bool
    {
        if (!$user instanceof BackendUserAuthentication) {
            return false;
        }

        $row = [];
        foreach ($page as $field => $value) {
            $row[(string) $field] = $value;
        }

        return $user->doesUserHaveAccess($row, $permission)
            && $user->checkLanguageAccess($language);
    }

    private function instruction(A2aPageMetadataRequest $request, int $target): string
    {
        return implode("\n", [
            sprintf('Write page metadata for page %d in language %d.', $request->page, $request->language),
            sprintf('The record to update is pages:%d. Read it first with get_page_content (uid %d, language %d).', $target, $request->page, $request->language),
            sprintf('Then call update_page_metadata once with uid %d and exactly these fields: %s.', $target, implode(', ', $request->fields)),
            'Write each value in the language of that record. Do not change any other field or record.',
        ]);
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

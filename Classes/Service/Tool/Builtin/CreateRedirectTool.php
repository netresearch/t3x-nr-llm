<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\Enum\WriteCompleteness;
use Netresearch\NrLlm\Domain\Enum\WriteKind;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Domain\ValueObject\ToolSpec;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewTranslator;
use Netresearch\NrLlm\Service\Tool\PendingTargetInterface;
use Netresearch\NrLlm\Service\Tool\RefusesBeforeApprovalInterface;
use Netresearch\NrLlm\Service\Tool\ToolDataClassInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\LinkHandling\LinkService;

/**
 * Create ONE redirect (`sys_redirect`) through the DataHandler, as the acting
 * administrator, the way the redirects module would (ADR-257).
 *
 * Admin-only, because the redirects module's rule for everyone else — the
 * source hosts of the user's sites and access to the target page or file —
 * is checked by EXT:redirects against the AMBIENT backend user, which is the
 * approver on a resumed run, not the run's acting user (ADR-083). An
 * administrator passes that rule whoever is ambient.
 *
 * The redirect takes effect for visitors as soon as it is written: core's own
 * DataHandler hook rebuilds the redirect cache of the source host. The card
 * says so. A redirect with the same host, path and kind of match as an
 * existing one is refused and the existing uid named, so the model changes
 * that one instead of shadowing it.
 */
final readonly class CreateRedirectTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, PendingTargetInterface, ToolDataClassInterface, RefusesBeforeApprovalInterface
{
    use SafeCastTrait;
    use WritesThroughDataHandlerTrait;
    use PlansOneEditorialWriteTrait;
    use ManagesRedirectsTrait;

    private const NOT_PERMITTED = 'Redirect not found or not permitted.';

    public function __construct(
        private ConnectionPool $connectionPool,
        private ApprovalPreviewTranslator $translator,
        private LinkService $linkService,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'create_redirect',
            'Create ONE redirect in the TYPO3 redirects module: requests for source_host + source_path are sent to '
            . 'target with target_statuscode. Takes effect for visitors as soon as it is approved. Admin only; '
            . 'needs EXT:redirects. Refused when a redirect for the same host and path exists: change that one with '
            . 'update_redirect (list_redirects finds it).',
            [
                'type'       => 'object',
                'properties' => $this->redirectFieldSchema(),
                'required'   => ['source_path', 'target'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $this->redirectWriter($arguments, $context);
        $plan = $user instanceof ToolResult ? $user : $this->plan($arguments, $user);
        if (!is_array($plan)) {
            return $plan instanceof ToolResult ? $plan : ToolResult::error($plan);
        }

        $record = ['pid' => 0] + $plan['fields'];
        if (isset($this->redirectColumns()['createdby'])) {
            $record['createdby'] = self::toInt($user->user['uid'] ?? 0);
        }

        $created = $this->createRecord(
            self::REDIRECT_TABLE,
            $record,
            $user,
            'No redirect was created. TYPO3 refused the record without an error; a hook of the installation may have dropped it.',
        );
        if ($created instanceof ToolResult) {
            return $created;
        }

        [$uid, $complaints] = $created;

        // Read back: EXT:redirects' own guard empties a record it refuses and
        // only logs it, so a uid alone does not prove the fields arrived.
        $stored = $this->fetchRowByUid(self::REDIRECT_TABLE, $uid);
        if ($stored === null) {
            return ToolResult::error(sprintf('Redirect [%d] was created but cannot be read back. Check it in the redirects module.%s', $uid, $complaints));
        }

        // The row exists, so it is named as written — only not as asked.
        if (!$this->redirectHolds($stored, $plan['fields'])) {
            return ToolResult::text(sprintf(
                'Redirect [%d] was created but does not hold the requested values: a hook of the installation changed it. '
                . 'Check it in the redirects module before relying on it.%s',
                $uid,
                $complaints,
            ))->withWriteTarget(new RecordReference(self::REDIRECT_TABLE, $uid), WriteKind::CREATED, WriteCompleteness::PARTIAL);
        }

        return ToolResult::text(sprintf(
            'Created redirect [%d] %s (%d). It applies to visitors now.%s',
            $uid,
            $this->redirectInEnglish($plan['fields']),
            self::toInt($plan['fields']['target_statuscode'] ?? 0),
            $complaints,
        ))->withWriteTarget(new RecordReference(self::REDIRECT_TABLE, $uid), WriteKind::CREATED, WriteCompleteness::COMPLETE);
    }

    /**
     * The new redirect, field by field, and that it applies at once.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        $user = $this->redirectPreviewer($arguments, $context);
        $plan = is_string($user) ? $user : $this->plan($arguments, $user);
        if (is_string($plan) || is_string($user)) {
            return [is_string($user) ? $user : self::toStr($plan)];
        }

        [$t, $q]      = $this->translator->boundTo($user, $this->excerpt(...));
        [$fieldLines] = $this->redirectFieldLines($t, $q, $user, $plan['fields'], null);

        return [
            $t(ApprovalPreviewLabel::RedirectCreateHeading),
            ...$fieldLines,
            $t(ApprovalPreviewLabel::RedirectAppliesAtOnce),
            $this->translator->technical($user, [$t(ApprovalPreviewLabel::TechnicalRecord, self::REDIRECT_TABLE, 0)]),
        ];
    }

    public function getEffect(): ToolEffect
    {
        // A second call creates a second record.
        return ToolEffect::NON_IDEMPOTENT_WRITE;
    }

    /**
     * A creation names no existing record (ADR-214).
     */
    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        return null;
    }

    /**
     * The fields of the new redirect, checked — or the refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{fields: array<string, int|string>}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        if (!$user->isAdmin()) {
            return self::NOT_PERMITTED;
        }

        $unknown = $this->refuseUnknownArguments($arguments, self::REDIRECT_FIELDS, 'creates one redirect');
        if ($unknown !== null) {
            return $unknown;
        }

        $fields = $this->redirectFieldsAfter($arguments, [
            'source_host'       => '*',
            'target_statuscode' => $this->redirectDefaultStatusCode(),
        ]);
        if (is_string($fields)) {
            return $fields;
        }

        $duplicates = $this->redirectDuplicates($fields, 0);
        if ($duplicates !== []) {
            return sprintf(
                'Refused: redirect [%d] already covers host %s and path %s. Change it with update_redirect instead of adding a second one.',
                $duplicates[0],
                self::toStr($fields['source_host']),
                $this->excerpt(self::toStr($fields['source_path'])),
            );
        }

        return ['fields' => $fields];
    }
}

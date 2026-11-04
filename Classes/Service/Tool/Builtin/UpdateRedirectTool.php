<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
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
use Netresearch\NrLlm\Service\Tool\ToolDataClassInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectInterface;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use Netresearch\NrLlm\Service\Tool\ToolInterface;
use Netresearch\NrLlm\Service\Tool\ToolPreviewInterface;
use Netresearch\NrLlm\Utility\SafeCastTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Change the fields of ONE existing redirect, through the DataHandler, as the
 * acting administrator (ADR-257).
 *
 * The call names the fields it changes; every other field stays. The result
 * is checked as a whole against the rules of {@see ManagesRedirectsTrait}, so
 * a changed target is refused when it would loop on the unchanged source. A
 * disabled redirect can be enabled again with `disabled: false`; disabling
 * is {@see DisableRedirectTool}'s one job. QR codes and short URLs (TYPO3 14)
 * are refused: their fields mean something else.
 */
final readonly class UpdateRedirectTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, PendingTargetInterface, ToolDataClassInterface
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
            'update_redirect',
            'Change ONE existing redirect of the TYPO3 redirects module: pass its uid and only the fields to change; '
            . 'every other field stays. disabled: false enables a disabled redirect again. Takes effect for visitors '
            . 'as soon as it is approved. Admin only; needs EXT:redirects. list_redirects finds the uid.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the redirect to change.',
                    ],
                    ...$this->redirectFieldSchema(),
                    'disabled' => [
                        'type'        => 'boolean',
                        'description' => 'false enables a disabled redirect again. To disable one, use disable_redirect.',
                    ],
                ],
                'required' => ['uid'],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $missing = $this->redirectsMissing();
        if ($missing !== null) {
            return ToolResult::error($missing);
        }

        $user = $this->writableActingUser($context, self::REDIRECT_TABLE);
        if ($user instanceof ToolResult) {
            return $user;
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        if ($plan['changes'] === []) {
            return ToolResult::text(sprintf(
                'Redirect [%d] %s already holds these values; nothing was written.',
                $plan['uid'],
                $this->redirectInEnglish($plan['after']),
            ));
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([self::REDIRECT_TABLE => [$plan['uid'] => $plan['changes']]], [], $user);
        $dataHandler->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        $stored = $this->fetchRowByUid(self::REDIRECT_TABLE, $plan['uid']);
        if ($stored === null || !$this->redirectHolds($stored, $plan['changes'])) {
            return ToolResult::error(sprintf(
                'Redirect [%d] does not hold the requested values after the write. A hook of the installation may have '
                . 'dropped them; check the redirect in the redirects module.',
                $plan['uid'],
            ));
        }

        return ToolResult::text(sprintf(
            'Changed %s of redirect [%d]; it is now %s. It applies to visitors now.',
            implode(', ', array_keys($plan['changes'])),
            $plan['uid'],
            $this->redirectInEnglish($plan['after']),
        ))->withWriteTarget(new RecordReference(self::REDIRECT_TABLE, $plan['uid']), WriteKind::UPDATED, WriteCompleteness::COMPLETE);
    }

    /**
     * The redirect as it is, and each field the call names with its current
     * and its proposed value.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        $missing = $this->redirectsMissing();
        if ($missing !== null) {
            return [$missing];
        }

        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return [self::NOT_PERMITTED];
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return [$plan];
        }

        [$t, $q] = $this->translator->boundTo($user, $this->excerpt(...));

        $named                    = array_intersect_key($plan['after'], $plan['named']);
        [$fieldLines, $technical] = $this->redirectFieldLines($t, $q, $user, $named, $plan['before']);

        $lines = [
            $t(ApprovalPreviewLabel::RedirectUpdateHeading),
            $t(ApprovalPreviewLabel::RedirectObject, $q($this->redirectSourceOf($plan['before'])), $q(self::toStr($plan['before']['target'] ?? ''))),
            ...$fieldLines,
        ];
        if (isset($plan['named']['disabled'])) {
            $lines[] = $t($plan['wasDisabled'] ? ApprovalPreviewLabel::RedirectEnables : ApprovalPreviewLabel::RedirectAlreadyEnabled);
        }

        $lines[] = $t($plan['changes'] === [] ? ApprovalPreviewLabel::RedirectNothingChanges : ApprovalPreviewLabel::RedirectAppliesAtOnce);
        $lines[] = $this->translator->technical($user, [$t(ApprovalPreviewLabel::TechnicalRecord, self::REDIRECT_TABLE, $plan['uid']), ...$technical]);

        return $lines;
    }

    public function isEnabledByDefault(): bool
    {
        // A writing tool is never on by default (ADR-134/135).
        return false;
    }

    public function requiresAdmin(): bool
    {
        return true;
    }

    public function getGroup(): string
    {
        // The writers' own group (ADR-135).
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // Setting fields to given values converges: a repeat writes nothing.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    public function getDataClass(): ToolDataClass
    {
        return ToolDataClass::INTERNAL_CONFIGURATION;
    }

    /**
     * The redirect and the fields the call names (ADR-214).
     */
    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        $fields = array_values(array_intersect([...self::REDIRECT_FIELDS, 'disabled'], array_keys($arguments)));
        sort($fields);

        // A call that names no field it writes writes nothing.
        return $fields === [] ? null : PendingWriteTarget::fromArguments(self::REDIRECT_TABLE, $arguments['uid'] ?? null, $fields);
    }

    /**
     * The redirect, its fields before and after, and what changes — or the
     * refusal.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array{uid: int, before: array<string, int|string>, after: array<string, int|string>, named: array<string, true>, changes: array<string, int|string>, wasDisabled: bool}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        if (!$user->isAdmin()) {
            return self::NOT_PERMITTED;
        }

        $unknown = $this->refuseUnknownArguments($arguments, ['uid', ...self::REDIRECT_FIELDS, 'disabled'], 'changes fields of one redirect');
        if ($unknown !== null) {
            return $unknown;
        }

        $row = $this->existingRedirect($arguments['uid'] ?? null);
        if (is_string($row)) {
            return $row;
        }

        $before = $this->redirectFieldsOf($row);
        $after  = $this->redirectFieldsAfter($arguments, $row);
        if (is_string($after)) {
            return $after;
        }

        $named = [];
        foreach (array_keys($arguments) as $key) {
            if ($key !== 'uid') {
                $named[self::toStr($key)] = true;
            }
        }

        if ($named === []) {
            return 'Refused: name at least one field to change besides "uid".';
        }

        $wasDisabled = self::toInt($row['disabled'] ?? 0) === 1;
        $changes     = array_diff_assoc(array_map(strval(...), $after), array_map(strval(...), $before));
        $changes     = array_intersect_key($after, $changes);
        if (array_key_exists('disabled', $arguments)) {
            if ($arguments['disabled'] !== false) {
                return 'Refused: "disabled" takes only false here, to enable a redirect again. Disable one with disable_redirect.';
            }

            if ($wasDisabled) {
                $changes['disabled'] = 0;
            }
        }

        $duplicates = $this->redirectDuplicates($after, self::toInt($row['uid'] ?? 0));
        if ($duplicates !== [] && (isset($changes['source_host']) || isset($changes['source_path']) || isset($changes['is_regexp']))) {
            return sprintf(
                'Refused: redirect [%d] already covers host %s and path %s; two redirects for the same request shadow each other.',
                $duplicates[0],
                self::toStr($after['source_host'] ?? ''),
                $this->excerpt(self::toStr($after['source_path'] ?? '')),
            );
        }

        return [
            'uid'         => self::toInt($row['uid'] ?? 0),
            'before'      => $before,
            'after'       => $after,
            'named'       => $named,
            'changes'     => $changes,
            'wasDisabled' => $wasDisabled,
        ];
    }

    /**
     * Host and path of a redirect in one value.
     *
     * @param array<string, int|string> $fields
     */
    private function redirectSourceOf(array $fields): string
    {
        return self::toStr($fields['source_host'] ?? '') . self::toStr($fields['source_path'] ?? '');
    }
}

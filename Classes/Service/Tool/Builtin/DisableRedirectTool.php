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
 * Disable ONE redirect: set its `disabled` flag, as the redirects module's
 * toggle does, through the DataHandler as the acting administrator (ADR-257).
 *
 * The record stays, with its hit counter and its history, and
 * `update_redirect` with `disabled: false` enables it again; nothing is
 * deleted. A redirect that is already disabled is not written.
 */
final readonly class DisableRedirectTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, PendingTargetInterface, ToolDataClassInterface
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
            'disable_redirect',
            'Disable ONE redirect of the TYPO3 redirects module, so visitors are no longer redirected. The record stays '
            . 'and update_redirect with disabled: false enables it again. Admin only; needs EXT:redirects.',
            [
                'type'       => 'object',
                'properties' => [
                    'uid' => [
                        'type'        => 'integer',
                        'description' => 'The uid of the redirect to disable (list_redirects finds it).',
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

        if ($plan['disabled']) {
            return ToolResult::text(sprintf(
                'Redirect [%d] %s is already disabled; nothing was written.',
                $plan['uid'],
                $this->redirectInEnglish($plan['fields']),
            ));
        }

        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([self::REDIRECT_TABLE => [$plan['uid'] => ['disabled' => 1]]], [], $user);
        $dataHandler->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        $stored = $this->fetchRowByUid(self::REDIRECT_TABLE, $plan['uid'], 'disabled');
        if ($stored === null || self::toInt($stored['disabled'] ?? 0) !== 1) {
            return ToolResult::error(sprintf(
                'Redirect [%d] is still enabled after the write. A hook of the installation may have kept it; check it in the redirects module.',
                $plan['uid'],
            ));
        }

        return ToolResult::text(sprintf(
            'Disabled redirect [%d] %s. Visitors are no longer redirected; update_redirect with disabled: false enables it again.',
            $plan['uid'],
            $this->redirectInEnglish($plan['fields']),
        ))->withWriteTarget(new RecordReference(self::REDIRECT_TABLE, $plan['uid']), WriteKind::TEXT_UNCHANGED, WriteCompleteness::COMPLETE);
    }

    /**
     * Which redirect stops, and that it can be enabled again.
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

        return [
            $t(ApprovalPreviewLabel::RedirectDisableHeading),
            $t(
                ApprovalPreviewLabel::RedirectObject,
                $q(self::toStr($plan['fields']['source_host'] ?? '') . self::toStr($plan['fields']['source_path'] ?? '')),
                $q(self::toStr($plan['fields']['target'] ?? '')),
            ),
            $t($plan['disabled'] ? ApprovalPreviewLabel::RedirectAlreadyDisabled : ApprovalPreviewLabel::RedirectDisableChange),
            $this->translator->technical($user, [$t(ApprovalPreviewLabel::TechnicalRecord, self::REDIRECT_TABLE, $plan['uid'])]),
        ];
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
        // Setting one flag to 1 converges: a repeat finds it at 1.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    public function getDataClass(): ToolDataClass
    {
        return ToolDataClass::INTERNAL_CONFIGURATION;
    }

    /**
     * The redirect whose flag the call sets (ADR-214).
     */
    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        return PendingWriteTarget::fromArguments(self::REDIRECT_TABLE, $arguments['uid'] ?? null, ['disabled']);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{uid: int, fields: array<string, int|string>, disabled: bool}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        if (!$user->isAdmin()) {
            return self::NOT_PERMITTED;
        }

        $unknown = $this->refuseUnknownArguments($arguments, ['uid'], 'disables one redirect');
        if ($unknown !== null) {
            return $unknown;
        }

        $row = $this->existingRedirect($arguments['uid'] ?? null);
        if (is_string($row)) {
            return $row;
        }

        return [
            'uid'      => self::toInt($row['uid'] ?? 0),
            'fields'   => $this->redirectFieldsOf($row),
            'disabled' => self::toInt($row['disabled'] ?? 0) === 1,
        ];
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\Enum\ToolEffect;
use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
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
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Clear TYPO3 caches by one of TYPO3's own cache groups, or the page caches
 * by tag, as the acting administrator (ADR-257).
 *
 * `pages`, `all` and tags go through core's own command,
 * `DataHandler::clear_cacheCmd()`, which is what the backend's clear-cache
 * menu calls: the user TSconfig `options.clearCache.all` that keeps an
 * administrator from flushing everything is honoured, the flush is written to
 * the system log under the acting user, and the hooks extensions register
 * for it run (EXT:redirects rebuilds its redirect cache there). Any other
 * group the installation configures (`system`, an extension's own) is
 * flushed through the cache manager, as the install tool does.
 *
 * `all` is a full flush, and the card says so in its heading and in a line of
 * its own: every cache is emptied, the system caches included, and the next
 * requests are slow while they fill again. The card lists the caches a group
 * empties by name, from the configuration — never entry counts or times,
 * which change between the pause and the resume (ADR-184).
 */
final readonly class ClearCacheTool implements ToolInterface, ToolEffectInterface, ToolPreviewInterface, PendingTargetInterface, ToolDataClassInterface
{
    use SafeCastTrait;

    private const NOT_PERMITTED = 'Clearing caches requires an administrator.';

    private const FULL_FLUSH = 'all';

    private const PAGE_GROUP = 'pages';

    private const MAX_TAGS = 20;

    private const MAX_LISTED_CACHES = 30;

    public function __construct(
        private CacheManager $cacheManager,
        private ApprovalPreviewTranslator $translator,
    ) {}

    public function getSpec(): ToolSpec
    {
        return ToolSpec::function(
            'clear_cache',
            'Clear TYPO3 caches, as the backend clear-cache menu does: either one cache group (pages for the frontend '
            . 'page caches, system, or another group this TYPO3 configures; all is a FULL flush of every cache and '
            . 'makes the next requests slow), or the page caches by tag (pageId_12 for page 12). Pass group OR tags. '
            . 'Admin only.',
            [
                'type'       => 'object',
                'properties' => [
                    'group' => [
                        'type'        => 'string',
                        'description' => 'A cache group: pages, system, all (full flush), or another configured group.',
                    ],
                    'tags' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'description' => 'Page cache tags to clear, for example pageId_12. At most 20.',
                    ],
                ],
            ],
        );
    }

    public function execute(array $arguments, ToolExecutionContext $context): ToolResult
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return ToolResult::error(self::NOT_PERMITTED);
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return ToolResult::error($plan);
        }

        if ($plan['tags'] !== []) {
            $dataHandler = $this->dataHandlerFor($user);
            foreach ($plan['tags'] as $tag) {
                $dataHandler->clear_cacheCmd('cacheTag:' . $tag);
            }

            return ToolResult::text(sprintf(
                'Cleared the entries tagged %s in the page caches (%s).',
                implode(', ', $plan['tags']),
                implode(', ', $plan['caches']),
            ));
        }

        if (in_array($plan['group'], [self::FULL_FLUSH, self::PAGE_GROUP], true)) {
            $this->dataHandlerFor($user)->clear_cacheCmd($plan['group']);
        } else {
            $this->cacheManager->flushCachesInGroup($plan['group']);
        }

        return ToolResult::text($plan['group'] === self::FULL_FLUSH
            ? sprintf('Flushed ALL caches of this TYPO3 (%d). The next requests rebuild them.', count($plan['caches']))
            : sprintf('Cleared the cache group "%s": %s.', $plan['group'], $plan['caches'] === [] ? 'no cache belongs to it' : implode(', ', $plan['caches'])));
    }

    /**
     * What the call empties: the group and its caches by name, or the tags
     * and the page caches; for `all`, that it is a full flush.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    public function previewCall(array $arguments, ToolExecutionContext $context): array
    {
        $user = $context->actingBackendUser();
        if (!$user instanceof BackendUserAuthentication) {
            return [self::NOT_PERMITTED];
        }

        $plan = $this->plan($arguments, $user);
        if (is_string($plan)) {
            return [$plan];
        }

        $t = fn (ApprovalPreviewLabel $label, int|string ...$values): string => $this->translator->text($user, $label, ...$values);

        if ($plan['tags'] !== []) {
            return [
                $t(ApprovalPreviewLabel::CacheTagsHeading),
                $t(ApprovalPreviewLabel::CacheTags, implode(', ', $plan['tags'])),
                $t(ApprovalPreviewLabel::CacheCaches, $this->listed($plan['caches'])),
                $t(ApprovalPreviewLabel::CacheRefills),
            ];
        }

        if ($plan['group'] === self::FULL_FLUSH) {
            return [
                $t(ApprovalPreviewLabel::CacheAllHeading),
                $t(ApprovalPreviewLabel::CacheAllWarning, count($plan['caches'])),
                $t(ApprovalPreviewLabel::CacheRefills),
            ];
        }

        return [
            $t(ApprovalPreviewLabel::CacheGroupHeading),
            $t(ApprovalPreviewLabel::CacheGroup, $plan['group']),
            $plan['caches'] === [] ? $t(ApprovalPreviewLabel::CacheNone) : $t(ApprovalPreviewLabel::CacheCaches, $this->listed($plan['caches'])),
            $t(ApprovalPreviewLabel::CacheRefills),
        ];
    }

    public function mayViewerReadPreview(array $arguments, BackendUserAuthentication $viewer): bool
    {
        return $viewer->isAdmin();
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
        // The writers' own group (ADR-135): a configuration that grants the
        // read-only system tools does not gain a flush with them.
        return 'editing';
    }

    public function getEffect(): ToolEffect
    {
        // Emptying a cache twice leaves it empty.
        return ToolEffect::IDEMPOTENT_WRITE;
    }

    public function getDataClass(): ToolDataClass
    {
        return ToolDataClass::INTERNAL_CONFIGURATION;
    }

    /**
     * A flush names no record (ADR-214).
     */
    public function pendingTarget(array $arguments): ?PendingWriteTarget
    {
        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{group: string, tags: list<string>, caches: list<string>}|string
     */
    private function plan(array $arguments, BackendUserAuthentication $user): array|string
    {
        if (!$user->isAdmin()) {
            return self::NOT_PERMITTED;
        }

        foreach (array_keys($arguments) as $key) {
            if (!in_array($key, ['group', 'tags'], true)) {
                return sprintf(
                    'Refused: "%s" is not an argument of this tool. It clears one cache group or page caches by tag; allowed: group, tags.',
                    preg_replace('/[^A-Za-z0-9_]/', '', self::toStr($key)) ?? '',
                );
            }
        }

        $hasGroup = isset($arguments['group']) && $arguments['group'] !== '';
        $hasTags  = isset($arguments['tags']) && $arguments['tags'] !== [];
        if ($hasGroup === $hasTags) {
            return 'Refused: pass either "group" or "tags", not both and not neither.';
        }

        if ($hasTags) {
            return $this->tagPlan($arguments['tags']);
        }

        $group  = self::toStr($arguments['group']);
        $groups = $this->configuredGroups();
        if ($group !== self::FULL_FLUSH && !isset($groups[$group])) {
            return sprintf(
                'Refused: "%s" is not a cache group of this TYPO3. Groups: all (full flush), %s.',
                preg_replace('/[^A-Za-z0-9_.-]/', '', $group) ?? '',
                implode(', ', array_keys($groups)),
            );
        }

        if ($group === self::FULL_FLUSH && !$this->mayFlushEverything($user)) {
            return 'Refused: the user TSconfig option options.clearCache.all = 0 keeps this administrator from flushing all caches, '
                . 'as it hides the entry in the backend menu. Clear a group instead.';
        }

        return [
            'group'  => $group,
            'tags'   => [],
            'caches' => $group === self::FULL_FLUSH ? $this->allCaches() : ($groups[$group] ?? []),
        ];
    }

    /**
     * @return array{group: string, tags: list<string>, caches: list<string>}|string
     */
    private function tagPlan(mixed $tags): array|string
    {
        if (!is_array($tags) || !array_is_list($tags) || count($tags) > self::MAX_TAGS) {
            return sprintf('Refused: "tags" must be a list of 1 to %d tags.', self::MAX_TAGS);
        }

        $clean = [];
        foreach ($tags as $tag) {
            if (!is_string($tag) || preg_match(FrontendInterface::PATTERN_TAG, $tag) !== 1) {
                return 'Refused: a cache tag holds only letters, digits, _, %, - and &, at most 250 characters (pageId_12).';
            }

            $clean[$tag] = $tag;
        }

        sort($clean);

        return [
            'group'  => self::PAGE_GROUP,
            'tags'   => $clean,
            'caches' => $this->configuredGroups()[self::PAGE_GROUP] ?? [],
        ];
    }

    /**
     * Core's own rule from the clear-cache menu: an administrator may flush
     * everything unless their user TSconfig sets `options.clearCache.all = 0`.
     */
    private function mayFlushEverything(BackendUserAuthentication $user): bool
    {
        $options    = $user->getTSConfig()['options.'] ?? null;
        $clearCache = is_array($options) ? ($options['clearCache.'] ?? null) : null;
        $option     = is_array($clearCache) ? ($clearCache['all'] ?? null) : null;

        return $option === null || (bool) $option;
    }

    /**
     * The cache groups this TYPO3 configures and the caches in each, sorted.
     *
     * @return array<string, list<string>>
     */
    private function configuredGroups(): array
    {
        $groups = [];
        foreach ($this->cacheConfigurations() as $identifier => $configuration) {
            $memberOf = is_array($configuration) ? ($configuration['groups'] ?? ['all']) : ['all'];
            foreach (is_array($memberOf) ? $memberOf : [] as $group) {
                if (is_string($group) && $group !== '' && $group !== self::FULL_FLUSH) {
                    $groups[$group][] = (string) $identifier;
                }
            }
        }

        ksort($groups);
        foreach ($groups as &$caches) {
            sort($caches);
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    private function allCaches(): array
    {
        $caches = array_map(strval(...), array_keys($this->cacheConfigurations()));
        sort($caches);

        return $caches;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function cacheConfigurations(): array
    {
        $value = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        foreach (['SYS', 'caching', 'cacheConfigurations'] as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        $configurations = $value;

        return is_array($configurations) ? $configurations : [];
    }

    /**
     * @param list<string> $caches
     */
    private function listed(array $caches): string
    {
        if (count($caches) <= self::MAX_LISTED_CACHES) {
            return implode(', ', $caches);
        }

        return implode(', ', array_slice($caches, 0, self::MAX_LISTED_CACHES)) . sprintf(' (+%d)', count($caches) - self::MAX_LISTED_CACHES);
    }

    private function dataHandlerFor(BackendUserAuthentication $user): ToolDataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([], [], $user);

        return $dataHandler;
    }
}

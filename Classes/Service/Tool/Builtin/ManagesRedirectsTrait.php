<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Tool\Builtin;

use Closure;
use Exception;
use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Domain\ValueObject\ToolResult;
use Netresearch\NrLlm\Service\Tool\ApprovalPreviewLabel;
use Netresearch\NrLlm\Service\Tool\ToolExecutionContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Redirects\Evaluation\SourceHost;

/**
 * What the three redirect writers share (ADR-257): the table, the fields a
 * call may set, the rules EXT:redirects applies to them, the lookup of an
 * existing redirect and the lines the approval card shows for one.
 *
 * The DECISIONS stay in each tool — which fields a call takes, what it
 * refuses beyond these rules, what it writes. This trait holds only what would
 * otherwise be written three times.
 *
 * The rules are core's where core has one, read from the live TCA so the
 * TYPO3 13.4 and 14.3 schemas are both honoured: the source host goes through
 * EXT:redirects' own `SourceHost` evaluation, the path length through the
 * column's `max`, the status code through the column's items. Five rules are
 * this tool's own, because the module accepts the value and the redirect then
 * never works: a plain path without a leading slash matches no request, a
 * plain path with a query string matches none unless the redirect respects
 * query parameters, and one without a query string none when it does, a
 * regular expression that does not compile matches nothing, and a target that
 * is exactly its own source loops.
 *
 * The approval card shows the fields a visitor's redirect depends on and
 * nothing that changes by itself: no hit counter, no last-hit time, no
 * modification time and no integrity status. Those change with traffic and
 * with the integrity command between the pause and the resume, and the card is
 * compared byte for byte when the run resumes (ADR-184).
 */
trait ManagesRedirectsTrait
{
    private const REDIRECT_TABLE = 'sys_redirect';

    /**
     * The fields a call may set, in the order the card shows them. A field the
     * running core's TCA does not declare is
     * left out of the card and refused as an argument.
     */
    private const REDIRECT_FIELDS = [
        'source_host',
        'source_path',
        'is_regexp',
        'respect_query_parameters',
        'target',
        'target_statuscode',
        'force_https',
        'keep_query_parameters',
        'description',
    ];

    private const REDIRECT_FLAGS = ['is_regexp', 'respect_query_parameters', 'force_https', 'keep_query_parameters'];

    /** The status codes EXT:redirects offers, for the schema; the live TCA decides. */
    private const REDIRECT_STATUS_CODES = [301, 302, 303, 307, 308];

    private const REDIRECT_DESCRIPTION_LIMIT = 2000;

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

    public function getDataClass(): ToolDataClass
    {
        return ToolDataClass::INTERNAL_CONFIGURATION;
    }

    /**
     * When a redirect serves visitors after a write, as the card's last line
     * says it and as the answer to the model says it: at once; only within its
     * start and stop times; or not at all while it stays disabled. The times
     * are named, never judged against the clock, so the card does not change
     * between the pause and the resume (ADR-184).
     *
     * @param array<string, mixed> $row the stored redirect, or [] for a new one
     *
     * @return array{ApprovalPreviewLabel, list<string>, string} the card's label, its values and the English sentence
     */
    private function redirectApplies(array $row, bool $disabledAfter): array
    {
        if ($disabledAfter) {
            return [ApprovalPreviewLabel::RedirectStaysDisabled, [], 'It stays disabled; update_redirect with disabled: false applies it.'];
        }

        $start = self::toInt($row['starttime'] ?? 0);
        $stop  = self::toInt($row['endtime'] ?? 0);
        if ($start === 0 && $stop === 0) {
            return [ApprovalPreviewLabel::RedirectAppliesAtOnce, [], 'It applies to visitors now.'];
        }

        $from  = $start > 0 ? gmdate('Y-m-d\TH:i:s\Z', $start) : '—';
        $until = $stop > 0 ? gmdate('Y-m-d\TH:i:s\Z', $stop) : '—';

        return [
            ApprovalPreviewLabel::RedirectTimed,
            [$from, $until],
            sprintf('It applies only between its start time %s and its stop time %s (UTC), which the redirects module sets.', $from, $until),
        ];
    }

    private function now(): int
    {
        return self::toInt(GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp'));
    }

    /**
     * The administrator a redirect write runs as, or the refusal: no
     * EXT:redirects, no acting user, no backend environment, a draft
     * workspace.
     */
    private function redirectWriter(ToolExecutionContext $context): BackendUserAuthentication|ToolResult
    {
        $missing = $this->redirectsMissing();

        return $missing === null ? $this->writableActingUser($context, self::REDIRECT_TABLE) : ToolResult::error($missing);
    }

    /**
     * The user a card is written for, or the line that stands for the card.
     */
    private function redirectPreviewer(ToolExecutionContext $context): BackendUserAuthentication|string
    {
        $user = $context->actingBackendUser();

        return $this->redirectsMissing() ?? ($user instanceof BackendUserAuthentication ? $user : self::NOT_PERMITTED);
    }

    /**
     * Write `$changes` to one redirect through the DataHandler and read them
     * back — or the refusal. EXT:redirects' own guard empties a record it
     * refuses and only logs that, so the read-back is what proves the write.
     *
     * @param array<string, int|string> $changes
     * @param string                    $whenNotHeld completes "Redirect [uid] …" when the read-back differs
     */
    private function writeRedirect(int $uid, array $changes, BackendUserAuthentication $user, string $whenNotHeld): ?ToolResult
    {
        $dataHandler = GeneralUtility::makeInstance(ToolDataHandler::class);
        $dataHandler->start([self::REDIRECT_TABLE => [$uid => $changes]], [], $user);
        $dataHandler->process_datamap();

        $refused = $this->refuseOnDataHandlerErrors($dataHandler);
        if ($refused instanceof ToolResult) {
            return $refused;
        }

        $stored = $this->fetchRowByUid(self::REDIRECT_TABLE, $uid);

        return $stored !== null && $this->redirectHolds($stored, $changes)
            ? null
            : ToolResult::error(sprintf(
                'Redirect [%d] %s after the write. A hook of the installation may have changed it; check the redirect in the redirects module.',
                $uid,
                $whenNotHeld,
            ));
    }

    /**
     * The refusal when EXT:redirects is not installed, or null when its table
     * is there.
     */
    private function redirectsMissing(): ?string
    {
        return is_array($this->redirectColumns()) && class_exists(SourceHost::class)
            ? null
            : 'Refused: EXT:redirects (typo3/cms-redirects) is not installed in this TYPO3, so there are no redirects to manage.';
    }

    /**
     * The JSON schema of the redirect fields, for the specs of the create and
     * the update tool.
     *
     * @return array<string, array<string, mixed>>
     */
    private function redirectFieldSchema(): array
    {
        return [
            'source_host' => [
                'type'        => 'string',
                'description' => 'The host the redirect applies to, for example www.example.com, or * for every host.',
            ],
            'source_path' => [
                'type'        => 'string',
                'description' => 'The path to redirect, starting with a slash (/old-page). With is_regexp, a complete '
                    . 'regular expression with delimiters (#^/old/(.*)$#).',
            ],
            'is_regexp' => [
                'type'        => 'boolean',
                'description' => 'Whether source_path is a regular expression.',
            ],
            'respect_query_parameters' => [
                'type'        => 'boolean',
                'description' => 'Whether the query string must match too.',
            ],
            'target' => [
                'type'        => 'string',
                'description' => 'Where to send the visitor: a TYPO3 link (t3://page?uid=12, t3://file?uid=3), an '
                    . 'absolute http(s) URL, or a path on the same host starting with a slash.',
            ],
            'target_statuscode' => [
                'type'        => 'integer',
                'enum'        => self::REDIRECT_STATUS_CODES,
                'description' => 'The HTTP status: 301 or 308 for a permanent move, 302, 303 or 307 for a temporary one.',
            ],
            'force_https' => [
                'type'        => 'boolean',
                'description' => 'Whether to send the visitor to https.',
            ],
            'keep_query_parameters' => [
                'type'        => 'boolean',
                'description' => 'Whether to pass the query string on to the target.',
            ],
            'description' => [
                'type'        => 'string',
                'description' => 'A note for editors.',
            ],
        ];
    }

    /**
     * The redirect fields `$arguments` names, checked against EXT:redirects'
     * rules and laid over `$base` — or the refusal.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $base      the stored row, or the defaults of a new one
     *
     * @return array<string, int|string>|string the complete field set after the call
     */
    private function redirectFieldsAfter(array $arguments, array $base): array|string
    {
        $fields = $this->redirectFieldsOf($base);
        $named  = [];
        foreach (self::REDIRECT_FIELDS as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }

            if (!isset($fields[$field])) {
                return sprintf('Refused: this TYPO3 declares no redirect field "%s".', $field);
            }

            $value = $this->redirectArgumentValue($field, $arguments[$field]);
            if (is_string($value) && str_starts_with($value, 'Refused: ')) {
                return $value;
            }

            $fields[$field] = is_string($value) ? trim($value) : $value;
            $named[$field]  = true;
        }

        // A new redirect is checked whole; a change only where it touches a
        // rule, so a stored value the call leaves alone never blocks it.
        return $this->redirectFieldsRefusal($fields, $base === [] || !isset($base['uid']) ? null : $named) ?? $fields;
    }

    /**
     * One argument as the field stores it, or the refusal (a string starting
     * with "Refused: ").
     */
    private function redirectArgumentValue(string $field, mixed $value): int|string
    {
        if (in_array($field, self::REDIRECT_FLAGS, true)) {
            return is_bool($value) ? (int) $value : sprintf('Refused: "%s" must be true or false.', $field);
        }

        if ($field === 'target_statuscode') {
            $code = is_int($value) || (is_string($value) && MathUtility::canBeInterpretedAsInteger($value)) ? (int) $value : 0;

            return in_array($code, $this->redirectStatusCodes(), true)
                ? $code
                : sprintf('Refused: "target_statuscode" must be one of %s.', implode(', ', $this->redirectStatusCodes()));
        }

        return is_string($value) ? $value : sprintf('Refused: "%s" must be a string.', $field);
    }

    /**
     * The redirect fields the running core declares, as `$base` holds them —
     * unchecked: a stored redirect is what it is.
     *
     * @param array<string, mixed> $base
     *
     * @return array<string, int|string>
     */
    private function redirectFieldsOf(array $base): array
    {
        $columns = $this->redirectColumns() ?? [];
        $fields  = [];
        foreach (self::REDIRECT_FIELDS as $field) {
            if (!isset($columns[$field])) {
                continue;
            }

            $fields[$field] = in_array($field, self::REDIRECT_FLAGS, true) || $field === 'target_statuscode'
                ? self::toInt($base[$field] ?? 0)
                : self::toStr($base[$field] ?? '');
        }

        return $fields;
    }

    /**
     * Why a field set would not work as a redirect, or null. `$named` lists
     * the fields a change sets; null checks every rule.
     *
     * @param array<string, int|string> $fields
     * @param array<string, true>|null  $named
     */
    private function redirectFieldsRefusal(array $fields, ?array $named): ?string
    {
        $touches = static fn (string ...$any): bool => $named === null || array_intersect_key($named, array_flip($any)) !== [];

        $host = self::toStr($fields['source_host'] ?? '');
        if ($touches('source_host') && ($host === '' || (new SourceHost())->evaluateFieldValue($host) !== $host)) {
            return 'Refused: "source_host" must be * or a host name or IP address without scheme or path, as the redirects '
                . 'module accepts it (www.example.com).';
        }

        $refusal = ($touches('source_path', 'is_regexp', 'respect_query_parameters') ? $this->redirectPathRefusal($fields) : null)
            ?? ($touches('target') ? $this->redirectTargetRefusal(self::toStr($fields['target'] ?? '')) : null);
        if ($refusal !== null) {
            return $refusal;
        }

        if ($touches('source_host', 'source_path', 'is_regexp', 'target') && $this->redirectLoops($fields)) {
            return 'Refused: the target is the source itself, so the redirect would send the visitor round in a loop.';
        }

        if ($touches('description') && mb_strlen(self::toStr($fields['description'] ?? '')) > self::REDIRECT_DESCRIPTION_LIMIT) {
            return sprintf('Refused: "description" may hold at most %d characters.', self::REDIRECT_DESCRIPTION_LIMIT);
        }

        return null;
    }

    /**
     * Why a source path would match nothing, or null.
     *
     * @param array<string, int|string> $fields
     */
    private function redirectPathRefusal(array $fields): ?string
    {
        $path          = self::toStr($fields['source_path'] ?? '');
        $isRegexp      = self::toInt($fields['is_regexp'] ?? 0) === 1;
        $respectsQuery = self::toInt($fields['respect_query_parameters'] ?? 0) === 1;
        $pathLimit     = $this->redirectPathLimit();

        return match (true) {
            $path === '' || mb_strlen($path) > $pathLimit => sprintf('Refused: "source_path" must be between 1 and %d characters long.', $pathLimit),
            $isRegexp && !$this->compiles($path)          => 'Refused: "source_path" is marked as a regular expression but does not compile. It needs delimiters, for example #^/old/(.*)$#.',
            $isRegexp                                     => null,
            !str_starts_with($path, '/')                  => 'Refused: "source_path" must start with a slash. A path without one matches no request.',
            rawurldecode($path) !== $path                 => sprintf('Refused: "source_path" is percent-encoded, and core compares the decoded request path with it. Write it decoded: %s', $this->excerpt(rawurldecode($path))),
            str_contains($path, '?') && !$respectsQuery   => 'Refused: "source_path" carries a query string, which a redirect only matches with "respect_query_parameters": true. Set it, or leave the query string out.',
            !str_contains($path, '?') && $respectsQuery   => 'Refused: with "respect_query_parameters": true the redirect matches only the path together with its query string (/index.php?id=12), and this path has none. Add the query string, or set it to false.',
            default                                       => null,
        };
    }

    /**
     * Why a target is not one the redirect field takes, or null: a TYPO3 link
     * core resolves (a page that exists, a file), an absolute http(s) URL, or
     * a path on the same host — each optionally with the additional parameters
     * the module's link wizard stores after it (`t3://page?uid=12 - - - &a=1`),
     * which core decodes as a typolink.
     */
    private function redirectTargetRefusal(string $target): ?string
    {
        $url = trim(self::toStr(GeneralUtility::makeInstance(TypoLinkCodecService::class)->decode($target)['url'] ?? ''));
        if ($url === '' || preg_match('/\s/u', $url) === 1) {
            return 'Refused: "target" must be one link: t3://page?uid=12, an absolute http(s) URL or a path starting with a slash.';
        }

        if (str_starts_with($url, 't3://')) {
            return $this->typo3LinkRefusal($url);
        }

        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return null;
        }

        // FILTER_VALIDATE_URL takes ASCII only; the module stores a path such
        // as /über-uns as it is typed.
        $ascii  = preg_replace_callback('/[^\x21-\x7E]/u', static fn (array $match): string => rawurlencode($match[0]), $url) ?? $url;
        $scheme = strtolower(self::toStr(parse_url($ascii, PHP_URL_SCHEME)));

        return in_array($scheme, ['http', 'https'], true) && GeneralUtility::isValidUrl($ascii)
            ? null
            : 'Refused: "target" must be a t3:// link, an absolute http(s) URL or a path starting with a slash.';
    }

    /**
     * Why a `t3://` link is not a redirect target, or null.
     */
    private function typo3LinkRefusal(string $url): ?string
    {
        try {
            $resolved = $this->linkService->resolve($url);
        } catch (Exception) {
            return sprintf('Refused: TYPO3 cannot resolve the link %s.', $this->excerpt($url));
        }

        $type = self::toStr($resolved['type'] ?? '');
        if ($type === 'page') {
            $page = self::toInt($resolved['pageuid'] ?? 0);

            return $page > 0 && $this->fetchRowByUid('pages', $page, 'uid') !== null
                ? null
                : 'Refused: the target page does not exist.';
        }

        // A missing file resolves to no file object rather than throwing. A
        // record link names its table only through the installation's link
        // handler configuration, so its record is left to core's integrity
        // check, as the redirects module leaves it.
        if ($type === 'file') {
            return ($resolved['file'] ?? null) instanceof FileInterface ? null : 'Refused: the target file does not exist.';
        }

        return in_array($type, ['record', 'url'], true)
            ? null
            : sprintf('Refused: a redirect cannot point at a "%s" link.', preg_replace('/[^a-z_]/', '', $type) ?? '');
    }

    /**
     * Whether a plain redirect sends a visitor back to the very URL they
     * asked for: the target is the source path as it is, on the same host or
     * on a host this TYPO3 serves where the redirect covers every host. An
     * https target is a scheme upgrade, which core follows once, and a
     * different trailing slash is a different URL; neither loops.
     *
     * @param array<string, int|string> $fields
     */
    private function redirectLoops(array $fields): bool
    {
        if (self::toInt($fields['is_regexp'] ?? 0) === 1) {
            return false;
        }

        $host   = strtolower(self::toStr($fields['source_host'] ?? ''));
        $path   = self::toStr($fields['source_path'] ?? '');
        $target = trim(self::toStr(GeneralUtility::makeInstance(TypoLinkCodecService::class)->decode(self::toStr($fields['target'] ?? ''))['url'] ?? ''));
        if (str_starts_with($target, '/')) {
            return $target === $path;
        }

        $targetHost = strtolower(self::toStr(parse_url($target, PHP_URL_HOST)));
        $targetPath = self::toStr(parse_url($target, PHP_URL_PATH));
        if ($targetHost === '' || strtolower(self::toStr(parse_url($target, PHP_URL_SCHEME))) !== 'http'
            || parse_url($target, PHP_URL_QUERY) !== null || ($targetPath === '' ? '/' : $targetPath) !== $path
        ) {
            return false;
        }

        // A redirect for every host loops only where the target host is one
        // this TYPO3 serves; the same path on another domain is a move.
        return $host === '*' ? in_array($targetHost, $this->siteHosts(), true) : $host === $targetHost;
    }

    /**
     * The hosts the sites of this installation answer on, lowercased.
     *
     * @return list<string>
     */
    private function siteHosts(): array
    {
        $hosts = [];
        foreach (GeneralUtility::makeInstance(SiteFinder::class)->getAllSites() as $site) {
            foreach ($site->getAllLanguages() as $language) {
                $host = strtolower($language->getBase()->getHost());
                if ($host !== '') {
                    $hosts[$host] = $host;
                }
            }
        }

        return array_values($hosts);
    }

    /**
     * The uids of other live redirects that EXT:redirects files under the
     * same key as this one, and of which it serves the oldest: same host,
     * same kind of match, and the same path — for a plain path that ignores the
     * query string, with the trailing slash left out, as core's redirect cache
     * keys it (`/old` and `/old/` are one redirect).
     *
     * @param array<string, int|string> $fields
     *
     * @return list<int>
     */
    private function redirectDuplicates(array $fields, int $exceptUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::REDIRECT_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select('uid', 'source_path')
            ->from(self::REDIRECT_TABLE)
            ->where(
                $queryBuilder->expr()->eq('source_host', $queryBuilder->createNamedParameter(self::toStr($fields['source_host'] ?? ''))),
                $queryBuilder->expr()->eq('is_regexp', $queryBuilder->createNamedParameter(self::toInt($fields['is_regexp'] ?? 0), Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('respect_query_parameters', $queryBuilder->createNamedParameter(self::toInt($fields['respect_query_parameters'] ?? 0), Connection::PARAM_INT)),
                $queryBuilder->expr()->neq('uid', $queryBuilder->createNamedParameter($exceptUid, Connection::PARAM_INT)),
                // Core leaves a disabled redirect out of its cache, and one whose
                // stop time has passed is never served again: neither shadows.
                $queryBuilder->expr()->eq('disabled', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('endtime', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->gt('endtime', $queryBuilder->createNamedParameter($this->now(), Connection::PARAM_INT)),
                ),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $key        = $this->redirectCacheKey($fields);
        $duplicates = [];
        foreach ($rows as $row) {
            if ($this->redirectCacheKey(['source_path' => self::toStr($row['source_path'] ?? '')] + $fields) === $key) {
                $duplicates[] = self::toInt($row['uid'] ?? 0);
            }
        }

        return $duplicates;
    }

    /**
     * The path key EXT:redirects files a redirect under in its cache
     * (`RedirectCacheService::rebuildForHost()`): a plain path that ignores
     * the query string without its trailing slash, any other path as it is.
     *
     * @param array<string, int|string> $fields
     */
    private function redirectCacheKey(array $fields): string
    {
        $path = self::toStr($fields['source_path'] ?? '');

        return self::toInt($fields['is_regexp'] ?? 0) === 0 && self::toInt($fields['respect_query_parameters'] ?? 0) === 0
            ? rtrim($path, '/') . '/'
            : $path;
    }

    /**
     * One existing redirect of the default type, or the refusal. A QR code or
     * a short URL (TYPO3 14) is not a redirect an editor typed, and its fields
     * mean something else, so these tools leave it alone.
     *
     * @return array<string, mixed>|string
     */
    private function existingRedirect(mixed $uid): array|string
    {
        $uid = is_int($uid) || (is_string($uid) && MathUtility::canBeInterpretedAsInteger($uid)) ? (int) $uid : 0;
        if ($uid < 1) {
            return 'Refused: "uid" must be the positive uid of exactly one redirect.';
        }

        $row = $this->fetchRowByUid(self::REDIRECT_TABLE, $uid);
        if ($row === null) {
            return self::NOT_PERMITTED;
        }

        $type = self::toStr($row['redirect_type'] ?? 'default');
        if ($type !== '' && $type !== 'default') {
            return sprintf(
                'Refused: redirect [%d] is a "%s" record, not a plain redirect. Change it in the redirects module.',
                $uid,
                preg_replace('/[^a-z_]/', '', $type) ?? '',
            );
        }

        return $row;
    }

    /**
     * Whether a stored row holds every field of `$fields`. The read-back after
     * a write: EXT:redirects' own guard empties a record it refuses and only
     * logs that, so an empty error log proves nothing.
     *
     * @param array<string, mixed>      $stored
     * @param array<string, int|string> $fields
     */
    private function redirectHolds(array $stored, array $fields): bool
    {
        foreach ($fields as $field => $value) {
            if (self::toStr($stored[$field] ?? '') !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * The card lines of a redirect's fields in the acting user's language: on
     * a new redirect each value, on a change each field with its current and
     * its proposed value (only the fields the call changes, then the ones that
     * stay, in one line each). Field names come from EXT:redirects' own labels.
     *
     * @param Closure(ApprovalPreviewLabel, int|string...): string $t
     * @param Closure(string): string                              $q
     * @param array<string, int|string>                            $after
     * @param array<string, int|string>|null                       $before null for a new redirect
     *
     * @return array{list<string>, list<string>} the editor's lines and the technical parts
     */
    private function redirectFieldLines(Closure $t, Closure $q, BackendUserAuthentication $user, array $after, ?array $before): array
    {
        $lines     = [];
        $technical = [];
        foreach ($after as $field => $value) {
            $name = $this->translator->columnLabel($user, self::REDIRECT_TABLE, $field);
            $new  = $this->redirectValueOnCard($t, $user, $field, $value);
            if ($before === null) {
                $lines[] = $t(ApprovalPreviewLabel::RedirectField, $name, $field === 'description' ? $q($new) : $new);
                continue;
            }

            $old = $this->redirectValueOnCard($t, $user, $field, $before[$field] ?? '');
            if (in_array($field, self::REDIRECT_FLAGS, true) || $field === 'target_statuscode') {
                $lines[] = $old === $new
                    ? $t(ApprovalPreviewLabel::FieldUnchanged, $name, $new)
                    : $t(ApprovalPreviewLabel::FieldChange, $name, $old, $new);
                continue;
            }

            [$line, $part] = $this->fieldChange($t, $q, $name, $field, self::toStr($before[$field] ?? ''), self::toStr($value));
            $lines[]       = $line;
            if ($part !== null) {
                $technical[] = $part;
            }
        }

        return [$lines, $technical];
    }

    /**
     * One redirect value as the card shows it: a flag as yes or no, the status
     * code with its label, anything else as it is.
     *
     * @param Closure(ApprovalPreviewLabel, int|string...): string $t
     */
    private function redirectValueOnCard(Closure $t, BackendUserAuthentication $user, string $field, int|string|null $value): string
    {
        if (in_array($field, self::REDIRECT_FLAGS, true)) {
            return $t(self::toInt($value) === 1 ? ApprovalPreviewLabel::RedirectYes : ApprovalPreviewLabel::RedirectNo);
        }

        if ($field === 'target_statuscode') {
            return $this->translator->itemLabel($user, self::REDIRECT_TABLE, $field, (string) self::toInt($value));
        }

        return self::toStr($value);
    }

    /**
     * The redirect in one phrase, for the answers to the model: host, path and
     * target.
     *
     * @param array<string, mixed> $fields
     */
    private function redirectInEnglish(array $fields): string
    {
        return sprintf(
            '%s%s → %s',
            self::toStr($fields['source_host'] ?? ''),
            $this->excerpt(self::toStr($fields['source_path'] ?? '')),
            $this->excerpt(self::toStr($fields['target'] ?? '')),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function redirectColumns(): ?array
    {
        $columns = $this->redirectTca('columns');
        if (!is_array($columns)) {
            return null;
        }

        /** @var array<string, mixed> $columns */
        return $columns;
    }

    /**
     * The status codes the running core offers, from the column's items.
     *
     * @return list<int>
     */
    private function redirectStatusCodes(): array
    {
        $items = $this->redirectTca('columns', 'target_statuscode', 'config', 'items');
        $codes = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_scalar($item['value'] ?? null)) {
                $codes[] = (int) $item['value'];
            }
        }

        return $codes === [] ? self::REDIRECT_STATUS_CODES : $codes;
    }

    /**
     * The default status code of a new redirect, from the TCA.
     */
    private function redirectDefaultStatusCode(): int
    {
        $default = self::toInt($this->redirectTca('columns', 'target_statuscode', 'config', 'default') ?? 307);

        return in_array($default, $this->redirectStatusCodes(), true) ? $default : 307;
    }

    private function redirectPathLimit(): int
    {
        $max = self::toInt($this->redirectTca('columns', 'source_path', 'config', 'max'));

        return $max > 0 ? $max : 2048;
    }

    /**
     * Whether a regular expression compiles. preg_match() signals an invalid
     * pattern with a warning and false; a scoped no-op handler keeps the probe
     * silent without "@".
     */
    private function compiles(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);
        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The value at a path below the `sys_redirect` TCA, or null where the path
     * ends early.
     */
    private function redirectTca(string ...$path): mixed
    {
        $value = $GLOBALS['TCA'] ?? null;
        foreach ([self::REDIRECT_TABLE, ...$path] as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }
}

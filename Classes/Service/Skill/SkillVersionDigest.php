<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Model\Skill;

/**
 * The version digest of a skill (ADR-214 item 1): one sha256 over everything
 * the model reads of a version.
 *
 * The input covers the body and every frontmatter field that composition,
 * the catalogue or the tool allow-list reads: ``name``, ``description``,
 * ``body``, ``support_status``, ``allowed_tools`` and the process marker.
 * ``raw_frontmatter`` is not hashed as such, because its JSON depends on key
 * order and a key nothing reads does not change the version.
 *
 * The serialisation is length-prefixed rather than JSON, so it is binary-safe:
 * two different byte strings can never serialise alike, whatever their
 * encoding. ``allowed_tools`` is null (no declaration) or the list the
 * allow-list resolver reads — string names only — de-duplicated and sorted, so
 * the order an author wrote them in is not a new version.
 *
 * The stored value carries its format version in front of the hex digest
 * (``1:<64 hex>``). A field a later change starts to read joins the input under
 * a new format version.
 *
 * Every reader computes the digest through this class — the sync, the
 * compose-time integrity check, the approval form and the upgrade wizard — so
 * the four can never disagree about what a version is.
 *
 * @internal Not part of the @api surface; may change without notice (ADR-127).
 */
final class SkillVersionDigest
{
    public const FORMAT = 1;

    /**
     * The fields of a version, in canonical form.
     *
     * @return array{name: string, description: string, body: string, support_status: string, allowed_tools: list<string>|null, process: bool}
     */
    public static function fieldsOf(Skill $skill): array
    {
        return [
            'name'           => $skill->getName(),
            'description'    => $skill->getDescription(),
            'body'           => $skill->getBody(),
            'support_status' => $skill->getSupportStatus(),
            'allowed_tools'  => self::normaliseTools($skill->getAllowedToolsList()),
            'process'        => $skill->isProcess(),
        ];
    }

    /**
     * The digest of a skill record's current fields.
     */
    public static function of(Skill $skill): string
    {
        return self::ofFields(self::fieldsOf($skill));
    }

    /**
     * The digest of a set of version fields, e.g. an approval snapshot.
     *
     * @param array{name: string, description: string, body: string, support_status: string, allowed_tools: list<string>|null, process: bool} $fields
     */
    public static function ofFields(array $fields): string
    {
        $tools   = self::normaliseTools($fields['allowed_tools']);
        $payload = 'nr_llm.skill-version' . "\n"
            . 'format=' . self::FORMAT . "\n"
            . self::field('name', $fields['name'])
            . self::field('description', $fields['description'])
            . self::field('body', $fields['body'])
            . self::field('support_status', $fields['support_status']);

        if ($tools === null) {
            $payload .= "allowed_tools=-\n";
        } else {
            $payload .= 'allowed_tools=' . count($tools) . "\n";
            foreach ($tools as $tool) {
                $payload .= self::field('tool', $tool);
            }
        }

        $payload .= 'process=' . ($fields['process'] ? '1' : '0') . "\n";

        return self::FORMAT . ':' . hash('sha256', $payload);
    }

    /**
     * The verified version digest of a skill record, '' for a legacy row whose
     * body checksum verifies, or null when the integrity check fails (ADR-214
     * item 1, ADR-036 item 6).
     *
     * A row with a stored version digest is checked against the digest
     * recomputed from its stored fields, so an edit of the body, the name, the
     * description, the support status, the tool declaration or the process
     * marker fails it. A legacy row has no digest yet; it keeps the body-only
     * check until the sync or the upgrade wizard writes one, and it can never
     * be approved or instruct.
     *
     * The one integrity check: compose and the approval form both call it, so
     * a record the composer would skip can never be approved.
     */
    public static function verified(Skill $skill): ?string
    {
        $stored = $skill->getVersionDigest();
        if ($stored !== '') {
            return hash_equals($stored, self::of($skill)) ? $stored : null;
        }

        return hash_equals($skill->getBodyChecksum(), hash('sha256', $skill->getBody())) ? '' : null;
    }

    /**
     * Whether a stored value has the shape this class writes.
     */
    public static function isWellFormed(string $digest): bool
    {
        return preg_match('/^[1-9]\d*:[0-9a-f]{64}$/', $digest) === 1;
    }

    /**
     * The tool names the allow-list resolver reads, de-duplicated and sorted.
     *
     * @param list<mixed>|null $tools
     *
     * @return list<string>|null
     */
    public static function normaliseTools(?array $tools): ?array
    {
        if ($tools === null) {
            return null;
        }

        $names = array_values(array_unique(array_filter($tools, is_string(...))));
        sort($names, SORT_STRING);

        return $names;
    }

    private static function field(string $key, string $value): string
    {
        return $key . '=' . strlen($value) . ':' . $value . "\n";
    }
}

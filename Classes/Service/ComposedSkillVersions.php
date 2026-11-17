<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service;

use Netresearch\NrLlm\Domain\Enum\SkillRole;
use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\ValueObject\SkillUse;

/**
 * The skill versions a configuration-backed chat composed into its request,
 * carried on the response's metadata so an evaluation can say which skill
 * versions it measured (ADR-322).
 *
 * Identities, digests and roles of the composition, and the identifier of the
 * configuration whose skills they are. No text. Written by the manager after
 * the call returns, outside the provider pipeline, so no middleware rewrites
 * or drops it.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final class ComposedSkillVersions
{
    public const METADATA_KEY = 'nr_llm_composed_skills';

    /**
     * The response with the composition on its metadata; the response itself
     * when the request carried no skill version, which a reader treats as no
     * composition — an evaluation names no version either way.
     *
     * @param list<SkillUse> $uses
     */
    public static function attach(CompletionResponse $response, string $configurationIdentifier, array $uses): CompletionResponse
    {
        if ($uses === []) {
            return $response;
        }

        $metadata                     = $response->metadata ?? [];
        $metadata[self::METADATA_KEY] = [
            'configuration' => $configurationIdentifier,
            'skills'        => array_map(
                static fn (SkillUse $use): array => [
                    'skill'      => $use->skillUid,
                    'source'     => $use->sourceUid,
                    'digest'     => $use->versionDigest,
                    'identifier' => $use->identifier,
                    'role'       => $use->role->value,
                ],
                $uses,
            ),
        ];

        return new CompletionResponse(
            $response->content,
            $response->model,
            $response->usage,
            $response->finishReason,
            $response->provider,
            $response->toolCalls,
            $metadata,
            $response->thinking,
        );
    }

    /**
     * The composition a response carries, or null when it carries none or one
     * that does not read: the configuration identifier and the versions.
     *
     * @return array{configuration: string, skills: list<SkillUse>}|null
     */
    public static function read(mixed $metadata): ?array
    {
        $stored = is_array($metadata) ? ($metadata[self::METADATA_KEY] ?? null) : null;
        if (!is_array($stored) || !is_string($stored['configuration'] ?? null) || !is_array($stored['skills'] ?? null)) {
            return null;
        }

        $uses = [];
        foreach ($stored['skills'] as $item) {
            $role = is_array($item) && is_string($item['role'] ?? null) ? SkillRole::tryFrom($item['role']) : null;
            if (!is_array($item) || !$role instanceof SkillRole || !is_int($item['skill'] ?? null) || !is_int($item['source'] ?? null)
                || !is_string($item['digest'] ?? null) || !is_string($item['identifier'] ?? null)) {
                return null;
            }

            $uses[] = new SkillUse($item['skill'], $item['source'], $item['digest'], $item['identifier'], $role);
        }

        return ['configuration' => $stored['configuration'], 'skills' => $uses];
    }
}

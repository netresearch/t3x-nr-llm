<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\ValueObject\MarketplaceEntry;
use Netresearch\NrLlm\Service\Skill\Exception\SkillParseException;

final class MarketplaceParser
{
    /**
     * @return list<MarketplaceEntry>
     */
    public function parse(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw SkillParseException::forReason('marketplace.json', 'invalid JSON');
        }
        if (!isset($decoded['plugins']) || !is_array($decoded['plugins'])) {
            throw SkillParseException::forReason('marketplace.json', 'missing "plugins" array');
        }

        $entries = [];
        foreach ($decoded['plugins'] as $plugin) {
            if (!is_array($plugin) || !isset($plugin['source'])) {
                continue;
            }
            $ownerRepo = $this->extractOwnerRepo($plugin['source']);
            if ($ownerRepo === null) {
                continue;
            }
            $ref = isset($plugin['ref']) && is_string($plugin['ref']) ? $plugin['ref'] : null;
            $entries[] = new MarketplaceEntry($ownerRepo[0], $ownerRepo[1], $ref);
        }
        return $entries;
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function extractOwnerRepo(mixed $source): ?array
    {
        $slug = null;
        if (is_string($source)) {
            $slug = $source;
        } elseif (is_array($source) && isset($source['repo']) && is_string($source['repo'])) {
            $slug = $source['repo'];
        }
        if ($slug === null || !str_contains($slug, '/')) {
            return null;
        }
        [$owner, $repo] = explode('/', $slug, 2);
        if ($owner === '' || $repo === '') {
            return null;
        }
        return [$owner, $repo];
    }
}

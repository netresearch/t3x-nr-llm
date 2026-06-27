<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Service\Skill;

use Netresearch\NrLlm\Domain\Enum\SupportStatus;
use Netresearch\NrLlm\Domain\ValueObject\ParsedSkill;
use Netresearch\NrLlm\Service\Skill\Exception\SkillParseException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class SkillMarkdownParser
{
    /** Patterns that indicate the skill relies on assets/scripts not supported in Plan 1a. */
    private const UNSUPPORTED_PATTERNS = [
        '#\breferences/#i',
        '#\bscripts/#i',
        '#\bassets/#i',
        '#\.(py|sh|js|rb)\b#i',
    ];

    public function __construct(
        private readonly int $maxBodyBytes = 65536,
        private readonly int $maxFrontmatterBytes = 8192,
    ) {}

    public function parse(string $path, string $content): ParsedSkill
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw SkillParseException::forReason($path, 'content is not valid UTF-8');
        }

        // Front-matter must be a leading `---\n ... \n---` block.
        if (!preg_match('/^---\R(.*?)\R---\R?(.*)$/s', $content, $m)) {
            throw SkillParseException::forReason($path, 'missing YAML front-matter');
        }
        $frontmatterRaw = $m[1];
        $body = ltrim($m[2]);

        if (strlen($frontmatterRaw) > $this->maxFrontmatterBytes) {
            throw SkillParseException::forReason($path, 'front-matter exceeds size cap');
        }
        if (strlen($body) > $this->maxBodyBytes) {
            throw SkillParseException::forReason($path, 'body exceeds size cap');
        }

        try {
            $frontmatter = Yaml::parse($frontmatterRaw);
        } catch (ParseException $e) {
            throw SkillParseException::forReason($path, 'malformed YAML: ' . $e->getMessage());
        }
        if (!is_array($frontmatter) || array_is_list($frontmatter)) {
            throw SkillParseException::forReason($path, 'front-matter is not a mapping');
        }

        $name = isset($frontmatter['name']) && is_scalar($frontmatter['name']) ? trim((string)$frontmatter['name']) : '';
        $description = isset($frontmatter['description']) && is_scalar($frontmatter['description']) ? trim((string)$frontmatter['description']) : '';
        if ($name === '') {
            throw SkillParseException::forReason($path, 'missing or empty "name"');
        }
        if ($description === '') {
            throw SkillParseException::forReason($path, 'missing or empty "description"');
        }

        /** @var array<string,mixed> $normalized */
        $normalized = [];
        foreach ($frontmatter as $k => $v) {
            $normalized[(string)$k] = $v;
        }

        [$supportStatus, $notes] = $this->assessSupport($normalized, $body);

        return new ParsedSkill($path, $name, $description, $body, $normalized, $supportStatus, $notes);
    }

    /**
     * @param array<string,mixed> $frontmatter
     *
     * @return array{0: SupportStatus, 1: string}
     */
    private function assessSupport(array $frontmatter, string $body): array
    {
        $reasons = [];
        if (array_key_exists('allowed-tools', $frontmatter) || array_key_exists('allowed_tools', $frontmatter)) {
            $reasons[] = 'declares allowed-tools';
        }
        foreach (self::UNSUPPORTED_PATTERNS as $pattern) {
            if (preg_match($pattern, $body) === 1) {
                $reasons[] = 'body references scripts/assets';
                break;
            }
        }
        if ($reasons === []) {
            return [SupportStatus::FULL, ''];
        }
        return [SupportStatus::PARTIAL, implode('; ', array_unique($reasons))];
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\PendingWriteTarget;
use Netresearch\NrLlm\Service\Tool\PendingTargetInterface;
use Netresearch\NrLlm\Service\Tool\ToolEffectResolver;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every approval-bound builtin writer names the record and the fields of a
 * pending call as structured values, or says it has none because it creates
 * its record (ADR-214, item 9; amends ADR-136).
 *
 * Asserted per writer against the builtins the registry actually holds, so a
 * writer added later fails here until it states its target — and with one
 * example call each, so "implements the interface" is not the whole proof.
 */
#[CoversNothing]
final class PendingTargetCoverageTest extends AbstractFunctionalTestCase
{
    /**
     * One call per writer and the target it must name; null for a create.
     *
     * @var array<string, array{array<string, mixed>, array{table: string, uid: int, fields: list<string>}|null}>
     */
    private const EXPECTED = [
        'attach_file_to_content_element' => [['content_element' => 12, 'file' => 3, 'field' => 'assets'], ['table' => 'tt_content', 'uid' => 12, 'fields' => ['assets']]],
        'attach_file_to_record'          => [['table' => 'tx_news_domain_model_news', 'record' => 5, 'file' => 3, 'field' => 'fal_media'], ['table' => 'tx_news_domain_model_news', 'uid' => 5, 'fields' => ['fal_media']]],
        'copy_record'                    => [['table' => 'tt_content', 'uid' => 12, 'target_page' => 4], null],
        'create_content_element_draft'   => [['page' => 4, 'type' => 'text', 'header' => 'H'], null],
        'create_page_draft'              => [['parent' => 1, 'title' => 'T'], null],
        'create_record_draft'            => [['table' => 'tx_news_domain_model_news', 'pid' => 4, 'fields' => ['title' => 'T']], null],
        'create_translation_draft'       => [['table' => 'tt_content', 'uid' => 12, 'language' => 1], null],
        'delete_record'                  => [['table' => 'tt_content', 'uid' => 12], ['table' => 'tt_content', 'uid' => 12, 'fields' => []]],
        'move_content_element'           => [['uid' => 12, 'target_page' => 4], ['table' => 'tt_content', 'uid' => 12, 'fields' => []]],
        'move_page'                      => [['uid' => 7, 'parent' => 1], ['table' => 'pages', 'uid' => 7, 'fields' => []]],
        'publish_record'                 => [['table' => 'pages', 'uid' => 7], ['table' => 'pages', 'uid' => 7, 'fields' => []]],
        'replace_file_reference'         => [['reference' => 101, 'action' => 'replace', 'file' => 3], ['table' => 'sys_file_reference', 'uid' => 101, 'fields' => []]],
        'set_file_alternative_text'      => [['uid' => 3, 'alternative' => 'A dog'], ['table' => 'sys_file', 'uid' => 3, 'fields' => ['alternative']]],
        'set_page_social_image'          => [['page' => 7, 'field' => 'og_image', 'file' => 3], ['table' => 'pages', 'uid' => 7, 'fields' => ['og_image']]],
        'update_content_element'         => [['uid' => 12, 'fields' => ['header' => 'H', 'bodytext' => 'B']], ['table' => 'tt_content', 'uid' => 12, 'fields' => ['bodytext', 'header']]],
        'update_fal_asset_meta'          => [['uid' => 3, 'title' => 'T', 'description' => 'D'], ['table' => 'sys_file', 'uid' => 3, 'fields' => ['description', 'title']]],
        'update_page_metadata'           => [['uid' => 7, 'title' => 'T', 'nav_title' => 'N', 'not_a_field' => 'x'], ['table' => 'pages', 'uid' => 7, 'fields' => ['nav_title', 'title']]],
    ];

    #[Test]
    public function everyBuiltinWriterNamesThePendingTargetOrSaysItCreatesItsRecord(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $effects = new ToolEffectResolver($registry);

        $writers = [];
        foreach ($registry->builtinNames() as $name) {
            if ($effects->effectFor($name)->isWrite()) {
                $writers[] = $name;
            }
        }

        sort($writers);
        $listed = array_keys(self::EXPECTED);
        sort($listed);
        self::assertSame($listed, $writers, 'A writer was added or removed: state its pending target here.');

        foreach (self::EXPECTED as $name => [$arguments, $expected]) {
            $tool = $registry->get($name);
            self::assertInstanceOf(PendingTargetInterface::class, $tool, $name . ' does not state its pending target.');

            $target = $tool->pendingTarget($arguments);
            self::assertSame($expected, $target?->toArray(), $name);
        }
    }

    /**
     * The other direction: arguments that name no reachable record give no
     * target, for every writer that names one — never an exception.
     */
    #[Test]
    public function aWriterGivesNoTargetForArgumentsThatNameNoRecord(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);

        foreach (self::EXPECTED as $name => [$arguments, $expected]) {
            $tool = $registry->get($name);
            self::assertInstanceOf(PendingTargetInterface::class, $tool);

            self::assertNull($tool->pendingTarget([]), $name . ' with no arguments');

            $broken = array_map(static fn(mixed $value): mixed => is_int($value) ? -1 : $value, $arguments);
            if ($expected !== null) {
                self::assertNotInstanceOf(PendingWriteTarget::class, $tool->pendingTarget($broken), $name . ' with a negative uid');
            }
        }
    }
}

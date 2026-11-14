<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool\Scope;

use Netresearch\NrLlm\Domain\ValueObject\RecordReference;
use Netresearch\NrLlm\Service\Tool\Scope\ChildRelations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChildRelations::class)]
final class ChildRelationsTest extends TestCase
{
    protected function setUp(): void
    {
        ChildRelations::reset();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']);
        ChildRelations::reset();
    }

    #[Test]
    public function everyTableThatIsTheChildOfAnInlineOrFileColumnNamesItsPointers(): void
    {
        $GLOBALS['TCA'] = [
            'parent_a' => ['columns' => [
                'items' => ['config' => ['type' => 'inline', 'foreign_table' => 'child_one', 'foreign_field' => 'parent_uid', 'foreign_table_field' => 'parent_table']],
                'plain' => ['config' => ['type' => 'input']],
            ]],
            'parent_b' => ['columns' => [
                'items' => ['config' => ['type' => 'inline', 'foreign_table' => 'child_one', 'foreign_field' => 'parent_uid', 'foreign_table_field' => 'parent_table']],
                'own'   => ['config' => ['type' => 'inline', 'foreign_table' => 'child_two', 'foreign_field' => 'owner']],
                'files' => ['config' => ['type' => 'file']],
            ]],
            'child_one' => [],
            'child_two' => [],
        ];

        self::assertSame(['field' => 'parent_uid', 'tableField' => 'parent_table', 'parents' => ['parent_a', 'parent_b']], ChildRelations::of('child_one'));
        self::assertSame(['field' => 'owner', 'tableField' => null, 'parents' => ['parent_b']], ChildRelations::of('child_two'));
        self::assertSame(['field' => 'uid_foreign', 'tableField' => 'tablenames', 'parents' => ['parent_b']], ChildRelations::of('sys_file_reference'));
        self::assertNull(ChildRelations::of('parent_a'), 'a table that is nobody\'s child');
        self::assertNull(ChildRelations::of('no_such_table'));
    }

    #[Test]
    public function aChildNamesItsHostOnlyWhereTheStoredPointersAreUnambiguous(): void
    {
        $GLOBALS['TCA'] = [
            'parent_a' => ['columns' => ['items' => ['config' => ['type' => 'inline', 'foreign_table' => 'child_one', 'foreign_field' => 'parent_uid', 'foreign_table_field' => 'parent_table']]]],
            'parent_b' => ['columns' => [
                'items' => ['config' => ['type' => 'inline', 'foreign_table' => 'child_one', 'foreign_field' => 'parent_uid', 'foreign_table_field' => 'parent_table']],
                'one'   => ['config' => ['type' => 'inline', 'foreign_table' => 'child_two', 'foreign_field' => 'owner']],
            ]],
            'parent_c'  => ['columns' => ['two' => ['config' => ['type' => 'inline', 'foreign_table' => 'child_two', 'foreign_field' => 'owner']]]],
            'child_one' => [],
            'child_two' => [],
        ];

        self::assertEquals(new RecordReference('parent_b', 5), ChildRelations::host('child_one', ['parent_uid' => 5, 'parent_table' => 'parent_b']));
        self::assertNull(ChildRelations::host('child_one', ['parent_uid' => 5, 'parent_table' => 'somewhere_else']), 'a table that is no parent of the column');
        self::assertNull(ChildRelations::host('child_one', ['parent_uid' => 5]), 'no table told');
        self::assertNull(ChildRelations::host('child_one', ['parent_uid' => 0, 'parent_table' => 'parent_a']));
        self::assertNull(ChildRelations::host('child_two', ['owner' => 5]), 'two parents and nothing that tells which');
        self::assertNull(ChildRelations::host('parent_a', ['owner' => 5]));
    }

    #[Test]
    public function aInlineColumnWithoutAForeignFieldDefinesNoPointer(): void
    {
        $GLOBALS['TCA'] = ['parent' => ['columns' => ['mm' => ['config' => ['type' => 'inline', 'foreign_table' => 'child', 'MM' => 'child_mm']]]], 'child' => []];

        self::assertNull(ChildRelations::of('child'));
    }
}

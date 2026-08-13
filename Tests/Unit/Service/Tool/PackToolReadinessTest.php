<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Tool;

use Netresearch\NrLlm\Domain\ValueObject\EditorAction;
use Netresearch\NrLlm\Service\Tool\PackToolReadiness;
use Netresearch\NrLlm\Service\Tool\ToolAvailabilityServiceInterface;
use Netresearch\NrLlm\Service\UseCase\PackEditorActionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The three questions a use-case pack's plan asks about a declared editor
 * action (ADR-168): does it exist here, is it enabled, which records does it
 * address.
 */
#[CoversClass(PackToolReadiness::class)]
final class PackToolReadinessTest extends TestCase
{
    /** Public because the anonymous availability fixture below reads them. */
    public const ALT_TEXT = 'set_file_alternative_text';

    public const TRANSLATION = 'create_translation_draft';

    private function availability(): ToolAvailabilityServiceInterface
    {
        return new class implements ToolAvailabilityServiceInterface {
            public function enabledNames(): array
            {
                return [PackToolReadinessTest::ALT_TEXT];
            }

            public function states(): array
            {
                return [
                    [
                        'name' => PackToolReadinessTest::ALT_TEXT,
                        'description' => 'Sets a file alternative text.',
                        'group' => 'editing',
                        'enabled' => true,
                        'toolEnabled' => true,
                        'groupEnabled' => true,
                        'defaultEnabled' => false,
                        'overridden' => true,
                    ],
                    [
                        'name' => PackToolReadinessTest::TRANSLATION,
                        'description' => 'Creates a translation draft.',
                        'group' => 'editing',
                        'enabled' => false,
                        'toolEnabled' => false,
                        'groupEnabled' => true,
                        'defaultEnabled' => false,
                        'overridden' => false,
                    ],
                    [
                        'name' => 'get_page',
                        'description' => 'Reads a page.',
                        'group' => 'content',
                        'enabled' => true,
                        'toolEnabled' => true,
                        'groupEnabled' => true,
                        'defaultEnabled' => true,
                        'overridden' => false,
                    ],
                ];
            }

            public function editorActions(): array
            {
                return [
                    PackToolReadinessTest::ALT_TEXT => new EditorAction(
                        'LLL:alt.label',
                        'LLL:alt.description',
                        'nrllm-editor-action-file-alt-text',
                        ['sys_file'],
                    ),
                    PackToolReadinessTest::TRANSLATION => new EditorAction(
                        'LLL:translate.label',
                        'LLL:translate.description',
                        'nrllm-editor-action-create-translation',
                        ['pages', 'tt_content'],
                    ),
                ];
            }

            public function groupStates(): array
            {
                return [
                    ['name' => 'editing', 'labelKey' => 'LLL:tool.group.editing', 'enabled' => true, 'overridden' => false],
                    ['name' => 'content', 'labelKey' => 'LLL:tool.group.content', 'enabled' => false, 'overridden' => true],
                    ['name' => 'my_ext', 'labelKey' => null, 'enabled' => true, 'overridden' => false],
                ];
            }
        };
    }

    private function readiness(): PackToolReadiness
    {
        return new PackToolReadiness($this->availability());
    }

    #[Test]
    public function anEnabledActionReportsItsDeclarationAndItsRecordTypes(): void
    {
        $states = $this->readiness()->editorActionStates([self::ALT_TEXT]);

        self::assertCount(1, $states);
        self::assertSame(self::ALT_TEXT, $states[0]->toolName);
        self::assertTrue($states[0]->declared);
        self::assertTrue($states[0]->enabled);
        self::assertSame('editing', $states[0]->group);
        self::assertSame(['sys_file'], $states[0]->getRecordTypes());
        self::assertSame('LLL:alt.label', $states[0]->declaration?->labelKey);
    }

    #[Test]
    public function aDisabledActionIsStillDeclaredAndStillNamesItsRecordTypes(): void
    {
        // The distinction the plan exists for: the operator has a switch to
        // throw, and the screen must say which one and for what.
        $states = $this->readiness()->editorActionStates([self::TRANSLATION]);

        self::assertTrue($states[0]->declared);
        self::assertFalse($states[0]->enabled);
        self::assertSame(['pages', 'tt_content'], $states[0]->getRecordTypes());
    }

    #[Test]
    public function anUnknownActionIsReportedAsUndeclaredRatherThanDroppedOrDisabled(): void
    {
        // The central regression: a typo must be a visible row. Dropping it
        // would make the plan silent, and reporting it as merely "disabled"
        // would send the operator to a Tools module with no such entry.
        $states = $this->readiness()->editorActionStates(['set_file_alternativ_text']);

        self::assertCount(1, $states);
        self::assertSame('set_file_alternativ_text', $states[0]->toolName);
        self::assertFalse($states[0]->declared);
        self::assertFalse($states[0]->enabled);
        self::assertNull($states[0]->declaration);
        self::assertSame([], $states[0]->getRecordTypes());
    }

    #[Test]
    public function aRegisteredToolThatDeclaresNoEditorActionCountsAsUndeclared(): void
    {
        // `get_page` exists and is enabled, but it is not an editor action.
        // Reporting it as an enabled action would let a pack claim an editorial
        // write for a read-only tool.
        $states = $this->readiness()->editorActionStates(['get_page']);

        self::assertFalse($states[0]->declared);
        self::assertFalse($states[0]->enabled, 'the tool is enabled, but not as an editor action');
        // `get_page` sits in `content`, and the plan screen renders the group as
        // "where the switch for this action is". There is no such switch, so the
        // real group must not be offered as one.
        self::assertSame('', $states[0]->group, 'the tool has a group, but the action does not exist');
    }

    #[Test]
    public function everyNameAskedForGetsExactlyOneRowInOrder(): void
    {
        $states = $this->readiness()->editorActionStates([self::TRANSLATION, 'nope', self::ALT_TEXT]);

        self::assertSame(
            [self::TRANSLATION, 'nope', self::ALT_TEXT],
            array_map(static fn(PackEditorActionState $state): string => $state->toolName, $states),
        );
    }

    #[Test]
    public function anEmptyDeclarationCostsNothing(): void
    {
        self::assertSame([], $this->readiness()->editorActionStates([]));
        self::assertSame([], $this->readiness()->toolGroupStates([]));
    }

    #[Test]
    public function toolGroupsReportRegistrationEnabledStateAndTheCuratedLabel(): void
    {
        $states = $this->readiness()->toolGroupStates(['editing', 'content', 'my_ext', 'contnet']);

        self::assertTrue($states[0]->registered);
        self::assertTrue($states[0]->enabled);
        self::assertSame('LLL:tool.group.editing', $states[0]->labelKey);

        self::assertTrue($states[1]->registered);
        self::assertFalse($states[1]->enabled, 'an admin override disabled the group');

        // A third-party group is registered and switchable; it simply has no
        // translated name. Null must not read as "unknown".
        self::assertTrue($states[2]->registered);
        self::assertNull($states[2]->labelKey);

        // The typo the template used to print as if it were real.
        self::assertFalse($states[3]->registered);
        self::assertFalse($states[3]->enabled);
    }
}

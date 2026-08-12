<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\Enum;

use Netresearch\NrLlm\Domain\Enum\ToolGroup;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Tests\Unit\Language\LabelCatalogue;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The curated group taxonomy (ADR-152): every case is named in both catalogues
 * and classified for egress, and a group outside the enum stays nameless
 * rather than becoming an error.
 */
#[CoversNothing]
final class ToolGroupTest extends TestCase
{
    /**
     * @return array<string, array{ToolGroup}>
     */
    public static function curatedGroups(): array
    {
        $cases = [];
        foreach (ToolGroup::cases() as $case) {
            $cases[$case->value] = [$case];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('curatedGroups')]
    public function everyGroupIsNamedInBothCatalogues(ToolGroup $group): void
    {
        $key = $group->labelKey();

        self::assertNotNull(LabelCatalogue::source($key), 'No English label for group ' . $group->value);
        self::assertNotNull(LabelCatalogue::target($key), 'No German label for group ' . $group->value);
    }

    /**
     * The taxonomy is written down twice — here and as the per-group egress
     * default of ADR-094. A group added to one and not the other would silently
     * fall through to SECRET_ADJACENT, which is safe but not intended.
     */
    #[Test]
    #[DataProvider('curatedGroups')]
    public function everyGroupCarriesAnEgressDefault(ToolGroup $group): void
    {
        self::assertNotNull(
            ToolDataClassResolver::defaultForGroup($group->value),
            'ToolDataClassResolver has no default for the curated group ' . $group->value,
        );
    }

    #[Test]
    public function aThirdPartyGroupHasNoLabelRatherThanAWrongOne(): void
    {
        self::assertNull(ToolGroup::labelKeyFor('my_ext'));
        self::assertNull(ToolGroup::labelKeyFor(''));
    }

    #[Test]
    public function theLabelKeyPointsAtTheGeneralCatalogue(): void
    {
        self::assertSame(
            'LLL:EXT:nr_llm/Resources/Private/Language/locallang.xlf:tool.group.editing',
            ToolGroup::EDITING->labelKey(),
        );
    }
}

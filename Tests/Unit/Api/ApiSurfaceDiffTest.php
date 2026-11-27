<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Api;

use Netresearch\NrLlm\Tests\Unit\Api\Support\ApiSurfaceDiff;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The classifier on rendered snapshots: which additions break a consumer.
 *
 * A new method on an @api interface breaks every implementation outside
 * nr_llm (0.41.0 called this "BREAKING for implementations"), and a new case of
 * an @api enum breaks every exhaustive match (0.36.0, "BREAKING for exhaustive
 * matches"). Both are additions to the snapshot, so a classifier that counts
 * only removals and changes called them additive and the release took the
 * last number. Documentation/Api/Stability.rst counts them as breaking.
 */
#[CoversNothing] // the subject lives under Tests/, never a coverage target
final class ApiSurfaceDiffTest extends TestCase
{
    private const BEFORE = "Acme\\Port (interface)\n  method send(string \$text): void\n\n"
        . "Acme\\Outcome (enum)\n  case DONE = \"done\"\n\n"
        . "Acme\\Result (class)\n  method text(): string\n";

    #[Test]
    public function aNewMethodOnAnInterfaceIsBreaking(): void
    {
        $diff = ApiSurfaceDiff::between(self::BEFORE, str_replace(
            "  method send(string \$text): void\n",
            "  method close(): void\n  method send(string \$text): void\n",
            self::BEFORE,
        ));

        self::assertSame('breaking', $diff->verdict());
        self::assertSame(['Acme\\Port :: method close(): void'], $diff->addedToContracts);
        self::assertStringContainsString('breaks every implementation', $diff->describe());
    }

    #[Test]
    public function aDiffThatOnlyAddsToAContractLeadsWithItAndListsEachAdditionOnce(): void
    {
        $after = str_replace(
            "  method send(string \$text): void\n",
            "  method close(): void\n  method send(string \$text): void\n",
            self::BEFORE,
        ) . "\nAcme\\Extra (class)\n  method x(): void\n";

        $text = ApiSurfaceDiff::between(self::BEFORE, $after)->describe();

        self::assertStringStartsWith('BREAKING — 1 added to an interface, abstract class or enum, 1 other addition', $text);
        self::assertSame(1, substr_count($text, 'method close(): void'));
        self::assertStringNotContainsString('A removed line breaks', $text);
    }

    #[Test]
    public function aChangedParentIsBreakingAndTheMessageSaysWhenItIsNot(): void
    {
        $before = "Acme\\Result (class) extends Acme\\Base\n  method text(): string\n";
        $after  = "Acme\\Result (class) extends Acme\\Child\n  method text(): string\n";

        $diff = ApiSurfaceDiff::between($before, $after);

        self::assertSame('breaking', $diff->verdict());
        self::assertStringContainsString('subclass of the old one', $diff->describe());
    }

    #[Test]
    public function eachProtectedAbstractMethodIsItsOwnMember(): void
    {
        // memberKey() once read `protected` as the name, so both methods
        // shared one key, only the last survived, and a change to the first
        // one read as an unchanged surface.
        $before = "Acme\\Base (abstract class)\n  method abstract protected a(): string\n  method abstract protected b(): int\n";
        $after  = "Acme\\Base (abstract class)\n  method abstract protected a(): int\n  method abstract protected b(): int\n";

        $diff = ApiSurfaceDiff::between($before, $after);

        self::assertSame('breaking', $diff->verdict());
        self::assertSame('Acme\\Base :: method a', $diff->changed[0]['entry']);
    }

    #[Test]
    public function aNewCaseOfAnEnumIsBreaking(): void
    {
        $diff = ApiSurfaceDiff::between(self::BEFORE, str_replace(
            "  case DONE = \"done\"\n",
            "  case DONE = \"done\"\n  case WAITING = \"waiting\"\n",
            self::BEFORE,
        ));

        self::assertSame('breaking', $diff->verdict());
        self::assertStringContainsString('exhaustive match', $diff->describe());
    }

    #[Test]
    public function aNewMethodOnAClassAndANewInterfaceStayAdditive(): void
    {
        $after = str_replace("  method text(): string\n", "  method text(): string\n  method html(): string\n", self::BEFORE)
            . "\nAcme\\NewPort (interface)\n  method open(): void\n";

        $diff = ApiSurfaceDiff::between(self::BEFORE, $after);

        self::assertSame('additive', $diff->verdict());
        self::assertSame([], $diff->addedToContracts);
        self::assertStringContainsString('changelog.d/', $diff->describe());
    }

    #[Test]
    public function aBreakingDiffNamesTheMarkerAndTheFragment(): void
    {
        $diff = ApiSurfaceDiff::between(self::BEFORE, str_replace("  method text(): string\n", '', self::BEFORE));

        self::assertSame('breaking', $diff->verdict());
        self::assertStringContainsString('`- **BREAKING`', $diff->describe());
        self::assertStringContainsString('changelog.d/', $diff->describe());
        self::assertStringNotContainsString('BREAKING heading', $diff->describe());
    }
}

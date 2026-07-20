<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Domain\Enum\ToolDataClass;
use Netresearch\NrLlm\Service\Tool\ToolDataClassResolver;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every registered tool must have a data class, and every group must have a
 * declared default (ADR-094).
 *
 * This is the test that fails when someone adds a tool in a new, unclassified
 * group: the resolver would fall back to SECRET_ADJACENT, which is safe but
 * silently removes the tool from every external-provider run. Better to fail
 * here than to have an operator debug a vanished tool.
 */
#[CoversClass(ToolDataClassResolver::class)]
final class ToolDataClassCoverageTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function everyRegisteredToolResolvesToADataClassThroughItsGroupOrAnExplicitDeclaration(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        self::assertNotSame([], $registry->names(), 'The registry must not be empty, or this test proves nothing.');

        $resolver   = new ToolDataClassResolver($registry);
        $unclassed  = [];

        foreach ($registry->names() as $name) {
            $tool = $registry->get($name);
            self::assertNotNull($tool);

            if (ToolDataClassResolver::defaultForGroup($tool->getGroup()) === null
                && !in_array($name, self::EXPLICITLY_DECLARED, true)
            ) {
                $unclassed[] = sprintf('%s (group "%s")', $name, $tool->getGroup());
            }

            self::assertInstanceOf(ToolDataClass::class, $resolver->classFor($name));
        }

        self::assertSame(
            [],
            $unclassed,
            "These tools sit in a group with no declared data class, so they silently fall back to the strictest one:\n"
            . implode("\n", $unclassed),
        );
    }

    /** Tools that declare their class directly, so their group default does not have to cover them. */
    private const EXPLICITLY_DECLARED = [
        'get_env_raw',
        'get_php_info_raw',
        'list_be_users_raw',
        'list_be_users',
        'list_be_groups',
        'get_site_config',
        'get_last_exception',
    ];

    #[Test]
    public function theCredentialAdjacentToolsDeclareTheStrictestClass(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);
        $resolver = new ToolDataClassResolver($registry);

        foreach (self::EXPLICITLY_DECLARED as $name) {
            self::assertSame(
                ToolDataClass::SECRET_ADJACENT,
                $resolver->classFor($name),
                sprintf('%s returns credential-adjacent data and must be classified as such.', $name),
            );
        }
    }

    #[Test]
    public function anUnknownToolNameFailsClosed(): void
    {
        $registry = $this->get(ToolRegistry::class);
        self::assertInstanceOf(ToolRegistry::class, $registry);

        self::assertSame(
            ToolDataClass::SECRET_ADJACENT,
            (new ToolDataClassResolver($registry))->classFor('no_such_tool'),
        );
    }
}

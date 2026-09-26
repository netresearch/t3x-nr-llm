<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Service\Tool\UnattendedToolFilter;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * The filter over the registry the container builds, against the builtin
 * tools: a reading tool stays, a writing tool goes (ADR-210). The interface
 * alias is private and unused inside this extension, so this container drops
 * it; a consuming extension injects it.
 */
#[CoversClass(UnattendedToolFilter::class)]
final class UnattendedToolFilterTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function aBuiltinReaderStaysAndABuiltinWriterGoes(): void
    {
        $filter = new UnattendedToolFilter($this->get(ToolRegistry::class));

        self::assertSame(
            ['get_page_content', 'get_pagetree'],
            $filter->unattended(['get_page_content', 'update_page_metadata', 'get_pagetree']),
        );
    }
}

<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Tool;

use Netresearch\NrLlm\Service\Tool\Builtin\UpdatePageMetadataTool;
use Netresearch\NrLlm\Service\Tool\FieldMeasurer;
use Netresearch\NrLlm\Service\Tool\ToolRegistry;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * The registered write tools read the ranges of a structured preview from the
 * installation's extension configuration (ADR-214, item 9): the container
 * wires the configuration into {@see FieldMeasurer}, so an operator's entry
 * replaces the shipped default.
 */
#[CoversClass(FieldMeasurer::class)]
final class StructuredPreviewRangesAreConfigurationTest extends AbstractFunctionalTestCase
{
    /**
     * Only this class's instance carries the entry, so no other test reads it.
     *
     * @var array<string, mixed>
     */
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_llm' => [
                'tools' => ['structuredPreview' => ['ranges' => 'pages.title:2-4']],
            ],
        ],
    ];

    #[Test]
    public function theRegisteredToolMeasuresAgainstTheConfiguredRange(): void
    {
        $this->getService(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Home', 'doktype' => 1, 'slug' => '/', 'description' => 'Old',
            'perms_userid' => 1, 'perms_user' => Permission::ALL,
            'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => 0,
        ]);
        $this->importFixture('BeUsers.csv');

        $tool = $this->getService(ToolRegistry::class)->get('update_page_metadata');
        self::assertInstanceOf(UpdatePageMetadataTool::class, $tool);

        $entries = $tool->structuredPreview(
            ['uid' => 1, 'title' => 'Startseite', 'description' => 'Neu'],
            $this->setUpBackendUser(1),
        );

        self::assertSame(['count' => 10, 'min' => 2, 'max' => 4], $entries[0]->measure?->toArray());
        // The operator's list replaces the default: the description has no range here.
        self::assertSame(['count' => 3, 'min' => null, 'max' => null], $entries[1]->measure?->toArray());
    }
}

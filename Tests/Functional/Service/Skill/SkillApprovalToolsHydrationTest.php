<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Functional\Service\Skill;

use Netresearch\NrLlm\Service\Skill\SkillApprovalRepository;
use Netresearch\NrLlm\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(SkillApprovalRepository::class)]
final class SkillApprovalToolsHydrationTest extends AbstractFunctionalTestCase
{
    /**
     * @return iterable<string,array{string,list<string>|null}>
     */
    public static function storedToolDeclarations(): iterable
    {
        yield 'absent declaration' => ['', null];
        yield 'declared empty' => ['[]', []];
        yield 'normalized name list' => ['["Zed","Alpha","Alpha"]', ['Alpha', 'Zed']];
        yield 'invalid JSON' => ['[broken', []];
        yield 'JSON null' => ['null', []];
        yield 'JSON bool' => ['true', []];
        yield 'JSON string' => ['"GetTca"', []];
        yield 'empty object' => ['{}', []];
        yield 'named object property' => ['{"name":"GetTca"}', []];
        yield 'numeric object property' => ['{"0":"GetTca"}', []];
    }

    /**
     * @param list<string>|null $expected
     */
    #[Test]
    #[DataProvider('storedToolDeclarations')]
    public function approvalReadsDistinguishAbsentAndCorruptDeclarations(
        string $stored,
        ?array $expected,
    ): void {
        $pool = $this->get(ConnectionPool::class);
        self::assertInstanceOf(ConnectionPool::class, $pool);
        $repository = new SkillApprovalRepository($pool);
        $fields = [
            'name' => 'Guide',
            'description' => 'Review tools.',
            'body' => 'Use the reviewed tools.',
            'support_status' => 'full',
            'allowed_tools' => ['Alpha'],
            'process' => false,
        ];
        $digest = '1:' . str_repeat('a', 64);
        $uid = $repository->add(5, 10, $digest, $fields, 'verified', 19);
        self::assertGreaterThan(0, $uid);
        $connection = $pool->getConnectionForTable('tx_nrllm_skill_approval');
        self::assertSame(
            1,
            $connection->update(
                'tx_nrllm_skill_approval',
                ['allowed_tools' => $stored],
                ['uid' => $uid],
            ),
        );
        $exact = $repository->findUnrevoked(5, 10, $digest);
        $latest = $repository->findLatestUnrevoked(5);
        $history = $repository->findBySkill(5);
        self::assertNotNull($exact);
        self::assertNotNull($latest);
        self::assertCount(1, $history);
        foreach ([$exact, $latest, $history[0]] as $approval) {
            self::assertSame($uid, $approval->uid);
            self::assertSame($expected, $approval->allowedTools);
        }

        self::assertTrue($repository->hasUnrevokedApproval(5, 10, $digest));
        self::assertNull($repository->findUnrevoked(5, 11, $digest));
        self::assertNull(
            $repository->findUnrevoked(5, 10, '1:' . str_repeat('b', 64)),
        );
    }
}

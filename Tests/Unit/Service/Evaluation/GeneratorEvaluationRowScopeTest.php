<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare (strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Service\Evaluation;

use ArrayIterator;
use Doctrine\DBAL\Result;
use Netresearch\NrLlm\Service\Evaluation\EvaluationResultRepository;
use Netresearch\NrLlm\Service\Privacy\PrivacyPolicyInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(EvaluationResultRepository::class)]
final class GeneratorEvaluationRowScopeTest extends TestCase
{
    /**
     * @param list<array<string,mixed>> $rows
     */
    private function repositoryReturning(
        array $rows,
    ): EvaluationResultRepository {
        $result = self::createStub(Result::class);
        $result
            ->method('iterateAssociative')
            ->willReturn(new ArrayIterator($rows));
        $expressions = self::createStub(ExpressionBuilder::class);
        $expressions->method('eq')->willReturn('fixture-equality');
        $builder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'orderBy', 'addOrderBy'] as $method) {
            $builder->method($method)->willReturnSelf();
        }

        $builder->method('expr')->willReturn($expressions);
        $builder->method('createNamedParameter')->willReturn(':fixture');
        $builder
            ->expects(self::once())
            ->method('executeQuery')
            ->willReturn($result);
        $pool = self::createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturn($builder);
        return new EvaluationResultRepository(
            $pool,
            self::createStub(PrivacyPolicyInterface::class),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function row(
        string $set = 'set-a',
        string $alias = 'shared-alias',
        string $grader = 'deterministic',
    ): array {
        return [
            'set_identifier' => $set,
            'model_id' => $alias,
            'grader' => $grader,
            'prompt_count' => 1,
            'passed_count' => 1,
            'mean_score' => 1.0,
            'pass_rate' => 1.0,
            'run_date' => 100,
            'generator_provenance' => json_encode(
                [
                    'version' => 1,
                    'providerIdentifier' => 'instance-a',
                    'modelId' => $alias,
                    'reportedModelId' => 'snapshot-1',
                ],
                JSON_THROW_ON_ERROR,
            ),
        ];
    }

    /**
     * @return iterable<string,array{string,string,string}>
     */
    public static function broaderDatabaseCandidates(): iterable
    {
        yield 'case-insensitive set match' => ['Set-A', 'shared-alias', 'deterministic'];
        yield 'case-insensitive alias match' => ['set-a', 'Shared-Alias', 'deterministic'];
        yield 'case-insensitive grader match' => ['set-a', 'shared-alias', 'DETERMINISTIC'];
    }

    #[Test]
    #[DataProvider('broaderDatabaseCandidates')]
    public function baselineRechecksEveryStringDimensionAfterTheDatabaseRead(
        string $set,
        string $alias,
        string $grader,
    ): void {
        $repository = $this->repositoryReturning([$this->row($set, $alias, $grader)]);
        self::assertNull(
            $repository->findLatestForGenerator(
                'set-a',
                'instance-a',
                'shared-alias',
                'snapshot-1',
                'deterministic',
            ),
        );
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function broaderQualityCandidates(): iterable
    {
        yield 'case-insensitive alias match' => ['Shared-Alias', 'deterministic'];
        yield 'case-insensitive grader match' => ['shared-alias', 'DETERMINISTIC'];
    }

    #[Test]
    #[DataProvider('broaderQualityCandidates')]
    public function qualityRechecksModelAndGraderAfterTheDatabaseRead(
        string $alias,
        string $grader,
    ): void {
        self::assertNull(
            $this
                ->repositoryReturning([$this->row(alias: $alias, grader: $grader)])
                ->meanQualityScoreForProviderModel('instance-a', 'shared-alias', 'deterministic'),
        );
    }
}

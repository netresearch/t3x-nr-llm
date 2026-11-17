<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Tests\Unit\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\SkillEvidenceFit;
use Netresearch\NrLlm\Domain\ValueObject\SkillEvidence;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkillEvidence::class)]
final class SkillEvidenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, SkillEvidenceFit}>
     */
    public static function fits(): iterable
    {
        yield 'the reported model answered' => ['gpt-5', 'gpt-5-2025-08-07', 'gpt-5-2025-08-07', SkillEvidenceFit::MATCH];
        yield 'the requested model answered' => ['gpt-5', 'gpt-5-2025-08-07', 'gpt-5', SkillEvidenceFit::MATCH];
        yield 'another model answered' => ['gpt-5', 'gpt-5-2025-08-07', 'claude-sonnet', SkillEvidenceFit::OTHER_MODEL];
        yield 'the answer names no model' => ['gpt-5', 'gpt-5-2025-08-07', '', SkillEvidenceFit::UNKNOWN];
        yield 'the evaluation names no model' => ['', '', 'gpt-5', SkillEvidenceFit::UNKNOWN];
    }

    /**
     * ADR-322: evidence is judged against the model the provider named on
     * the call's answer, matching either id the evaluation stored.
     */
    #[Test]
    #[DataProvider('fits')]
    public function evidenceIsJudgedAgainstTheModelThatAnswered(string $model, string $reported, string $answering, SkillEvidenceFit $expected): void
    {
        $evidence = new SkillEvidence(12, 1_700_000_000, 'nr_llm.smoke', 'v1:' . str_repeat('c', 64), 'default', 'openai', $model, $reported, 'deterministic', 'deterministic-assertions-v1', 2, 2, 1.0, 1.0);

        $judged = $evidence->judgedAgainst($answering);

        self::assertSame($expected, $judged->fit);
        self::assertSame(12, $judged->evaluationUid);
        self::assertSame($reported, $judged->reportedModelId);
    }
}

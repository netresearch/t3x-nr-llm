<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrLlm\Domain\ValueObject;

use Netresearch\NrLlm\Domain\Enum\SkillEvidenceFit;

/**
 * One evaluation that measured a skill version, as it stood when a run used
 * that version (ADR-322).
 *
 * A snapshot, not a pointer: the run keeps these facts even after retention
 * purged the evaluation, so a later evaluation never takes its place. The
 * evaluation uid, run date and benchmark fingerprint together identify the
 * row the snapshot came from; a reader asks whether that row still exists.
 *
 * Identifiers, figures and codes only. The set, configuration, provider and
 * model are identifiers an administrator chose; no prompt, answer or grading
 * detail is here.
 *
 * `scope` says what kind of task the evaluation measured. Evaluations run
 * one-shot completions, never the agent loop, so it is always `completion`
 * today.
 *
 * @internal not part of the @api surface; may change without notice (ADR-127)
 */
final readonly class SkillEvidence
{
    public const SCOPE_COMPLETION = 'completion';

    public function __construct(
        public int $evaluationUid,
        public int $runDate,
        public string $setIdentifier,
        public string $benchmarkFingerprint,
        public string $configurationIdentifier,
        public string $providerIdentifier,
        public string $modelId,
        public string $reportedModelId,
        public string $grader,
        public string $gradingProtocol,
        public int $promptCount,
        public int $passedCount,
        public float $passRate,
        public float $meanScore,
        public string $scope = self::SCOPE_COMPLETION,
        public SkillEvidenceFit $fit = SkillEvidenceFit::UNKNOWN,
    ) {}

    /**
     * This evidence judged against the model that answered the call: a match
     * when the provider named the evaluated model, another model otherwise.
     */
    public function judgedAgainst(string $answeringModel): self
    {
        $fit = match (true) {
            $answeringModel === '' || ($this->modelId === '' && $this->reportedModelId === '') => SkillEvidenceFit::UNKNOWN,
            $answeringModel === $this->reportedModelId || $answeringModel === $this->modelId   => SkillEvidenceFit::MATCH,
            default                                                                            => SkillEvidenceFit::OTHER_MODEL,
        };

        return new self(
            $this->evaluationUid,
            $this->runDate,
            $this->setIdentifier,
            $this->benchmarkFingerprint,
            $this->configurationIdentifier,
            $this->providerIdentifier,
            $this->modelId,
            $this->reportedModelId,
            $this->grader,
            $this->gradingProtocol,
            $this->promptCount,
            $this->passedCount,
            $this->passRate,
            $this->meanScore,
            $this->scope,
            $fit,
        );
    }

    /**
     * @return array{evaluation: int, runDate: int, set: string, benchmark: string, configuration: string, provider: string, model: string, reportedModel: string, grader: string, gradingProtocol: string, prompts: int, passed: int, passRate: float, meanScore: float, scope: string, fit: string}
     */
    public function toArray(): array
    {
        return [
            'evaluation'      => $this->evaluationUid,
            'runDate'         => $this->runDate,
            'set'             => $this->setIdentifier,
            'benchmark'       => $this->benchmarkFingerprint,
            'configuration'   => $this->configurationIdentifier,
            'provider'        => $this->providerIdentifier,
            'model'           => $this->modelId,
            'reportedModel'   => $this->reportedModelId,
            'grader'          => $this->grader,
            'gradingProtocol' => $this->gradingProtocol,
            'prompts'         => $this->promptCount,
            'passed'          => $this->passedCount,
            'passRate'        => $this->passRate,
            'meanScore'       => $this->meanScore,
            'scope'           => $this->scope,
            'fit'             => $this->fit->value,
        ];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $fit = is_string($data['fit'] ?? null) ? SkillEvidenceFit::tryFrom($data['fit']) : null;
        foreach (['evaluation', 'runDate', 'prompts', 'passed'] as $key) {
            if (!is_int($data[$key] ?? null)) {
                return null;
            }
        }

        foreach (['set', 'benchmark', 'configuration', 'provider', 'model', 'reportedModel', 'grader', 'gradingProtocol', 'scope'] as $key) {
            if (!is_string($data[$key] ?? null)) {
                return null;
            }
        }

        $passRate  = $data['passRate'] ?? null;
        $meanScore = $data['meanScore'] ?? null;
        if (!$fit instanceof SkillEvidenceFit || !is_int($passRate) && !is_float($passRate) || !is_int($meanScore) && !is_float($meanScore)) {
            return null;
        }

        /** @var array{evaluation: int, runDate: int, set: string, benchmark: string, configuration: string, provider: string, model: string, reportedModel: string, grader: string, gradingProtocol: string, prompts: int, passed: int, scope: string} $data */
        return new self(
            $data['evaluation'],
            $data['runDate'],
            $data['set'],
            $data['benchmark'],
            $data['configuration'],
            $data['provider'],
            $data['model'],
            $data['reportedModel'],
            $data['grader'],
            $data['gradingProtocol'],
            $data['prompts'],
            $data['passed'],
            (float) $passRate,
            (float) $meanScore,
            $data['scope'],
            $fit,
        );
    }
}

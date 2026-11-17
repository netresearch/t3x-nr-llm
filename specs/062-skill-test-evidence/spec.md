<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A run records the test evidence of the skill versions it used

Governing decision: ADR-322. Builds on spec 061 (ADR-321), which records the
skill versions each model call carried. This adds which evaluations had
measured those versions, at the moment a run used them, and whether they were
measured on the model that answered.

## Requirements

- **TE1 An evaluation names what it measured.** A chat through the default configuration
  puts the skill versions its request carried, and the configuration
  identifier, on the response's metadata. An evaluation stores them (skill,
  source, digest, role) when every prompt's request carried the same
  versions, plus the configuration identifier and the grading protocol
  version. Otherwise, and when its gradings do not share one yardstick or
  none of its decisions could be made, it stores no version.
- **TE2 Fixed at execution time.** After the answer step of the model call
  that first carried a version, the run records a `skill_evidence` step: per
  version, the evaluations stored at that moment that measured exactly its
  digest, newest first, at most three. An empty list states that none
  applied. Nothing is looked up later to fill in or replace a recorded entry.
- **TE3 What evidence holds.** Evaluation uid, run date, set, benchmark
  fingerprint, configuration, provider, model, reported model, grader,
  grading protocol, prompt and pass counts, pass rate, mean score, scope
  (`completion`) and fit. No prompt, answer or grading detail.
- **TE4 Fit.** `match` when the model the provider named on the call's answer
  is the evaluated model (its reported or its requested id), `other_model`
  otherwise, `unknown` when either names no model.
- **TE5 Approval apart.** The approval stays in the usage entry (spec 061);
  evidence is a separate step.
- **TE6 Retention.** The measured versions are purged with their evaluation.
  The run keeps its snapshot; the timeline marks a snapshot whose evaluation
  is no longer stored (same uid, run date, fingerprint and set) as `report=gone` (`report=unknown` when that cannot be checked) and never
  shows a newer evaluation in its place.
- **TE7 Composed only; a failed lookup.** The step names composed versions
  only: a version loaded during the run, or carried over a pause by a
  resumed segment, is left out; the usage step's inclusion says which it
  was. A lookup that fails is logged and records no evidence step
  for that call; the run continues.

## Out of scope

- Evaluating agent runs, tool choice, write proposals or multi-step
  processes: no evaluation runs the agent loop (see Limits).
- Choosing or requiring evidence before a run may use a version.
- Comparing providers: the answer names its adapter, an evaluation its
  provider record.

## Limits

- Skills loaded on demand are never covered; scope is always `completion`.
- Evidence matches by skill and digest, not by configuration or role.
- An evaluation with a pinned provider runs without the default
  configuration's skills and covers no version.
- Decision-graded evaluations are recorded; their comparable revision stays
  unknown (ADR-271).

## Verification

| Requirement | Evidence |
| --- | --- |
| TE1 | `SkillConfigInjectionTest::chatNamesTheSkillVersionsItsRequestCarried`, `EvaluationServiceTest::anEvaluationNamesTheSkillVersionsEveryPromptCarried`, `anEvaluationWhosePromptsDisagreeNamesNoSkillVersion`, functional `SkillEvidenceRepositoryTest::anEvaluationWithoutOneYardstickIsNoEvidence` |
| TE2, TE3 | `ToolLoopServiceSkillUsageTest::aRunWithoutToolsRecordsTheEvidenceAfterItsOnlyAnswer`, `ToolLoopServiceSkillUsageTest::theEvidenceOfAVersionIsRecordedAfterTheAnswerAndJudgedAgainstItsModel`, functional `SkillEvidenceRepositoryTest::anEvaluationIsFoundForTheVersionItMeasuredWithWhatItTested`, `theNewestEvaluationsComeFirstAndAtMostThree` |
| TE4 | `ToolLoopServiceSkillUsageTest::evidenceFromAnotherModelIsMarkedAsSuchAfterAFallback`, `SkillEvidenceTest::evidenceIsJudgedAgainstTheModelThatAnswered` |
| TE5 | `ToolLoopServiceSkillUsageTest::theEvidenceOfAVersionIsRecordedAfterTheAnswerAndJudgedAgainstItsModel` (a separate `skill_evidence` step; the usage entry keeps the approval, spec 061) |
| TE6 | `RunTimelineFactoryTest::aSkillEvidenceStepShowsTheRecordedEvaluationAndWhetherItIsStillStored`, functional `SkillEvidenceRepositoryTest::retentionPurgesTheEvaluationAndTheVersionsItMeasured`, `RetentionCoverageTest` |
| TE7 | `ToolLoopServiceSkillUsageTest::aLoadedVersionIsLeftOutOfTheEvidence`, `aResumedSegmentRecordsNoEvidenceForTheVersionsItCarries`, `aFailedEvidenceLookupRecordsNoStepAndTheRunFinishes` |

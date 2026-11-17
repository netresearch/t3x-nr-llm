<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A run records the skill versions it passed to a model

Governing decision: ADR-321. For any run it must be possible to tell which
skill versions a model call actually received, so that a change in a run's
answer can be traced to a skill change rather than a model or input change.
Before this, only the process skill of a run was recorded (lineage,
ADR-228). The pins of every other instruction lived in the resumable state,
which exists only while a run waits and is cleared when the run ends; a run
that never paused stored no pin at all. Context skills had no pin, and in
metadata mode a load left only the tool name behind.

## Requirements

- **SU1 Passed means sent.** A skill version is recorded as `passed` in the
  round of the first model call of a segment whose request carries it, before
  that call's request step. A version whose segment ends, pauses or fails
  before such a call is not recorded as passed. Passed means handed to the
  call: a call the manager refuses before the provider, or a run cancelled
  between the entry and the request step, keeps the entry. An entry counts as
  passed when a request step of the same round follows it.
- **SU2 Every channel.** Instruction versions, the process version and the
  context skills of the delimited block are recorded. A context skill is
  recorded only when the block was placed into the messages.
- **SU3 Only what reached the prompt.** A skill that is only attached, only
  offered in the load catalogue, dropped by the byte budget or dropped by the
  integrity check produces no record.
- **SU4 Loads.** A successful load is recorded as `loaded` in the round of the
  load, before the tool step that answers it, and as `passed` at the next
  model call. A refused or repeated load records nothing.
- **SU5 Pauses.** A resumed segment records the versions it holds as `passed`
  at its first model call, as `carried` (or `loaded`, for a load the held turn
  ran on resume). A version whose approval was withdrawn while the run waited
  stops the resume (ADR-214 item 6) and nothing is recorded after it.
- **SU6 What an entry holds.** Skill uid, source uid, version digest, the
  skill's identifier, role (`instruction`, `process`, `context`), inclusion
  (`composed`, `loaded`, `carried`), state (`loaded`, `passed`), and the
  approval that held when the entry was recorded: its uid and `approved`, or
  `none` for a context skill, or `unknown` when no unrevoked approval was
  found. No name, description or body; the identifier is the record's, never
  the approved name a process section falls back to.
- **SU7 Privacy and retention.** The record is a step of kind `skill_usage`,
  stored as it is at every privacy level, kept after the run ends and purged
  with the run's other events.
- **SU8 Readable.** The run timeline shows each entry as identity, digest
  prefix and codes.

## Out of scope

- Which test evidence covered a version (spec 062, ADR-322).
- Input and configuration digests (P3 of the lineage analysis).
- Whether a version stayed in the transcript after the context window pruned
  it: the record states the first call that carried it.
- Configured or offered skills as records of their own.

## Limits

- A context skill of a resumed segment is not recorded again: the resumable
  state holds pins of instructions only. Its earlier entry in the same run
  stands.
- A legacy context skill (no version digest) is recorded with digest `''`.
- The identifier of a loaded, carried or on-demand process version is read
  from the configuration's skills when the segment starts; a skill detached
  since, and a playground's forced skill carried into a resumed segment, has
  identifier `''`. Uid and digest still identify the version.
- A caller of the public `runLoop()` that passes an already assembled
  transcript (`skipAssembly`) gets no entry for the instructions baked into
  it, as it gets no pins for them.

## Verification

| Requirement | Evidence |
| --- | --- |
| SU1, SU2, SU6 | `ToolLoopServiceSkillUsageTest::theComposedVersionsAreNamedAtTheFirstSendAndNotAgain`, `aRunWithoutToolsNamesItsVersionsBeforeItsOnlyCall`, `theClosingCallAtTheRoundLimitNamesWhatItCarries` |
| SU6 (process identity, no name) | `ToolLoopServiceSkillUsageTest::aProcessIsNamedByItsRecordIdentifierAndNeverByItsName` |
| SU1 (changed skill) | `ToolLoopServiceSkillUsageTest::aChangedSkillIsNamedWithItsNewDigest` |
| SU2 (block not placed) | `SkillInjectionBoundaryTest::missingUserPreservesTheEntireMessageListAndFencedSkillsCannotEnterSystemRole` |
| SU3 | `ToolLoopServiceSkillUsageTest::aSkillOnlyOfferedInTheCatalogueIsNeverNamed`, `SkillComposerTest::dropsTaskAdditiveBeforeConfigBaselineWhenOverBudget` |
| SU4 | `ToolLoopServiceSkillUsageTest::aLoadIsNamedAsLoadedAndThenAsPassedByTheNextCall`, `aLoadedVersionWhoseRunPausesBeforeTheNextCallIsNotNamedAsPassed`, `aRefusedLoadNamesNothing` |
| SU5 (carried process, unknown approval) | `ToolLoopServiceSkillUsageTest::aResumedProcessIsCarriedAsAProcess`, `aVersionWithoutAHeldApprovalIsNamedWithAnUnknownApproval` |
| SU5 | `ToolLoopServiceSkillUsageTest::aResumedSegmentNamesTheVersionItCarriesAtItsFirstSend`, `aLoadHeldInAPausedTurnIsNamedWhenTheResumeRunsIt`, `aLoadHeldInATurnPausedForInputIsNamedWhenTheResumeRunsIt`, `aRevocationDuringThePauseStopsTheResumeBeforeAnythingIsNamed` |
| SU6, SU7 | `ToolLoopServiceSkillUsageTest::theRecordCarriesNoTextOfTheSkill`, functional `SkillUsagePersistenceTest` (every privacy level, after the run ends, purge) |
| SU8 | `RunTimelineFactoryTest::aSkillUsageStepShowsEachVersionItNames` |

Each of SU1, SU2, SU4 and SU5 was also shown failing by a mutation of the
code that implements it (send site removed, ledger not remembering what it
passed, load not recorded, resume ledger not handed to the loop, block rule
removed, approval lookup removed, the no-tools send site removed, the
closing send site removed, the input resume not handing over its ledger,
the process entry named by the approved name).

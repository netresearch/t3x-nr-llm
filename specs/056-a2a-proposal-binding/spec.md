<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A write of the page metadata skill is the proposal a check judged

Governing decision: ADR-298 (amends ADR-216, the invocation history). Its
first users replace the read-first rule and the unchanged-row check of the
page metadata skill, so ADR-297 records ADR-298 as amending it. Builds on
spec 055. Baseline: the branch of spec 055 at b9001f0b4.

Spec 055 held a write of the page metadata skill to a read of any page
earlier in the run and to a row whose `tstamp` was not later than the task's
acceptance. Neither binds the write to what was judged: the model can check
one variant and write another, and a writer that started before the task and
saved after it leaves an older `tstamp`. This specification gives the
invocation history keyed digests of a call's arguments and of the record
state it observed, adds a check tool, and replaces both conditions with one
rule that compares the write with the latest passed check.

## Requirements

- **PB1 Digests.** `InvocationDigests` (`@api`) computes `v1:` and the hex
  HMAC-SHA256 of the canonical form version 1 (ADR-298), keyed with
  `SYS/encryptionKey` joined with `nr_llm.history.arguments` or
  `nr_llm.history.state`. `arguments()` returns null for a value that cannot
  be encoded and for an empty key. Canonical form: an array that is not a
  list (decided before sorting) is an object with its keys sorted as strings,
  a list keeps its order, slashes and Unicode are not escaped, integers and
  numeric strings differ, floats are written with `serialize_precision` -1,
  and a float without a fraction below 2^53 in magnitude is the integer
  (`-0.0` is `0`).
- **PB2 Observed state.** `observe(table, uid, fields, execution)` reads the
  record as the run's workspace shows it (ADR-260: the acting user's
  workspace where the run recorded the same one, else live), with the deleted
  restriction only, takes exactly the listed fields as strings (null stays
  null), and returns an `ObservedState` with the digest of
  `{table, uid, fields}` and the values. It returns null for no such row in
  that workspace (deleted, a version of another workspace, a version row under
  its own uid, a record new in another workspace, a table without versioning
  seen from a draft workspace), an unknown table, an empty field list, or a
  field that is not a column. `ObservedState` refuses a digest that does not
  match `^v1:[0-9a-f]{64}\z`.
- **PB3 Result.** `ToolResult::withObservedState()` sets the public
  `observedState`; an error result ignores it, every wither and
  `withBoundedChannels()` carry it, and the bounding of an error result drops
  it.
- **PB4 History.** `ToolInvocationHistory::append()` takes two optional
  trailing digests; null omits the key. An entry stored without them still
  loads. A present key that does not match the format makes the whole stored
  history unreadable and incomplete; `append()` refuses one.
- **PB5 Runtime.** The tool loop appends every entry with the digest of the
  call's final arguments where they can be encoded, and with the digest of
  the result's observed state for an `ok` result, also for a result handed
  over by a dependency. The production container wires the digests into the
  loop.
- **PB6 Check tool.** `check_page_metadata` (group `editing`, read effect,
  disabled by default) takes exactly the arguments of `update_page_metadata`
  and judges them with spec 055 PC1 to PC3 for the run's request: the
  live uid of the requested row, only requested
  fields and at least one, each value text with a visible character within
  its limit. A passed check returns `ok` with the observed state of the
  requested row's proposed fields; a failed one returns an error with the
  reason. Outside a run of the skill it fails. Its verdict does not depend on
  the fields' current values, and its result names none.
- **PB7 Binding rule.** The rule `page_metadata_binding` (asked when the call
  runs, not before the card; `requiresCompleteHistory()` false) holds every
  `update_page_metadata` call the contract rule holds and admits, and that the
  scope does not refuse. The latest `ok` entry of `check_page_metadata` must
  carry the arguments digest of the write's arguments
  (`proposal_not_checked` otherwise, also with no check), and its state digest
  must equal the state the check tool would observe now (`changed_since_check`
  otherwise, also when there is none). With an incomplete history and no
  passed check since, the write is refused as `history_incomplete`.
- **PB8 Skill.** The handler offers `check_page_metadata` between the read
  and the write tool, tells the model to check the exact proposal and write
  the same arguments, and refuses with `-32004` when the profile does not
  grant it.

## Not in scope

- A check bound to fields other than the proposed ones: a field outside the
  proposal can change between the check and the write unseen.
- A change between the rule's comparison and the write itself.
- Comparing a check's state with the approval card's preview (ADR-184 does).
- A second user of the digests besides the page metadata skill.

## Proof

| Requirement | Suite |
|-------------|-------|
| PB1 | `InvocationDigestsTest` (unit): pinned canonical JSON and key, key order, lists and objects with integer keys, `1.0` and `1`, `-0.0`, floats from 2^53 on, an exponent, unicode and slashes, integers and numeric strings, the precision setting, unencodable values, empty key, purpose and key separation |
| PB2 | `InvocationDigestsObserveTest` (functional): live, draft version, new record, hidden record, deleted, deleted in the draft, version uid, another workspace, unknown table, missing column, no fields, a table without versioning, a run that did not record the workspace; `InvocationDigestsTest::anObservedStateTakesOnlyAVersionOneDigest` |
| PB3 | `ToolResultTest::anObservedStateIsCarriedOnSuccessAndRefusedOnFailure`, `::boundingIsExhaustiveOverThePropertiesOfTheResult` |
| PB4 | `ToolInvocationHistoryTest::digestsSurviveASuspensionAndAMissingOneIsOmitted`, `::anEntryStoredWithoutDigestsStillLoads`, `::appendRefusesADigestOfAnotherForm`, `::unprovenHistoryNeverBecomesProvenEmpty` (malformed, unknown version, null, trailing newline) |
| PB5 | `ToolLoopServiceTest::historyEntriesCarryTheArgumentsDigestAndTheObservedStateDigest` (unit: an executed call, a denied call, a dependency's result on resume), `A2aPageMetadataContractRuleTest::aValueWithinItsLengthIsWrittenAsADraftAndListedInTheResult` (the digests survive the suspended state), `::aCheckAndAWriteInOneTurnReachTheApprovalAndWrite`; `ToolLoopGateWiringTest::productionWiresTheHistoryDigestsIntoTheLoop` |
| PB6 | `A2aPageMetadataContractRuleTest::aWriteOfAnotherVariantThanTheCheckedOneIsRefused` (passed), `::aFailedCheckDoesNotCount` (failed with its reason), `::theCheckFailsOutsideAPageMetadataTask`, `::aUidTheScopeAlsoAdmitsIsHeldToTheContract` (a version uid is refused; a change of the draft version after the check is `changed_since_check`); `BuiltinToolGroupsTest`, `ProductFactsConsistencyTest` |
| PB7 | `A2aPageMetadataContractRuleTest::aWriteWithoutACheckIsRefused`, `::aWriteOfAnotherVariantThanTheCheckedOneIsRefused`, `::aLaterCheckOfAnotherVariantReplacesAnEarlierOne`, `::aFailedCheckDoesNotCount`, `::aRecordChangedAfterTheCheckIsRefused`, `::aMovedTimestampAloneIsNoChange`, `::anIncompleteHistoryRefusesOnlyAWriteOfThisSkill`, `::aUidTheScopeAlsoAdmitsIsHeldToTheContract` (a changed version row) |
| PB8 | `A2aPageMetadataSkillTest::aRequestBecomesARunBoundToThePageTheTableAndTheLanguage`, `::aConfigurationWithoutTheDraftAndScopeRequirementsMakesTheSkillUnavailable` |

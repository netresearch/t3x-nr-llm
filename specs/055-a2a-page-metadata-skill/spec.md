<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A2A skill: the metadata of one page in one language, as a draft

Governing decisions: ADR-296 (amends ADR-295) and ADR-297 (amends
ADR-296). Builds on spec 051 (A2A server
role, stage 4: the first write capability) and ADR-295 (the scope of an
assignment). Baseline: `main` at 485306afe.

The server role publishes configurations as skills, and its one domain handler
passes the client's text to the run. A remote agent that wants "the English
metadata of page 42, as a draft" has to describe it in prose, and inside the
client's draft workspace the run may change anything its technical user may
change. This specification adds a skill with a checked request and a run bound
to what the request names.

## Requirements

- **PM1 Contract, version 1.** The handler `nr_llm.page_metadata.v1` reads
  the whole text of the client's message as one JSON object with exactly the
  keys `page` (JSON integer ≥ 1), `language` (JSON integer ≥ 0) and `fields`
  (a non-empty list of distinct names from `title`, `subtitle`, `nav_title`,
  `abstract`, `description`, `keywords`, `seo_title`, `og_title`,
  `og_description`, `twitter_title`, `twitter_description`, `twitter_card`).
  A text over 2048 bytes, nested deeper than the contract, or of another shape
  is refused.
- **PM2 Refusal before a task.** A request that breaks PM1, names a page that
  is a translation, or a language the page has no translation in (workspace
  view; a translation deleted in the workspace does not count) is answered
  with JSON-RPC `-32602` on `message.parts`. The description names the rule,
  never a value of the client. A page that does not exist, is deleted (also
  as a delete placeholder in the workspace), or that the client's technical
  user may not see (its web mounts and page permissions), a row to write
  (the page for language 0, else the translation) that user may not edit,
  and a requested language that user may not edit all get one and the same
  refusal; a page that is a translation is told apart only when the user may
  edit its language. The default-language page of a requested translation
  only has to be visible, so a translator who may edit only that language is
  served.
  No task, run, dedup row or capacity is used.
- **PM3 Scope.** The accepted run's actor carries the scope
  `pages: [{page, depth 0}]`, `tables: [pages]`, `languages: [language]`,
  derived from the checked values. The scope is stored with the queued run
  and held by every later call, also after a question to the client and the
  client's answer (spec 051 A2A-MT1 to A2A-MT3).
- **PM4 Message and tools.** The run's user message is built from the checked
  values and names the row to write (`pages:<uid>`). The run is offered
  `get_page_content` and `update_page_metadata`, and `ask_choice` when the
  profile grants it; no other tool.
- **PM5 Setup.** The handler refuses with `-32004` "This skill is not
  available." and logs the reason when the profile does not write or does not
  grant `get_page_content` and `update_page_metadata`, or when the LLM
  configuration does not require a draft workspace (ADR-293) and the scope of
  an assignment (ADR-295). A client not pinned to a draft workspace is refused
  before the handler, as for every writing profile (spec 051 A2A-MT5).
- **PM4a Fields.** The requested fields are named in the run's message; the
  handler does not hold the write tool to them (the tool accepts any of its
  fields on the scoped row). The rule of PC2 does.
- **PM6 Result.** The task's artifacts are those of a writing A2A task: the
  answer as text and the `writes` artifact of A2A-MT6.

## Contract rule (ADR-297)

The rule `page_metadata_contract` (a pre-approval invocation rule) holds
every call of `update_page_metadata` in a run whose A2A task belongs to a
profile with the handler `nr_llm.page_metadata.v1` (read from the profile
row even when it is hidden or deleted; a missing row refuses). It reads the
request from the first entry of the task's history with the handler's
parser. A refusal goes back
to the model and into the governance log as `invocation_denied` with the
rule's identifier.

- **PC1 Row.** A call aimed at the live uid of the requested row (the
  page, or its translation in the requested language, as the client's
  workspace shows them) is checked by PC2 to PC5; the write tool takes no
  other uid. A row the scope's own check refuses is left to the scope
  (PM3), whose reason the model reads. Every other row, including a row the
  scope admits by another path and a requested row that no longer exists, is
  `not_the_requested_row`; an unreadable request is `request_unreadable`.
- **PC2 Fields.** Only fields the request names, at least one:
  `field_not_requested`.
- **PC3 Values.** Each value text with at least one visible character
  (whitespace, separators and format characters do not count), within its
  limit (title,
  subtitle, nav_title, keywords, twitter_card 255; abstract 1000;
  description, og_description, twitter_description 300; seo_title, og_title,
  twitter_title 100 characters): `value_not_allowed`.
- **PC4 Read first.** A `get_page_content` call with the outcome `ok`
  earlier in the run, of any page and including its answer that a page was
  not found: `page_not_read`, by the rule `page_metadata_read_first`. It
  depends on the history and is asked when the call runs, after its
  approval (ADR-293); a read and a write in one turn reach the approval. A
  run with an incomplete history and no read since has its writes of this
  skill refused (`history_incomplete`); other calls are not affected.
- **PC5 Unchanged.** The row's `tstamp` as the client's workspace shows it
  (its draft version when one exists, else the live row) is not later than
  the task's creation: `changed_since_accepted`. `tstamp`
  is the writer's `EXEC_TIME`, the start of its request, so a writer that
  started before the task was accepted and saved after it is not seen.
- **PC6 Only this skill.** A run without an A2A task, or whose task belongs to
  another handler, is not held.

## Not in scope

- A JSON data part as input (spec 051 stage 5c), and a machine-readable input
  schema on the agent card (the A2A 1.0 `AgentSkill` has no such field).
- Creating a missing translation.
- A second approver specific to this skill; a configuration that requires one
  applies as to every A2A run.
- A check bound to the exact proposal and the data state it read: the
  invocation history records no arguments and no results. PC4 is therefore
  satisfied by a read of any page, and PC5 by the writer's start time.
- A task with a full history (100 entries, after which the oldest is
  dropped), or a configuration whose privacy level stores no message
  content: the request is not read, and every write of the skill is refused
  (`request_unreadable`).

## Proof

| Requirement | Suite |
|-------------|-------|
| PM1 | `A2aPageMetadataRequestTest` (unit): every refused shape and type, the description without the client's value |
| PM2 | `A2aPageMetadataSkillTest::aKeyOutsideTheContractOrAWrongTypeIsRefusedBeforeATaskExists`, `::aMissingPageOrAMissingTranslationIsRefusedBeforeATaskExists`, `::aPageTheUserMayNotReadIsRefusedLikeOneThatDoesNotExist` (functional): no task and no run row |
| PM3 | `A2aPageMetadataSkillTest::aRequestBecomesARunBoundToThePageTheTableAndTheLanguage` (the stored actor), `::aWriteToAnotherPageIsRefusedAsOutsideTheScope`, `::theTranslationRowReachesTheApprovalAndTheDefaultLanguageRowIsRefused`, `::theScopeHoldsAfterAQuestionAndTheClientsAnswer` (scripted model, the container's policies) |
| PM4 | `A2aPageMetadataSkillTest::aRequestBecomesARunBoundToThePageTheTableAndTheLanguage` |
| PM5 | `A2aPageMetadataSkillTest::aConfigurationWithoutTheDraftAndScopeRequirementsMakesTheSkillUnavailable` |
| PM6 | existing `A2aWriteProfileTest` (the artifacts of a writing task do not depend on the handler); `A2aPageMetadataContractRuleTest::aValueWithinItsLengthIsWrittenAsADraftAndListedInTheResult` end to end |
| PC1 | `A2aPageMetadataContractRuleTest::aCallAimedAtAnotherRowIsTheScopesToRefuse`, `::aRowTheScopeAdmitsThroughATranslationIsHeldToTheContract`, `::aUidTheScopeAlsoAdmitsIsHeldToTheContract` (the version uid and a second translation, refused before the approval), `::anInvisibleValueAndAVersionOfAnotherWorkspaceAreRefused` (a version of another workspace) |
| PC2 | `A2aPageMetadataContractRuleTest::aFieldTheRequestDidNotNameIsRefused` |
| PC3 | `A2aPageMetadataContractRuleTest::aValueOverItsLengthIsRefusedAndLogged` (governance log), `::aValueWithinItsLengthIsWrittenAsADraftAndListedInTheResult` (approved, written as a draft, listed in `writes`), `::anInvisibleValueAndAVersionOfAnotherWorkspaceAreRefused` (only whitespace and format characters) |
| PC4 | `A2aPageMetadataContractRuleTest::aWriteBeforeThePageWasReadIsRefused` (refused after the approval, no draft), `::aReadAndAWriteInOneTurnReachTheApproval`, `::anIncompleteHistoryRefusesOnlyAWriteOfThisSkill` |
| PC5 | `A2aPageMetadataContractRuleTest::aRowChangedAfterTheTaskWasAcceptedIsRefused` |
| PC6 | `A2aPageMetadataContractRuleTest::aTaskOfAnotherSkillIsNotHeldToTheContract`, `::aHiddenProfileDoesNotSwitchTheContractOff` (a hidden profile is still held; a missing one refuses), `::aHistoryPastItsCapIsNotReadAsTheRequest` |

<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A2A skill: the metadata of one page in one language, as a draft

Governing decision: ADR-296 (amends ADR-295). Builds on spec 051 (A2A server
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
  never a value of the client. A page that does not exist, is deleted, or
  that the client's technical user may not show (its web mounts and page
  permissions), and a language that user may not edit, all get one and the
  same refusal, so a client learns nothing about pages its user may not read.
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
- **PM4a Fields.** The requested fields are named in the run's message; this
  handler does not hold the write tool to them (the tool accepts any of its
  fields on the scoped row). A pre-write rule does.
- **PM6 Result.** The task's artifacts are those of a writing A2A task: the
  answer as text and the `writes` artifact of A2A-MT6.

## Not in scope

- A JSON data part as input (spec 051 stage 5c), and a machine-readable input
  schema on the agent card (the A2A 1.0 `AgentSkill` has no such field).
- Creating a missing translation.
- A second approver specific to this skill; a configuration that requires one
  applies as to every A2A run.
- Rules on the values written (lengths, "unchanged since read"): spec 055 is
  extended for them separately.

## Proof

| Requirement | Suite |
|-------------|-------|
| PM1 | `A2aPageMetadataRequestTest` (unit): every refused shape and type, the description without the client's value |
| PM2 | `A2aPageMetadataSkillTest::aKeyOutsideTheContractOrAWrongTypeIsRefusedBeforeATaskExists`, `::aMissingPageOrAMissingTranslationIsRefusedBeforeATaskExists`, `::aPageTheUserMayNotReadIsRefusedLikeOneThatDoesNotExist` (functional): no task and no run row |
| PM3 | `A2aPageMetadataSkillTest::aRequestBecomesARunBoundToThePageTheTableAndTheLanguage` (the stored actor), `::aWriteToAnotherPageIsRefusedAsOutsideTheScope`, `::theTranslationRowReachesTheApprovalAndTheDefaultLanguageRowIsRefused`, `::theScopeHoldsAfterAQuestionAndTheClientsAnswer` (scripted model, the container's policies) |
| PM4 | `A2aPageMetadataSkillTest::aRequestBecomesARunBoundToThePageTheTableAndTheLanguage` |
| PM5 | `A2aPageMetadataSkillTest::aConfigurationWithoutTheDraftAndScopeRequirementsMakesTheSkillUnavailable` |
| PM6 | existing `A2aWriteProfileTest` (the artifacts of a writing task do not depend on the handler) |

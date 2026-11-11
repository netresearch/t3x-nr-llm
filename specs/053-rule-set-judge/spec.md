<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A rule set can be judged by a second model

Governing decision: ADR-288, amending ADR-243 and ADR-238. A rule set is a
tagged snippet plus a term list (ADR-243); the term list checks words, and a
prose rule such as "address the reader formally" was context only. The product
owner decided on 2026-11-11 (NEXT-169) to add an optional check of the prose
rules by a small model, set to report or to block.

## Requirements

- **R1 Off by default.** A configuration has a mode `off`, `report` or `block`;
  `off` is the default, and an unknown stored value reads as `off`. Off makes no
  call.
- **R2 Rule set.** The rules are the non-empty lines of the active, visible
  snippets carrying one of the configuration's rule-set tags, numbered in
  snippet order.
- **R3 Judging model.** An explicit configuration; none chosen, itself, missing
  or inactive is a failed check.
- **R4 Bounded.** One call per text through the completion service of the
  judging configuration; no repair round; timeout capped at 20 seconds.
- **R5 Structured and verified.** The answer must match a JSON schema; a
  violation counts only when its rule exists and its passage is in the text;
  violations of which none can be found make the answer unusable.
- **R6 Text is data.** The prompt says so, and the text sits between markers
  carrying a random value.
- **R7 Report.** The text is delivered with a hint per violation quoting the
  rule line and the passage; a failed check delivers it with a "not checked"
  hint.
- **R8 Block.** A violation stops the text with a message quoting rule line and
  passage; a failed check stops it too. The governance log keeps the rules and
  not the passage.
- **R9 Scope.** Only finished texts: no tool round, image description, agent
  run step or text under 30 words; JSON by its strings. Runs after the other
  guardrails; the judge's own call is not judged.
- **R10 Egress.** No call when the judging configuration's trust zone is less
  trusted than the judged one's or does not admit a rule snippet's class.
- **R11 Delivery.** Hints in `CompletionResponse::$metadata['guardrailHints']`;
  a held or live stream returns them; block mode holds a stream, report mode
  streams live and judges at the end.

## Out of scope

- Judging the chat or any agent run, and tool-call arguments.
- Showing hints on surfaces that receive only a string or an array from nr-llm.
- A model tier or a default judging model.

## Verification

| Requirement | Evidence |
| --- | --- |
| R1, R3, R7, R8, R9, R10 | `RuleSetJudgeGuardrailTest` |
| R2 | functional `RuleSetJudgeWiringTest`, `JudgeAnswerParserTest` (rule numbering) |
| R4, R6 | `RuleJudgeTest` |
| R5 | `JudgeAnswerParserTest` |
| R8 audit, R11 | `GuardrailHintDeliveryTest` |
| R1 storage, R7/R8 failure through the real container, ordering | functional `RuleSetJudgeWiringTest` |
| Live | NEXT-169 comment: a run against a real small model |

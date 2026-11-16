<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A text for use outside TYPO3 is checked as it is delivered

Governing decisions: ADR-300 (the check service), ADR-302 (the end-of-run check
and the check-only run), ADR-301 (the result contract). They extend ADR-238
(term lists), ADR-288 (rule-set judge) and ADR-290 (further configurations).

An editorial result, a social post or a reply to a comment, is used outside
TYPO3. Today a run that offers tools checks only what a writing tool would
store; its answer is conversation and is not checked. The judge skips texts
under 30 words, and a required term is asked of no agent run at all. A text
edited outside nr-llm can only be checked by generating it again.

## Requirements

### Check service (ADR-300, E1)

- **X1 One text, one configuration.** The service checks a given text against a
  configuration and the further configurations a caller names (ADR-290): the
  forbidden and the required terms of every term list naming one of them, and
  the rule set of every one whose judge is on.
- **X2 No word limit.** A text of any length is checked: forbidden terms,
  required terms and the judge. The global limits of the guardrail pipeline
  (30 words for the judge and for required terms) stay as they are.
- **X3 JSON.** A text is searched as sent. A text that is one JSON document
  (fence allowed) is decoded by `json_decode()` and every key and string value
  is searched too; a document with a duplicate key, and a text
  `json_decode()` refuses that holds a JSON escape, fail the term check. Any other text is plain text. The judge reads
  the text as sent; a passage it quotes counts when found in the text or a
  decoded string.
- **X4 Status.** `passed`, `violation`, `not_checked` or `failed`. A broken term
  or rule is `violation`. A judge that gives no usable answer, has no judging
  configuration, an empty rule set, or may not receive the text is
  `not_checked` in report mode and `failed` in block mode, where the mode is
  the strictest of every judged configuration (ADR-290). A term check that
  cannot run is `failed`. A text checked against no rule at all is
  `not_checked`, never `passed`. An empty text is not judged.
- **X5 Blocking.** A broken term, a broken rule under a block-mode judge and
  a failed check block delivery; a broken rule does not when the strictest
  mode of every judged configuration is report. That combined mode applies to
  every rule finding and every judge failure, whichever judge produced it. A failed check is carried apart from the status, so a non-blocking
  finding cannot hide it, and the service never throws.
- **X6 Findings.** Each names its kind, its source (list or snippet), the term
  or the rule line and, for a rule, the passage.
- **X7 Egress.** The judge is not asked when its zone is less trusted than the
  zone the text was written in, or does not admit a rule snippet's data class
  (as ADR-288 R10, one implementation for both callers).
- **X8 Digests.** The report carries the SHA-256 of the exact text and a digest
  of the rule state: per configuration its judge mode, judge tags, judging
  configuration (its uid, active flag, model and provider) and snippet tags,
  the text of its selected snippets and rule
  snippets, and its term lists. Snippets one run forces are not included.
- **X9 Generator and judge apart.** The report names the judging configuration
  separately from the configuration whose text was checked.

### End-of-run check and check-only run (ADR-302, E3)

- **X10 Final answer.** A run whose starter asks for it checks its final
  answer after the loop and before the completion is stored, also when the run
  offered tools and only read, and also for a short answer.
- **X11 No lock.** The check runs before the transaction that completes the
  run; no model call happens under the run-row lock.
- **X12 Result stored.** The report is stored as a run event, at the run's
  answer privacy level: passages at `full`, redacted like the answer at
  `redacted`, none at `metadata` or `none`.
- **X13 Block.** A blocking report ends the run FAILED with the guardrail
  outcome; no completed result carries the text.
- **X14 Check-only run.** A run of the check kind takes the submitted text as
  its answer and checks it: no generator call, no tool call, the text unchanged.
- **X15 One judge call per text.** A run with the end check does not also judge
  its answer in the pipeline; written tool arguments are still judged there.
- **X16 Starter decides.** Only the server side sets the end check; nothing in
  the request a client sends switches it on or off.

### Result contract (ADR-301, E2 part a)

- **X17 Content.** The content artifact has exactly one text part holding the
  delivered text; no field name, no hint, paragraphs as blank lines.
- **X18 Report artifact.** A second artifact `report` with one data part: the
  status, the editorial state, the findings at the answer level, the digest of
  the delivered text and of the rule state, judge and generator.
- **X19 Bound to the delivered text.** `passed` only when the stored check's
  digest equals the digest of the text in the content artifact; any difference
  is `not_checked`.
- **X20 Editorial state.** `draft`, `needs_revision` or `needs_human_review`,
  separate from the task state: a completed task is no approval to publish.
- **X21 Rule state changed.** When the rule state at the check differs from the
  one recorded at the start of the run, the state is `needs_human_review`.

## Out of scope

- A channel character limit (needs a profile field, E4).
- A refusal reason in the status text of a rejected task (after stage 5b).
- A snapshot of the snippet texts for a paused run; v1 only reports a change.
- Proof that a client copies the text unchanged; nr-llm checks its artifact.

## Verification

| Requirement | Evidence |
| --- | --- |
| X1-X9 | `ExternalTextCheckTest`, functional `ExternalTextCheckWiringTest` (rule-state digest included) |
| X7 shared | `RuleSetJudgeGuardrailTest` (behaviour after the extraction), `ExternalTextCheckTest::aNamedWriterThatDoesNotExistCountsAsTheMostTrustedZone` (a named provider that is gone) |
| X10-X21 | functional `A2aEditorialEndCheckTest`, which ships with the end-of-run check (ADR-302) and the result contract (ADR-301): no judge call in a transaction (X11), the check record and the report at each privacy level (X12, X16), the digest binding of the delivered text (X19) |

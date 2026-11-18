<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A run can tell its inputs apart without keeping them

Governing decision: ADR-323. Builds on spec 061 (skill versions, ADR-321)
and spec 062 (test evidence, ADR-322): with this, a run tells a changed
skill, a changed model and a changed input apart.

## Requirements

- **CD1 Opt-in.** The extension setting `privacy.inputDigests` is off by
  default. Off, a stored step is what it was before, except that a step
  stored at `redacted` carries the `contentMasked` mark (CD6).
- **CD2 Digests of the dropped parts.** On, a step filtered at the
  `metadata` level keeps a keyed digest of these parts it drops: each message
  sent (with its role, in order), the answer, a tool's arguments, a tool's
  result, and the request a model call answered. No text is kept.
- **CD3 Keyed.** A digest is the HMAC-SHA-256 of the ADR-298 canonical form,
  keyed with the installation's encryption key and the purpose
  `nr_llm.run.content`. The key is never stored with the digests. Without an
  encryption key there is no digest, never an unkeyed one.
- **CD4 Same input, same digest.** Provider-generated ids (tool-call ids, a
  tool result's `tool_call_id`) and provider-owned items are left out of a
  message's digest and of the request identity (ids there become ordinals);
  tool arguments are digested decoded; `_`-prefixed directives that are not
  sent are not part of the request.
- **CD5 Request identity.** `CompletionRequestIdentity::fingerprint()` covers
  tool calls and provider items; every identity that existed keeps its bytes.
  The tool-call terminal attaches the identity of the request it sent, tool
  schemas included; a run never stores it as it is, at any level, only as
  the keyed `requestDigest`.
- **CD6 Levels.** Only `metadata` keeps digests, and never of text masked at
  `redacted` before. A step filtered again at a stricter level loses them.
- **CD7 Retention.** The digests are part of the run's events and are purged
  with them.
- **CD8 Readable.** The run timeline shows each digest by its first twelve
  hex digits, messages as `role@digest`.

## Out of scope

- Digests at `redacted` (they would confirm a masked credential) or `full`
  (the text is stored).
- Comparing digests across installations.

## Limits

- A reader of the run can confirm a guessed short or predictable input.
- Requests that differ only in provider items look the same.
- A step first stored at `full` and held to `metadata` later (a provider's
  ceiling, a tightened run) has no request digest: `full` never stores the
  request identity it would be computed from. Thinking, requested tool
  calls, tool artifacts and raw provider bodies get no digest.
- A tool result with a per-call random value (`fetch_external_url`'s fence
  nonce) differs on every run.

## Verification

| Requirement | Evidence |
| --- | --- |
| CD1 | `RunStepPrivacyFilterDigestsTest::withoutTheSettingAStepKeepsWhatItKeptBefore`, `noOtherLevelKeepsADigest` |
| CD2 | `RunStepPrivacyFilterDigestsTest::atTheMetadataLevelEachDroppedPartKeepsAKeyedDigest`, functional `RunContentDigestsPersistenceTest` |
| CD3 | `RunStepPrivacyFilterDigestsTest::withoutAnEncryptionKeyThereIsNoDigest`, `InvocationDigestsTest` |
| CD4 | `RunStepPrivacyFilterDigestsTest::aChangedInputChangesTheDigestOfThatPartOnly`, `CompletionRequestIdentityTest::toolCallsAndProviderItemsHaveAnIdentity`, `LlmServiceManagerCompletionCostTest::aToolRoundNamesTheRequestItSent` |
| CD5 | `CompletionRequestIdentityTest::identitiesThatExistedKeepTheirBytes`, `toolCallsAndProviderItemsHaveAnIdentity`, `LlmServiceManagerCompletionCostTest::aToolRoundNamesTheRequestItSent`, `RunTraceTest::theAnswerStepNamesTheRequestItAnswered` |
| CD6 | `RunStepPrivacyFilterDigestsTest::noOtherLevelKeepsADigest`, `aStepFilteredAgainKeepsItsDigestsOnlyWhereTheyAreAllowed`, `aStepRedactedBeforeGetsNoDigest` |
| CD7 | functional `RunContentDigestsPersistenceTest::theDigestsAreStoredWithoutTheTextAndPurgedWithTheRun` |
| CD8 | `RunTimelineFactoryTest::aStepShowsTheContentDigestsItKept` |

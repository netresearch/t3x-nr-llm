<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# A run can tell its inputs apart without keeping them

Governing decision: ADR-323. Builds on spec 061 (skill versions, ADR-321)
and spec 062 (test evidence, ADR-322): with this, a run tells a changed
skill, a changed model and a changed input apart.

## Requirements

- **CD1 Opt-in.** The extension setting `privacy.inputDigests` is off by
  default. Off, a stored step is exactly what it was before.
- **CD2 Digests of the dropped parts.** On, a step filtered at the
  `metadata` level keeps a keyed digest of each part it drops: each message
  sent (with its role, in order), the answer, a tool's arguments, a tool's
  result, and the request a model call answered. No text is kept.
- **CD3 Keyed.** A digest is the HMAC-SHA-256 of the ADR-298 canonical form,
  keyed with the installation's encryption key and the purpose
  `nr_llm.run.content`. The key is never stored with the digests. Without an
  encryption key there is no digest, never an unkeyed one.
- **CD4 Same input, same digest.** Provider-generated ids (tool-call ids, a
  tool result's `tool_call_id`) and provider-owned items are left out of a
  message's digest; tool arguments are digested decoded.
- **CD5 Request identity.** `CompletionRequestIdentity::fingerprint()` covers
  tool calls and provider items; every identity that existed keeps its bytes.
  The tool-call terminal attaches the identity of the request it sent, tool
  schemas included; a run stores it only as the keyed `requestDigest`.
- **CD6 Levels.** Only `metadata` keeps digests. A step filtered again at a
  stricter level, or with the setting off, loses them.
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
- A provider that changes the encoding of its own items changes the request
  digest though the input did not change.

## Verification

| Requirement | Evidence |
| --- | --- |
| CD1 | `RunStepPrivacyFilterDigestsTest::withoutTheSettingAStepKeepsWhatItKeptBefore` |
| CD2 | `RunStepPrivacyFilterDigestsTest::atTheMetadataLevelEachDroppedPartKeepsAKeyedDigest`, functional `RunContentDigestsPersistenceTest` |
| CD3 | `RunStepPrivacyFilterDigestsTest::withoutAnEncryptionKeyThereIsNoDigest`, `InvocationDigestsTest` |
| CD4 | `RunStepPrivacyFilterDigestsTest::aChangedInputChangesTheDigestOfThatPartOnly` |
| CD5 | `CompletionRequestIdentityTest::identitiesThatExistedKeepTheirBytes`, `toolCallsAndProviderItemsHaveAnIdentity`, `LlmServiceManagerCompletionCostTest::aToolRoundNamesTheRequestItSent`, `RunTraceTest::theAnswerStepNamesTheRequestItAnswered` |
| CD6 | `RunStepPrivacyFilterDigestsTest::noOtherLevelKeepsADigest`, `aStepFilteredAgainKeepsItsDigestsOnlyWhereTheyAreAllowed` |
| CD7 | functional `RunContentDigestsPersistenceTest::theDigestsAreStoredWithoutTheTextAndPurgedWithTheRun` |
| CD8 | `RunTimelineFactoryTest::aStepShowsTheContentDigestsItKept` |

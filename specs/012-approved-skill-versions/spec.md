<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Approved skill versions instruct — the skill stream of ADR-214

ADR-214 is the decision. This specification states what each implementing change of the skill stream must do, what it must not do, and which suite proves it. The stream lands in four changes, in this order: (a) version digest, approval and framing; (b) the backend as a skill source; (c) load modes, catalogue, load tool, process skip and the pin data model; (d) budget admission and snippets in a caller's system message. Item 5 (the run-start allow-list) is not part of this stream.

## (a) Version digest, approval and framing — items 1 and 2

### What it must do

1. **One digest names one version.** `SkillVersionDigest` hashes name, description, body, support status, the allow-list (null, or the sorted, de-duplicated string names the resolver reads) and the process marker, length-prefixed, under format version 1. The value is stored as `1:<hex>` in `tx_nrllm_skill.version_digest`; `body_checksum` keeps its body-only meaning.
2. **The sync, the integrity check, the approval form and the wizard all use it.** The sync's change test compares digests; for a row without one it first computes the digest of the stored fields. Compose recomputes the digest from the stored fields and skips the skill on a mismatch; a row without a digest keeps the body-checksum check.
3. **The wizard does not bless earlier edits.** `nrllmAdr214SkillVersionDigest` writes the digest only when the body matches `body_checksum` and name, description, support status and allowed tools match what the parser derives from `raw_frontmatter`; otherwise it disables the row, audits `version_digest_unverified` and leaves it without a digest.
4. **An approved version on a trusted source instructs.** It is composed into the system message, behind the configuration's prompt and snippet block, or appended to a caller's own system message, without the guard preamble and the fence. Everything else stays fenced in the first user message.
5. **Instruct needs both conditions, checked at every composition:** an unrevoked approval for (skill uid, source uid, digest), and the source record's trust level at or above `skills.instructionTrustLevel` (default `verified`, also when the extension is not configured at all; unrecognised or unreadable → `first_party`; below the minimum → the minimum).
6. **The approval binds to what was shown.** The form posts the digest it rendered; the server refuses when it is not the current verified digest, and refuses a record that fails its integrity check, a legacy row and an orphaned skill. The approval row stores the snapshot.
7. **Revocation is per digest and wins.** It flags every approval of (skill, digest); only a new approval makes the digest instruct again.
8. **Audit.** Approvals, revocations and refused approvals are written with the digest they concern.
9. **Process skills are not composed from attachments or forced skills.** They are skipped with a warning; the invocation that composes them comes later.
10. **A suspended run holds its instructions as pins and re-checks them on resume (item 6, pulled forward from (c)).** Every composed instruction yields a pin (skill uid, source uid, digest), stored in `SuspendedRunState::$skillPins`. Before an approval resume (approved or declined) and before an input resume, `SkillPinCheck` requires for each pin an unrevoked approval of that triple whose snapshot still hashes to the digest, an existing non-orphaned skill record, and an active source at or above the instruction threshold; the first failure throws `SkillInstructionWithdrawnException` and no pending call executes. A continuation that suspends again keeps the pins. A continuation that derives pins from a predecessor run (process wiring, items 6 and 10) calls the same check.
11. **The fields that decide trust are `exclude` fields** (item 3, pulled forward from (b)), so a group with tables_modify cannot write them through the DataHandler: on the skill table source, trust level, body checksum, version digest, enabled, allowed tools, support status, orphaned and raw frontmatter; on the source table type and trust level.
12. **Approve and revoke change state only on a POST that matched their own route.** The core's RouteDispatcher validates the route token against the matched route (no token, or another route's token, is refused before the controller), but Extbase lets a request parameter override the action a route names; the actions therefore refuse a request whose matched route names another action, so the review route's token cannot approve.
14. **What the approver cannot see cannot be approved.** A version whose name, description or body contains an invisible character (the write tools' class, minus newline, carriage return and tab) is refused with `refused_invisible`; the review page lists each one.
15. **A disabled, hidden or deleted skill, a hidden or disabled source, and a damaged stored pin all fail the pin check.**
13. **The resume gate fails closed.** A loop built without the pin check refuses to resume a run that holds pins.

### What it must NOT do

- Change the signed manifest: it keeps hashing bodies only.
- Route snippet text into a caller's system message: that is (d).
- Drop an instruction section from the tail of the byte budget.
- Let a non-administrator approve or revoke.
- Put an instruction in the user turn or a bare system message that would suppress the configuration's prompt.

### Which suite proves what

| Requirement | Suite | Test |
|---|---|---|
| Every read field is part of the version; unread fields and tool order are not | unit | `SkillVersionDigestTest` |
| Edited stored field fails the check; legacy row checks the body | unit | `SkillVersionDigestTest`, `SkillComposerInstructionTest` |
| Instruction vs fence, both directions (approval, digest, revocation, source binding, provenance, missing source, legacy) | unit | `SkillComposerInstructionTest` |
| Threshold default, fail-closed, clamp | unit | `SkillComposerFactoryTest` |
| Placement in caller system message / behind the effective prompt / never in the prompt | unit | `SkillInjectionServiceTest`, `ToolLoopServiceInstructionAssemblyTest`, `SkillConfigInjectionTest`, `ConfigurationCallPlannerSnippetTagsTest` |
| Frontmatter-only change disables; legacy unchanged stays enabled | functional | `SkillVersionDigestSyncTest` |
| Approve, stale refusal, integrity refusal, revoke-then-same-bytes, re-approve, source move, diff escaping, audit | functional | `SkillApprovalServiceTest` |
| Wizard writes digests, refuses each kind of edit, does not overwrite a row a sync digested meanwhile | functional | `SkillVersionDigestUpdateWizardTest` |
| Each pin rule, both directions | unit | `SkillPinCheckTest` |
| Pins stored at suspend, revocation/downgrade stops approved, declined and input resumes before any call, held pins continue, re-suspend keeps pins | unit | `ToolLoopServiceSkillPinTest` |
| Pins round-trip, old rows have none, `withSkillPins()` changes nothing else | unit | `SuspendedRunStateTest` |
| Hidden/disabled/deleted source and orphaned/deleted skill read as absent | functional | `SkillPinLookupTest` |
| The container wires the pin check into the loop | functional | `ToolLoopGateWiringTest` |
| Excluded and not-excluded fields | unit | `SkillFieldExclusionTest` |
| GET of approve/revoke changes nothing, POST through the action's own route does, POST through the review route does not | functional | `SkillApprovalControllerTest` |
| Invisible characters listed and refused; ordinary Markdown passes; class identical to the write tools' | unit, functional | `SkillInvisibleCharactersTest`, `SkillApprovalServiceTest` |
| Approve/revoke without or with a foreign route token are refused by the dispatcher; the route's own token passes | functional | `SkillApprovalRouteTokenTest` |
| No pin check wired: pinned runs refused on both resume paths, unpinned runs resume | unit | `ToolLoopServiceSkillPinTest` |

## (b), (c), (d)

Specified in the change that implements each, in this file.

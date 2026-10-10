<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# Gemini native function-call ID correlation

Status: Accepted for implementation; ADR-226. This separate decision depends on
published Struct implementation PR1105, exact commit
48f9ab2be0311294a7919c71a52a5be6883098e6. Its implementation must depend on the
published decision head. ADR-222 replay and ADR-224 known Struct roots remain
prerequisites; native text extraction and nested JSON-kind restoration are
separate contracts.

## Problem and evidence

The Generate Content API permits a populated FunctionCall.id and requires the
client to return that matching FunctionResponse.id. The current adapter retains
the native call ID in its replay parts but emits the result with only name and
response. Five controlled real HTTP response-to-next-request cases produced
three genuine assertion failures and two passing ID-less controls, 93
assertions and zero errors/warnings on replay implementation a1b7403. Single
calls, parallel distinct names and parallel identical name/argument calls all
lose the result ID. This is serialized HTTP evidence, not a live Gemini
availability claim.

Primary contract checked 2026-10-10:
[FunctionCall](https://ai.google.dev/api/generate-content?hl=en#FunctionCall) and
[FunctionResponse](https://ai.google.dev/api/generate-content?hl=en#FunctionResponse).

Matching a generic call list to native parts by position is insufficient: two
same-name/same-argument calls with different native IDs remain
indistinguishable after the generic list is reordered. Refusing those fresh
calls would reject a supported parallel result. The association must be
captured before it is lost.

## Required behavior and acceptance

1. **Capture without public API changes.** Continue producing the existing
   conversation-unique synthesized ToolCall IDs. At the actual response parser,
   retain an explicit association between each synthesized ID and its populated
   native ID. Extend only the existing Gemini-owned opaque capsule with an
   optional `tool_call_bindings` list of objects containing `tool_call_id` and
   `native_id`, both strings. Store entries only for valid named calls with a
   populated native ID. Preserve the original native parts and other metadata.
   Unit actual-response tests pin the synthesized public IDs, original parts,
   captured association and no binding for ID-less calls. Public ToolCall
   fields, method signatures and database schema remain unchanged.

2. **Native ID policy.** An absent native `id` means the call is ID-less and
   its result omits `id`. A present `id` must be a nonempty string; empty, null
   and nonstring values on an otherwise accepted named call in the tool-
   response parser or its correlated replay are refused with an existing typed
   provider exception, rather than coerced or silently lost. Repeated populated
   native IDs within one owning assistant turn are ambiguous and refused.
   Native IDs may recur in later turns: the public synthesized IDs continue to
   identify the individual results. Existing skip behavior for a functionCall
   without a usable name remains unchanged. Unit parser cases prove all refusal
   and adjacent accepted controls without returning fabricated IDs.

3. **Consume the owning association.** Extend the existing transcript prescan's
   private per-public-ID mapping with the optional native ID; no extra public
   scanner or response field is introduced. Bind only from the same assistant
   turn's recognized Gemini capsule. Require a list of valid entries with no
   duplicate public or native ID, coverage of all populated accepted native
   IDs, and no extra bindings. Each entry must reference an actual generic call
   and native call whose name and decoded argument map agree. Matching uses the
   current associative representation, not a promise of lossless nested JSON.
   Always validate a present binding field structurally. Native-parts-only and
   entirely ID-less turns may carry only a valid empty binding list; nonempty
   or malformed fields are refused with a typed exception before contact. Extra
   association checks apply only to an owned turn with generic tool calls and
   native IDs to correlate. Native-parts-only turns and entirely ID-less turns
   otherwise retain their existing replay behavior. Unassociated native parts
   never fabricate a result ID. Reordering the generic list keeps explicit
   associations intact, including identical parallel call signatures. Unit
   second-request tests assert literal functionResponse IDs, names and result
   values for reordered single/parallel/sequential turns.

4. **Legacy owned state.** An older Gemini capsule without `tool_call_bindings`
   may recover a populated native ID only when matching decoded name/arguments
   uniquely identifies the native and generic call within a correlated turn. Do
   not join by list position. Duplicate equal signatures with populated IDs are
   refused before outbound contact; unique signatures and entirely ID-less
   legacy turns remain compatible, including ID-less owned replay without a
   generic call list. Signed plain-chat capture need not manufacture generic
   ToolCalls. A present binding field always follows criterion 3, including its
   structural validation, and is never treated as absent to evade validation.
   Unit controls distinguish an absent legacy field, a valid empty list for ID-
   less state, malformed fields and ambiguous same-signature state. Genuine
   assertion oracles require no HTTP request on refused replay, with the
   exception assertion outside the catch.

5. **Ownership and ambiguity boundaries.** Foreign or ordinary generic
   assistant turns never supply a Gemini native ID, even if their public call
   ID resembles one. Untagged OpenAI native replay and Gemini-to-Responses
   generic fallback keep their existing behavior; neither the private capsule
   nor its binding field is transmitted as provider payload. Existing
   mixed/duplicate capsule refusal still applies. A public call ID used by an
   owned native association must not also identify another generic call in the
   transcript, including a foreign turn, because a result would be ambiguous.
   Preserve the existing behavior of transcripts with no owned populated native
   association. Unit foreign, mixed-owner and duplicate-ID cases pin typed pre-
   contact refusal or the relevant existing payload without leaking the private
   binding.

6. **Every consuming request and durable resume.** Plain/tool chat and closing
   or streaming requests carrying already captured assistant state echo a
   populated native ID in the corresponding functionResponse. Capturing new
   streamed state remains outside this change. A real Functional ToolLoop
   suspension, encrypted SQL save/reload and approval resume must retain the
   explicit association and native parts; same-name/same-arguments parallel
   calls must return their own native IDs and results after reload, preserving
   approval identity, signatures, known Struct objects and final row
   settlement. Run the meaningful SQL cases on SQLite and MariaDB through the
   canonical runner. No hand-built carrier alone substitutes for actual
   response capture or durable state.

7. **Tests of tests and publication.** Selected faults dropping capture,
   dropping the result ID, swapping identical parallel associations, admitting
   malformed or ambiguous legacy state, losing persisted bindings and leaking
   foreign binding state must fail genuine assertions with zero framework
   errors. Independently verify exact source restoration and positive controls.
   Final complete make gate, independent whole-diff review, truthful
   documentation baseline comparison and exact frozen source hashes are
   required before signed publication. Current-head external CI and the actual
   PR stack govern merge.

## Scope and compatibility

The optional private capsule field amends ADR-222's internal replay shape,
while its native parts, carrier owner and public APIs stay. It is correlation
metadata, not authentication or authorization evidence. Refusal adds a bounded
behavior for malformed or genuinely ambiguous owned native-ID state; older
unambiguous owned state remains consumable. Existing actor, approval, budget,
credential and retention boundaries remain. Never log raw native parts,
signatures, arguments, IDs, result content or provider exception messages for
this validation.

This decision does not repair arbitrary nested object/list loss, make ID-less
providers return native IDs, change other provider APIs, add the Interactions
API or prove live Gemini acceptance. Native text/thought extraction remains
separate.

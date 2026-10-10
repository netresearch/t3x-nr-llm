<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# Gemini known Struct boundaries

Status: Accepted for implementation; ADR-224. This decision is a separate PR on
exact replay implementation PR1099, commit a1b7403e195b875f68c36bf9cbcfdf51c0577d71.
Its implementation must depend on this published decision, not an unrelated
main branch. ADR-222 replay and ADR-223 text extraction remain separate contracts.

## Problem and evidence

Google's Generate Content API defines FunctionCall.args and
FunctionResponse.response as JSON objects (Struct). Actual controlled HTTP
response-to-request probes show that an empty or numeric-key argument object
becomes a JSON list on the second request. Actual result-message probes show
that empty and numeric-key response objects likewise become lists. Object-mode
JSON decoding distinguishes the real wire types; associative decoding alone
cannot prove this contract. The clean six-case additional probe produced six
assertion failures, zero errors/warnings. This is no live Gemini acceptance claim.

The same probe confirms a separate limitation: arbitrary nested object/list
kinds are lost by inherited associative decoding. Its nested empty-object cases
fail while the adjacent genuine empty list stays a list. Restoring only the two
known Struct roots does not resolve that limitation. It remains an open audit
finding requiring its own preservation design and evidence.

Primary contract: https://ai.google.dev/api/generate-content?hl=en, FunctionCall
and FunctionResponse definitions.

## Required behavior and acceptance

1. **Known argument root.** At final Gemini request serialization,
   FunctionCall.args is a JSON object for a supported argument map, including
   empty maps and numeric-key maps. Public ToolCall.arguments remains its current
   PHP array, and existing names, synthesized IDs and argument values remain.
   Unit HTTP-response-to-next-request cases assert real stdClass wire roots,
   literal member values and the unchanged public parsed argument arrays.

2. **Known result root.** FunctionResponse.response is a JSON object, including
   empty or numeric-key decoded maps. Existing scalar/invalid JSON result
   fallback keeps its result member and original string. Existing array-valued
   decoded results keep their member values at numeric object keys, rather than
   emitting a root list into this Struct slot. Unit actual result-message
   requests pin empty, numeric-key, ordinary, list, scalar and invalid JSON
   controls. This decision makes no promise to restore nested JSON kinds after
   information has already been lost by associative decoding.

3. **Owned replay.** Gemini-owned native parts apply the same known-root encoding
   when replayed. Part order, signatures, other members and nested values retain
   their current representations. The carrier itself remains array-valued and is
   neither rewritten nor extended. Raw capture and persisted state keep their
   existing shape; only final Gemini wire construction restores the known root.
   Unit signed single/parallel replay cases require exact remaining members.
   A real Functional tool-loop suspension, encrypted SQL save/reload and resume
   must prove empty argument/result objects on the resumed request, signatures,
   name mapping, approval identity and final row settlement.

4. **Every consuming Gemini request.** Plain and tool chat conversion and closing
   or streaming requests carrying an existing assistant turn use the same rule.
   Controlled serialized-request tests cover these entry points without adding
   capture of new streamed replay state.

5. **Compatibility and boundaries.** No public signature, ToolCall field,
   database schema, provider carrier ownership or Responses fallback changes.
   Legacy untagged OpenAI items and foreign reconstruction retain their existing
   behavior. Known-root conversion must not indiscriminately cast nested arrays:
   a real nested list remains a list. Unit positive controls and the existing
   public API/replay/refusal suites enforce this. Credentials, actor binding,
   approvals, budgets and retention retain their existing boundaries.

6. **Tests of tests and publication.** Controlled removal of each known-root
   conversion must fail genuine assertions with zero framework errors, and exact
   source restoration must be checked. Publish only after independent full-diff
   review, complete make gate and truthful renderer comparison. Current-head
   external CI and the real PR dependencies remain mandatory before merge.

## Scope limits and open follow-up

This change concerns two API-known JSON-object slots. It does not assert generic
lossless JSON transport, reconstruct unknowable nested object/list distinctions,
change other providers, implement the Interactions API or validate live Gemini
availability. Nested-shape loss remains explicitly open after this repair.

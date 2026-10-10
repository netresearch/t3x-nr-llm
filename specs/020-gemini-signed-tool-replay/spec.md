<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Provider-owned Gemini conversation replay

ADR222. Publish this decision as a separate signed PR; implementation uses its exact commit as its actual base.

## Confirmed defect

Two isolated canonical tests drive the real Gemini adapter with a controlled PSR-18 client, receive single and parallel signed function-call parts, then inspect the actual next serialized request. Both lose the original thoughtSignature (two assertion failures, zero framework errors). They do not measure a live API refusal. Google's [Generate Content signature contract](https://ai.google.dev/gemini-api/docs/generate-content/thought-signatures) requires Gemini 3 function-call signatures to remain on their original parts, including all sequential steps and the first parallel call.

## Required behavior

1. Keep the existing CompletionResponse metadata key nrllm_provider_items and ChatMessage providerItems/toTranscriptArray/fromArray carrier. Public signatures, generic ToolCall fields, normal toArray wire serialization, schema and tool authorization do not change.
2. A selected Gemini generateContent candidate with tool calls carries its complete array-valued native parts, in original order and without rewriting the contained values. Use one explicitly owned carrier item: {type: "nrllm_gemini_generate_content", parts: [...]}. The internal wrapper is never sent to either provider. Signed plain-chat responses also carry their native parts; unsigned plain-chat response metadata remains unchanged. This is replay state, not the optional full raw-response capture.
3. Gemini recognizes only its owned carrier on assistant turns and unwraps its native parts into role=model. It accepts typed messages and persisted transcript arrays via the existing ChatMessage parser. Tool-result names remain tied to the ordinary stored tool-call IDs. Single/parallel/multiple sequential turns and signed text adjacent to a call keep their exact part boundaries, values and signature bytes. Closing chat and stream requests must retain already-carried tool turns even though streaming does not create new replay state.
4. OpenAI Responses recognizes the Gemini ownership tag as foreign. It reconstructs that assistant turn from its ordinary authorized ToolCall/content representation rather than submitting Gemini parts. A mixed text/tool turn retains its visible text as an independently serialized assistant message alongside the function calls; the existing generic builder branch that drops content when calls exist cannot be used unchanged for this foreign reconstruction. Gemini similarly reconstructs historical OpenAI-native items from generic fields. Historical untagged OpenAI Responses items retain their exact existing replay behavior; ordinary Chat Completions adapters continue to omit providerItems from their payload.
5. A recognized Gemini carrier must be the only replay item on that turn. Duplicate Gemini capsules or a Gemini capsule mixed with native OpenAI items are ambiguous and refused by either consuming adapter before HTTP contact with an existing typed provider exception and a cause-independent message. The same applies when its parts are not an ordered list of arrays. It must not silently drop required signatures. An empty native parts list is distinct from no carrier. Malformed provider response parts retain the adapter's existing defensive handling; valid array-valued parts keep their content unmodified.
6. The tool loop and persisted resume path continue to carry opaque replay state. Budget, offered-tool checks, approval digests, actor scope, guardrails and retention policies keep their established behavior. Opaque native parts do not become tool arguments, instructions, debug output or visible thinking. Do not decode, manufacture, move or substitute thought signatures. This repair does not promise continuity across a provider/model that cannot consume the originating provider's state.

## Verification

- Independent literal native-part expectations on actual serialized second/third requests: single, parallel, sequential, text/signature adjacency, empty/no-carrier and signed plain-chat controls. Test both typed and actual JSON transcript roundtrips.
- A real ToolLoopService request sequence proves response metadata is carried automatically; an actual persisted suspended/resumed agent path proves storage rather than a hand-constructed ChatMessage alone. Verify exact calls and unchanged tool-result name mapping.
- Cross-provider tests inspect Gemini-to-OpenAI Responses and OpenAI-to-Gemini payloads. Existing OpenAI native-item replay and ordinary compatible-adapter payloads remain intact; malformed/duplicate/mixed owned carriers refuse before contacting the fake HTTP client. Gemini-to-Responses fixtures must contain both visible text and a tool call and independently assert both payload items.
- Controlled missing/reordered/signature-rewritten/foreign-replay/serialization-strip faults must fail through assertions. Error-only/setup/timeouts are not proof of an oracle. Restore exact source bytes before the final full six canonical suites, strict documentation comparison and independent review.
- No live provider spend is needed for deterministic transport contracts. If a live API check is later performed, record it separately and avoid turning synthetic fixtures into a live-acceptance claim.

## Boundaries

This is the generateContent conversation replay contract. It does not implement Google's Interactions API, new streamed-response replay state, image generation or a model migration. Model discovery/capability assertions and other Gemini text/vision/stream parsing remain separately audited.

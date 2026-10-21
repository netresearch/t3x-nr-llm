<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Gemini candidate text and native thinking

ADR223. Publish this standalone decision as a separate signed PR, then derive its implementation from the exact signed decision commit. This repairs existing synchronous generateContent behavior; it adds no public API or schema.

## Confirmed defect and source

On frozen f10fab7582e2823b5534545c7a5959d380dd6442, actual Gemini HTTP-response parsing produces eight assertion failures across chatCompletion and chatCompletionWithTools: plain chat selects only its first text part, while tool chat merges thought-marked text into the visible answer. Native thought text never reaches CompletionResponse.thinking. Six ordinary/single-text controls pass. The separate empty-argument assertion belongs to the independent Struct decision, not this repair. These are controlled PSR-18 responses and actual adapter calls, not a live API claim.

Google's [Generate Content Part contract](https://ai.google.dev/api/generate-content?hl=en#Part) defines thought as the marker for model thought text. ADR016 already separates provider-native thinking from visible text and retains inline <think> extraction. This decision extends that existing contract to Gemini rather than exposing native thought summaries as normal answers.

## Required behavior

1. Both synchronous chat methods use one internal candidate-text parser. Continue selecting the first candidate. Visit its parts in their received order; only string text fields contribute. A strictly boolean thought:true field classifies that part as native thinking. Missing, false or malformed non-boolean markers remain ordinary text under the existing defensive behavior; unrelated and non-text parts contribute neither string.
2. Concatenate all ordinary text parts without inventing separators or trimming their whitespace. Run the existing extractThinkingBlocks exactly once on this complete visible-text sequence. Its existing tag matching, trim and whitespace rules remain unchanged, including tags that cross visible part boundaries.
3. Concatenate native thought text parts in their own received order without inventing separators. Do not parse <think> tags inside native thought text or rewrite its bytes. An empty concatenated native string contributes no thinking; other native strings, including whitespace, retain their exact contents.
4. If only native or only inline thinking exists, use that contribution. If both exist, CompletionResponse.thinking is native text, one LF, then the existing normalized inline thinking. No additional trimming applies to the combined result. With neither contribution, thinking remains null. CompletionResponse.content contains only the cleaned ordinary-text sequence; a thought-only candidate has empty visible content.
5. Preserve model, provider, finish reason, usage, metadata and tool-call parsing/order/arguments. Do not mutate the received parts or any existing provider-owned replay carrier: opaque signatures and raw native parts remain replay data even when their text is classified for the generic response. Public CompletionResponse and ToolCall signatures remain unchanged. Existing response capture/privacy behavior stays.

## Acceptance and evidence

- Unit tests drive each real Gemini method through controlled HTTP responses. Independently assert all ordered visible strings and native thinking for thought-first/middle/last, multiple visible/native parts, thought-only, empty/native-whitespace and no-thinking controls.
- Unit tests pin combined native plus inline output literally, multiple inline blocks, inline tags split across visible parts and literal tags inside native thought text. Include false/non-boolean markers and non-text/invalid text controls so the defensive contract is explicit.
- Actual tool-response cases pin function name/arguments, model, finish reason, usage and metadata/replay parts as independent expected values. Where a signature carrier exists in the implementation base, inspect its exact unchanged part list. Do not count a hand-built carrier as real response capture.
- Selected source faults that use only the first visible part, merge native text into visible output, drop native thinking, change its order, reverse the combined contribution or mutate raw parts must fail assertions. Framework errors, setup failures and timeouts do not demonstrate an oracle. Restore exact source bytes.
- Run the canonical focused Unit suite, full make gate, strict documentation baseline comparison and fresh independent review on final frozen bytes before publication. The decision-only PR has no runtime claims; implementation is its actual child.

## Execution and boundaries

Source is Classes/Provider/GeminiProvider.php; existing inline behavior remains in AbstractProvider. Tests belong to the canonical Tests/Unit/Provider suite, manual changes to the existing API/developer pages, and the ADR to Documentation/Adr. Apply the repository's AGENTS.md hierarchy and guarded PHP editing rather than duplicating its style rules here.

Run `./Build/Scripts/runTests.sh -s unit -- --filter Gemini` for focused behavior, `make gate` for the six required suites, and the canonical render-guides invocation with lowercase `--input-format=rst` for documentation. The native-text and Struct capabilities are independent decisions. Struct implementation additionally depends on the provider-owned replay repair; no Struct behavior is changed here.

This decision covers synchronous generateContent candidate text. It does not implement streamed native-thought events, vision parsing, Google's Interactions API, a thinking-budget option, new UI, or extraction of opaque thoughtSignature bytes. Native thoughts remain subject to existing response access, storage and privacy policy; no new diagnostic logging or retention path is introduced.

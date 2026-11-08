<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# A term list blocks an output and says why

> Current contract: [RT02](../current-contract.md#rt02-term-list-screening-scope-differs-across-call-types), [RT12](../current-contract.md#rt12-the-contextual-guardrail-extension-point-needs-an-explicit-api-contract), [L01](../current-contract.md#l01-cached-answers-need-an-explicit-policy-freshness-rule). These named amendments supersede conflicting historical increment wording below. [Inventory](../README.md).

An editor maintains forbidden and required terms in a record. A generated text that breaks a list does not reach the editor, and the editor reads which term stopped it (ADR-238).

## Required behavior

1. A term list is a record of `tx_nrllm_term_list`: a name, the configurations it applies to (at least one), an optional site, an optional language, forbidden terms and required terms, one per line, and an optional message.
2. An installation without a term list, and a call on a configuration no list names, behaves as before. A call with no configuration is not checked.
3. A forbidden term in the text of an answer, or in the string arguments of a tool call, blocks the answer. A required term that is missing blocks it too, unless the answer is a tool-call step, shorter than 30 words, JSON, an image description or part of an agent run.
4. Matching follows `TermMatcher`: case ignored, whole words, spaces match any white space, no inflection, a trailing `*` matches further letters, markup and entities do not hide a term. The module and the manual say exactly this.
5. A list bound to a site or language applies when the call names that site or language. When a call names neither, its forbidden terms still apply and its required terms do not.
6. A blocked output is a `GuardrailViolationException` whose message names the terms and the list, in the language of the acting backend user, followed by the list's own message. No part of the generated text is in that message.
7. The block is recorded as a governance event, on the non-streaming and on the streaming path.
8. A stream a list applies to is held back and screened as a whole. A blocked stream delivers no text. Any other stream is delivered as it arrives.
9. When the check cannot reach a verdict the output is blocked with a message that says so. A missing table is the one exception: it cannot hold a list, so the output passes and a warning is logged.
10. The guardrail cannot be deselected per configuration. The list's own scope is the one place that decides.
11. Saving a term list flushes the cached translations, so a new term also holds against them.
12. The nr-llm surfaces that end a task or an editor action show the reason instead of a generic failure.
13. The module `AI > Authoring > Term Lists`, an overview card and the manual exist. All strings exist in English and German.

## Not done

No synonyms, stemming, spell tolerance or language model in the check. nr-llm's own modules state no site or language. A forbidden term is not removed from the text. `thinking` is not screened: it reaches no editor. DeepL output is not screened, because it is not a language-model answer in the pipeline. Images, speech and embeddings are not screened. The list is not sent to the model; ADR-238 names the rule set as the place for that.

## Boundaries

The new table needs the database schema update. Until it ran, the output passes and a warning is logged (requirement 9). The module is admin-only like its siblings. An editor group edits the records on a page through the list module, as ADR-169 Option P describes.

## Tests

- Unit `TermMatcherTest`, requirement 4. Unit `TermListGuardrailTest`, requirements 2, 3, 5, 6 and 9.
- Unit `GuardrailMiddlewareTest`, requirements 3, 6 and 7. Unit `StreamingTermHoldTest`, requirements 7, 8 and 9.
- Functional `TermListGuardrailWiringTest`, requirements 1, 2, 5, 6, 7, 10 and the missing table of requirement 9.
- Functional `TranslationCacheFlushHookTest`, requirement 11. Functional `TermListControllerTest` and the XLF checks, requirement 13.
- Functional `TaskExecutionAndRecordsControllerTest`, `EditorActionControllerTest` and `AgentRunControllerTest`, requirement 12.

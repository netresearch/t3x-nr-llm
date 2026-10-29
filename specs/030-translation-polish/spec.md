<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# A language model revises a DeepL translation on request

A caller that translates through DeepL can ask, in the same call, for a language model to revise the result for fluency, terminology and tone. The option is off by default; a caller that does not set it sees no change (ADR-233).

## Required behavior

1. `TranslationOptions::withPolish(bool $enabled = true, ?string $instruction = null)` asks for the revision; `isPolishEnabled()` and `getPolishInstruction()` read it. It is not a constructor parameter and not part of `toArray()`.
2. `translateWithTranslator()` and `translateBatchWithTranslator()` revise the result when the option is set and the translator that ran is not the LLM translator. After the LLM translator nothing is called and `metadata['polish']` says `applied: false, reason: llm_translator`.
3. The revision is one `LlmServiceManager::chat()` call on the default configuration or the pinned provider and model, with the caller's backend user and planned cost for the budget pre-flight. Its chat row carries tokens and cost with the caller's extension and operation; a `translation_polish` usage row records the revised characters with the caller's extension. Neither counts as a request: the translator's row stays the single request of record.
4. The prompt passes source text and translation, and asks to keep meaning, formality, tags, placeholders and glossary target terms, and to return only the text. The caller's instruction is added.
5. The first translation is returned unchanged, with `metadata['polish']['reason']`, when the call fails or the budget refuses it, the answer is empty or cut off, its tags or placeholders differ as multisets from the first translation's, a glossary target term occurs less often, or no polisher is wired. The translation itself never fails because of the revision.
6. `TranslatorResult::isPolished()` is true only for an applied revision; `translator` stays the first-pass translator.
7. With the translation cache on, a requested revision is part of the key (instruction and the revising model's fingerprint), a result whose revision was not applied is not stored, and keys without a revision do not change.
8. The API surface change is additive: three methods on `TranslationOptions`, one on `TranslatorResult`, and an optional trailing constructor parameter of the service.

## Not done

No configuration of its own for the revision, no revision on `translate()` or `translateForConfiguration()` (they translate with a language model already), no meaning check beyond what the prompt asks, no new translator in the registry, no change to `TranslationResult`.

## Tests

- Unit `TranslationServicePolishTest`: revision applied; nothing called without the option; skipped after the LLM translator; fallback on a dropped tag, an altered attribute, a dropped or altered placeholder, an empty answer, a cut-off answer, a budget refusal, a failed call, a dropped glossary term, a missing polisher; moved tags and placeholders accepted; formality and instruction in the prompt; usage row `translation_polish` with extension and user, neither it nor the chat row a request; the site glossary's terms guarded when the caller passes none; an empty first translation not revised; batch revised per text; cache key differs with and without revision and per instruction; a fallback is not cached. Each guard was mutated once and the suite failed.
- Unit `ApiSurfaceSnapshotTest`: the snapshot holds the four methods and the widened service constructor.
- Functional `TranslationServicePolisherWiringTest`: the container hands the service its polisher; overriding the argument with null fails it.

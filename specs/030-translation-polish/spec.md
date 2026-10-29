<!-- SPDX-License-Identifier: GPL-2.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->

# A language model revises a DeepL translation on request

A caller that translates through DeepL can ask, in the same call, for a language model to revise the result for fluency, terminology and tone. The option is off by default; a caller that does not set it sees no change (ADR-233).

## Required behavior

1. `TranslationOptions::withPolish(bool $enabled = true, ?string $instruction = null)` asks for the revision; `isPolishEnabled()` and `getPolishInstruction()` read it. It is not a constructor parameter and not part of `toArray()`.
2. `translateWithTranslator()` and `translateBatchWithTranslator()` revise the result when the option is set and the translator that ran is not the LLM translator. After the LLM translator nothing is called and `metadata['polish']` says `applied: false, reason: llm_translator`.
3. The revision is one `LlmServiceManager::chat()` call on the default configuration or the pinned provider and model, with the caller's backend user and planned cost for the budget pre-flight. Its chat row carries tokens and cost with the caller's extension and operation; a `translation_polish` usage row records the revised characters with the caller's extension. Neither counts as a request: the translator's row stays the single request of record. The budget pre-flight of the revision does not count a request either (`ChatOptions::withRequestAlreadyCounted()`, `BudgetMiddleware::METADATA_REQUEST_ALREADY_COUNTED`, `BudgetService::checkWithoutNewRequest()`); token and cost limits apply. Calls that only suppress the request count keep their check.
4. The prompt passes source text and translation, and asks to keep meaning, formality, tags, placeholders and glossary target terms, and to return only the text. The caller's instruction is added to the system message; it is caller-controlled and must not carry editor input.
5. The first translation is returned unchanged, with `metadata['polish']['reason']`, when the call fails or the budget refuses it, the answer is empty or cut off, it starts with a code fence or a preamble the first translation does not start with, its tags (with attributes, in document order) or placeholders (`{name}`, printf conversions; as a multiset) differ from the first translation's, anything after the call throws, a glossary target term occurs less often, or no polisher is wired. The translation itself never fails because of the revision.
6. `TranslatorResult::isPolished()` is true only for an applied revision; `translator` stays the first-pass translator.
7. With the translation cache on, a requested revision is part of the key (instruction and the revising model's fingerprint), a result whose revision was not applied is not stored, and keys without a revision do not change.
8. The API surface change is additive: three methods on `TranslationOptions`, one on `TranslatorResult`, two on `ChatOptions`, and an optional trailing constructor parameter of the service.
9. The site glossary and the backend user are resolved once per call and once per batch.

## Not done

No configuration of its own for the revision (a configuration named with `withConfiguration()` selects the translator only), no guard for ICU plural blocks, `{{ name }}` with spaces, `%%`, HTML entities or the extent of a link, no revision on `translate()` or `translateForConfiguration()` (they translate with a language model already), no meaning check beyond what the prompt asks, no new translator in the registry, no change to `TranslationResult`.

## Tests

- Unit `TranslationServicePolishTest`: revision applied; nothing called without the option; skipped after the LLM translator, also in a batch; fallback on a dropped tag, an altered attribute, crossed nesting, swapped link targets, a dropped or altered placeholder, an empty answer, a code fence, an English and a German preamble, a cut-off answer, a budget refusal, a failed call, a dropped glossary term (explicit and site glossary), a missing polisher; an empty or cut-off first translation not revised; moved tags and placeholders accepted; a preamble-like start the first translation shares accepted; a surviving glossary term lets the revision apply; an empty glossary target ignored; formality and instruction in the prompt; usage row `translation_polish` with extension and user, not a request, chat call marked as already counted; batch revised per text; cache key differs with and without revision, per instruction and per revising model; not cached when the revising model cannot be told; a fallback is not cached.
- Unit `BudgetMiddlewareCountedRequestTest` (real middleware and budget service): at N of N requests used after the translation, the revision is allowed; a call not marked is refused; a call that only suppresses the request count is refused as before; an exhausted cost limit still refuses the revision; a configuration's request cap does not count it; another budget service implementation is asked `check()`.
- Unit `ApiSurfaceSnapshotTest`: the snapshot holds the new methods and the widened service constructor.
- Functional `TranslationServicePolisherWiringTest`: the container hands the service its polisher; overriding the argument with null fails it.
- Mutations, each restored from a copy and checked by sha256, each failing the suite: every guard of the polisher (incomplete first translation, empty, truncated, code fence, preamble, tag order, placeholders, glossary count and its `<`, empty glossary target, catch-all fallback), the budget reason, the usage operation and request flag, the already-counted flag on the chat call, the LLM skip, polish, instruction and model in the cache key, the null key not cached, the fallback not stored, the site-glossary fallback, the budget service's request-free check, the middleware's use of it and the metadata factory's key. Whether a guard's mutation was run is listed in the pull request.

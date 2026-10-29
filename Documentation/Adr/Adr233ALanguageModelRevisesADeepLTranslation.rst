.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-233:

=======================================================
ADR-233: A language model revises a DeepL translation
=======================================================

:Status: Accepted
:Date: 2026-10-29
:Authors: Netresearch DTT GmbH

.. _adr-233-context:

Context
=======

DeepL translates faithfully and keeps markup and glossary terms, but its
output can read stiffly, and it knows nothing about the tone a site wants.
A language model writes more fluent text, and it is also more likely to drop
a tag, rewrite a placeholder or ignore a glossary term.

The cowriter (``netresearch/t3-cowriter``) translates through
:php:`TranslationService::translateWithTranslator()` with
:php:`TranslationOptions::withTranslator()` set to the best available
translator, which is DeepL where it is configured. Its users asked for DeepL's
result to be revised by a language model in the same call. No such step
existed: ``translateWithTranslator()`` returned the translator's result as it
was.

.. _adr-233-decision:

Decision
========

:php:`TranslationOptions::withPolish(bool $enabled = true, ?string $instruction = null)`
asks for a revision. It is off by default. :php:`translateWithTranslator()`
and :php:`translateBatchWithTranslator()` honour it when the translator that
ran is not the LLM translator; after the LLM translator a second pass would
only repeat the first, so the result says ``reason: llm_translator`` and
nothing is called. :php:`translate()` and :php:`translateForConfiguration()`
always translate with a language model and ignore the option.

The revision is one chat call through :php:`LlmServiceManager::chat()`, made
by the new ``@internal`` :php:`TranslationPolisher`:

- It runs on the default configuration, or on the provider and model the
  options pin, as :php:`translate()` does. There is no separate configuration
  for the revision; a configuration named with
  :php:`TranslationOptions::withConfiguration()` selects the translator only
  and does not apply to the revision.
- The prompt names both languages and passes the source text and the
  translation. It tells the model to keep the meaning, every tag with its
  attributes, every placeholder (``{name}``, ``%s``, ``%1$s``) and the target
  terms of the glossary, to keep a non-default formality, and to return only
  the text. The caller's instruction is appended as one rule of the system
  message. The glossary is the caller's own, or the site's for the language
  pair (:ref:`ADR-208 <adr-208>`), resolved once per call or batch.
- The instruction is caller-controlled: it is part of the system message, so
  it must come from the calling code or its configuration, never from editor
  input. Editor text belongs in the text to translate.
- The budget pre-flight applies with the caller's backend user and planned
  cost, like every other call of :php:`TranslationService`.
- After DeepL, the translator's request row — counting, for the same backend
  user — is written before the revision runs. The revision is then marked
  with :php:`ChatOptions::withRequestAlreadyCounted()`, which puts
  ``BudgetMiddleware::METADATA_REQUEST_ALREADY_COUNTED`` on the call. The
  middleware asks the new ``@internal``
  :php:`CountedRequestBudgetCheckInterface::checkWithoutNewRequest()` of
  :php:`BudgetService`, which leaves the request out of the backend user's
  per-day and per-month request limits only. Without it, at N−1 of N
  requests used the translation passed and every revision was refused. The
  configuration's per-day request cap still counts the revision: DeepL's row
  carries no configuration, so the configuration's usage does not hold it,
  and leaving the request out there would let revisions pass a shared cap
  without ever being counted against it. Token and cost limits are checked as
  usual. A budget service that does not implement the interface — another
  implementation of the ``@api`` :php:`BudgetServiceInterface` — is asked
  :php:`check()`, which counts the call. Calls that only suppress the request
  count (the language detection inside a translation, the LLM translator's
  chat call) keep their check unchanged, because their request row is written
  after them.
- After any other translator than DeepL — a third-party ``#[AsTranslator]`` —
  nothing guarantees such a row, so the revision's chat call is an ordinary
  request with an ordinary pre-flight. Translation and revision therefore
  never consume zero requests.
- The chat row carries the tokens and the cost with the caller's extension and
  operation. A usage row of service type ``translation_polish`` records the
  caller's extension, the model and the number of characters revised, and
  never counts as a request. After DeepL the chat row does not count either:
  DeepL's row stays the single request of record, as the language detection
  inside a translation does not count (#473), so a polished DeepL translation
  uses one request of an editor's request limits; a DeepL batch, which DeepL
  records as one request, stays one request however many texts are revised.
  Its tokens and cost count against the token and cost limits.

A revision never fails the translation. The first translation is returned
unchanged when:

- the first translation is empty or was cut off (``incomplete_translation``);
- the call fails (``failed``) or the budget refuses it (``budget_refused``);
- the answer is empty (``empty``) or was cut off (``truncated``);
- the answer starts with a code fence (``code_fence``), with a preamble such
  as "Here is…" or "Hier ist…" (whole words), or with a first line that ends
  in a colon and is followed by more text (``preamble``), and the first
  translation does not;
- the answer has more non-empty lines than the first translation, such as a
  trailing "Hinweis: …" note (``added_lines``);
- the answer's tags and HTML comments, compared with their attributes and in
  document order, differ from the first translation's (``markup_changed``) —
  crossed nesting, swapped link targets and a dropped comment are caught.
  Tags are normalised first: the name and attribute names in lower case, a
  trailing ``/`` dropped, whitespace collapsed and attribute values in double
  quotes, so ``<br>`` and ``<br />`` or ``<B>`` and ``<b>`` are the same tag.
  A revision that moves a clause containing a tag past another tag changes
  the order and falls back, although it may be a valid rewording;
- its placeholders, compared as a multiset so word order may change, differ
  (``placeholders_changed``). Guarded are ``{name}`` (also inside
  ``{{name}}``) and printf conversions such as ``%s``, ``%d``, ``%1$s`` and
  ``%.2f``. Not guarded: ICU plural and select blocks, ``{{ name }}`` with
  spaces, ``%%`` and HTML entities;
- a glossary target term occurs less often than in the first translation
  (``glossary_term_changed``). The count is case-sensitive substring counting,
  so a term inside a longer word counts too;
- anything in the revision throws after the call returned (``failed``). A
  budget refusal and a provider exception are logged as warnings, anything
  else as an error, each with the exception;
- no polisher is wired (``unavailable``).

:php:`TranslatorResult::$metadata` gains ``polish`` with ``applied``, and
``reason`` and ``model`` where they apply. :php:`TranslatorResult::isPolished()`
reads it. :php:`translator` stays the translator of the first pass, so
``isFromDeepL()`` is still true. The fallback and its reason are logged.

With the translation cache on (:ref:`ADR-209 <adr-209>`), the revision runs
inside the cached step. A requested revision adds to the key a hash of the
instruction and the fingerprint of the model that revises — the one ADR-209
uses for the LLM translator. When that model cannot be told, the call is not
cached. A result whose revision was not applied is not stored, so a refused
revision is tried again on the next call. The keys of calls without a
revision do not change.

.. _adr-233-consequences:

Consequences
============

- The ``@api`` surface grows by :php:`TranslationOptions::withPolish()`,
  :php:`isPolishEnabled()`, :php:`getPolishInstruction()` and
  :php:`TranslatorResult::isPolished()`. The option is not a constructor
  parameter of :php:`TranslationOptions` and not part of ``toArray()``, which
  is the payload the translator receives.
- :php:`TranslationService`'s constructor gains an optional trailing
  :php:`TranslationPolisher`. :ref:`api-stability` puts the constructors of
  services out of contract; the snapshot records the widening, and positional
  callers are unaffected.
- A revised translation costs a DeepL request and a chat call. Both are
  checked against the budget, and both are recorded.
- :php:`ChatOptions` gains :php:`withRequestAlreadyCounted()` and
  :php:`getRequestAlreadyCounted()` (additive ``@api``), and
  :php:`BudgetMiddleware` a metadata key. Like
  :php:`withSuppressRequestCount()`, the flag is turned into metadata by
  :php:`CallMetadataFactory::requestCount()` and honoured by
  :php:`LlmServiceManager::chat()` only. No existing caller sets it, so no
  other budget check changes.
- The guards check what can be compared without understanding the language.
  A revision that keeps every tag, placeholder and term and still changes the
  meaning is not detected; the prompt is the only protection there. Tags are
  compared in order, not by what they enclose, so a link or emphasis that
  covers more or fewer words than before is not detected either.
- A caller reads ``metadata['polish']['reason']`` to tell an editor why the
  revision did not apply. A budget refusal of the revision is not thrown, so
  a caller that maps budget exceptions to a message does not see it.

.. _adr-233-alternatives:

Alternatives considered
=======================

- **A translator of its own (``deepl+llm``) in the registry.** It would make
  the combination selectable by configuration, but a translator returns one
  result and has no place to say that the second step fell back, and the
  translation cache and glossary handling of :php:`TranslationService` would
  have to be repeated inside it.
- **Failing the translation when the revision fails.** The caller asked for a
  translation first. Returning DeepL's result and saying why keeps the call as
  reliable as it was without the option.
- **A configuration named by the option for the revision.** It would let a
  site give the revision its own persona and model. The default configuration
  and the existing provider and model pins cover the request; a configuration
  can be added later without changing the option's meaning.

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
  for the revision.
- The prompt names both languages and passes the source text and the
  translation. It tells the model to keep the meaning, every tag with its
  attributes, every placeholder (``{name}``, ``%s``, ``%1$s``) and the target
  terms of the glossary, to keep a non-default formality, and to return only
  the text. The caller's instruction is appended as one rule. The glossary is
  the caller's own, or the site's for the language pair (:ref:`ADR-208
  <adr-208>`).
- The budget pre-flight applies with the caller's backend user and planned
  cost, like every other call of :php:`TranslationService`.
- The chat row carries the tokens and the cost with the caller's extension and
  operation. A usage row of service type ``translation_polish`` records the
  caller's extension, the model and the number of characters revised.
  Neither row counts as a request: the translator's own row stays the single
  request of record of the translation, as the language detection inside a
  translation does not count either (#473). A polished translation therefore
  uses one request of an editor's request limits; its tokens and cost count
  against the token and cost limits.

A revision never fails the translation. The first translation is returned
unchanged when:

- the first translation is empty or was cut off (``incomplete_translation``);
- the call fails (``failed``) or the budget refuses it (``budget_refused``);
- the answer is empty (``empty``) or was cut off (``truncated``);
- the answer's tags, compared as a multiset with their attributes, differ
  from the first translation's (``markup_changed``); a moved tag is accepted;
- its placeholders, compared the same way, differ (``placeholders_changed``);
- a glossary target term occurs less often than in the first translation
  (``glossary_term_changed``);
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
- The guards check what can be compared without understanding the language.
  A revision that keeps every tag, placeholder and term and still changes the
  meaning is not detected; the prompt is the only protection there.
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

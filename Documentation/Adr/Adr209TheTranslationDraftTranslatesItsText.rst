.. include:: /Includes.rst.txt

.. _adr-209:

====================================================================
ADR-209: The translation draft translates its text
====================================================================

:Status: Accepted
:Date: 2026-09-25
:Amends: :ref:`ADR-146 <adr-146>` (``create_translation_draft`` copied the
    source text and could not change it)
:Authors: Netresearch DTT GmbH

Context
=======

``create_translation_draft`` (:ref:`ADR-146 <adr-146>`) runs core's
``localize`` command and hides the result. The text of the new record is what
``localize`` copies: the source text, prefixed with "[Translate to …:]". Its
description told the model that it takes no translated text and cannot change
the text afterwards. Asked for a German version of a page, a chat produced a
hidden German record in English.

:php:`TranslationService` already translates through DeepL or the LLM
translator, and since :ref:`ADR-208 <adr-208>` it applies the glossary of the
caller's site. Three things were missing to connect the two: markup handling on
the DeepL path, a way to avoid paying twice for the same text, and the step
itself.

Decision
========

**After** ``localize`` **created and hid the record, the tool machine-translates
its text.**

- **Which fields.** Every column of the record's type that is ``input`` or
  ``text`` and holds text in the source — read from the live TCA with the
  type's ``columnsOverrides`` applied, because whether ``bodytext`` is rich text
  or raw markup depends on the content type. Left out: ``l10n_mode = exclude``
  (core does not copy it), ``l10n_display = defaultAsReadonly`` (the form shows
  the source value), ``readOnly``, and a ``text`` column with a ``renderType``
  (a code editor or a table wizard holds code or structure, not prose).
  Columns the acting user may not edit are not sent to a translator, and the
  preview and the result name them.
- **From the source row.** The text is read from the default-language record,
  not from the copy, so the "[Translate to …:]" prefix never reaches the
  translator.
- **Rich text goes as HTML.** A column with ``enableRichtext`` is sent with the
  new :php:`TranslationOptions::withTagHandling('html')`, which DeepL receives
  as ``tag_handling``. The LLM translator already keeps tags while
  ``preserveFormatting`` is on, which is its default.
- **The site and the pair come from the site configuration.** The site of the
  record's page names the glossary (:ref:`ADR-208 <adr-208>`); the two
  languages are the ISO 639-1 codes of the site's default language and of the
  target language. They are read after ``localize`` succeeded, so core has
  already checked that the site defines the target language, and that check is
  not re-implemented.
- **The translator is an argument.** ``translator`` is ``deepl`` or ``llm``.
  Without it the tool uses ``llm``, which is what
  :php:`TranslationService` chooses when neither a translator nor a
  configuration is named — and a tool call carries no configuration. A
  translator that is not available on the installation is refused before
  anything is created. The approval preview names the translator and the
  fields: *text: MACHINE-TRANSLATED by DeepL (deepl) — header, bodytext*.
- **One write, or none.** All fields are translated first and written in one
  DataHandler pass, as the acting user, and read back. If any translation, the
  write or the read-back fails, nothing is written and the result says *The
  text was NOT machine-translated: <reason>*. The record then holds the copied
  source text, which is what it held before this decision. The result stays a
  success with its write target, because a record was created and the
  provenance event (:ref:`ADR-187 <adr-187>`) must announce it.

**Translations are cached, opt-in, inside** :php:`TranslationService`.

- :php:`TranslationOptions::withCacheTtl()` switches the cache on for
  :php:`TranslationService::translateWithTranslator()`. It is off by default:
  a cached answer skips the translator, its budget pre-flight and its usage row
  — the trade :php:`CacheMiddleware` makes — and a caller has to choose that.
  The test page, for one, must reach DeepL to prove that a key works. The tool
  opts in for a day.
- **One cache for both translators.** The key is built inside the glossary step,
  where the translator options are final: the translator, both languages, the
  text, and the options, which carry the glossary terms (LLM) or the id of the
  DeepL glossary holding them, the tag handling, formality, domain, context,
  provider and model. An edited glossary is therefore a new key on both paths:
  new terms on the LLM path, and on the DeepL path a new glossary id, because
  :php:`DeepLGlossarySync` creates a new DeepL glossary for changed terms. The
  attribution fields (``beUserUid``, ``plannedCost``) are left out: who asks
  does not change the answer.
- Entries live in the existing ``nrllm_responses`` cache, tagged
  ``nrllm_translation``. Only a successful result is stored. A hit carries
  ``metadata['cached'] = true`` and ``charactersUsed = 0``.
- :php:`CacheMiddleware` is **not** used for the LLM path. It stores only an
  array returned by the terminal of the provider pipeline, and the chat terminal
  returns a :php:`CompletionResponse`; a cache key set on a chat call would
  never store anything. Making chat cacheable would change every chat call and
  would still need a cache key carried through :php:`LlmTranslator` and
  :php:`ChatOptions`. One cache one level up covers both translators through
  one code path.
- ``translateBatchWithTranslator()`` and the LLM-only ``translate()`` are not
  cached; nothing in this change calls them.

**A transparency notice for machine-translated records is not decided here.**
The ticket asked for a notice field for EU AI Act Article 50. Neither form of
it fits the decisions this extension has recorded:

- A field on ``pages`` and ``tt_content``: this extension does not extend
  foreign tables (``Configuration/AGENTS.md``; ``ext_tables.sql`` defines only
  ``tx_nrllm_*`` tables).
- A listener on :php:`AfterAiRecordWrittenEvent`: :ref:`ADR-187 <adr-187>`
  decided that no listener ships and none is planned, because what a site says
  about AI-written content is the site's policy.

The tool marks its records the way ADR-187 chose: the result carries the write
target with :php:`WriteKind::CREATED`, so the event is dispatched for every
created translation, and the result and the preview say that the text was
machine-translated and by which translator. An installation that must label
such records listens to the event. Shipping a notice here would supersede
ADR-187 and needs its own decision.

Consequences
============

- The draft a chat produces is in the target language. A human still reviews
  and unhides it; nothing about visibility changes.
- The tool sends the source text to the chosen translator. For ``deepl`` that
  is an external service, named on the approval card.
- :php:`TranslationOptions` gains ``tagHandling`` and ``cacheTtl`` with their
  withers and getters, appended to the constructor; ``toArray()`` emits
  ``tag_handling``. :php:`TranslationService`'s constructor gains an optional
  trailing :php:`CacheManagerInterface`. Both are additive.
- A cache hit is invisible to budgets and usage analytics. That is the reason
  the cache is opt-in.
- Batch translation stays uncached until a caller needs it.

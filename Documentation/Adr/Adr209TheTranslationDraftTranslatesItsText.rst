.. include:: /Includes.rst.txt

.. _adr-209:

====================================================================
ADR-209: The translation draft translates its text
====================================================================

:Status: Accepted
:Date: 2026-09-25
:Amends: :ref:`ADR-146 <adr-146>` (``create_translation_draft`` copied the
    source text and could not change it, and left the target-language check
    to core)
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

- **Which fields.** Every ``input`` or ``text`` column of the record's
  **type** — the columns of the type's form, read from the live TCA with the
  type's ``columnsOverrides`` applied, by the same reader the content-element
  writers use — that holds text in the source. A column outside the type's
  form is not sent even when it holds text: it is left over from another type,
  and no editor sees it. Left out as well: ``l10n_mode = exclude`` (core does
  not copy it), ``l10n_display = defaultAsReadonly`` (the form shows the
  source value), ``readOnly``, a ``text`` column with a ``renderType`` (a code
  editor or a table wizard holds code or structure, not prose), and a
  person's name — ``pages.author``: a translator would translate it. Columns
  the acting user may not edit are not sent to a translator, and the preview
  and the result name them.
- **From the source row.** The text is read from the default-language record,
  not from the copy, so the "[Translate to …:]" prefix never reaches the
  translator.
- **Rich text goes as HTML.** A column with ``enableRichtext`` is sent with the
  new :php:`TranslationOptions::withTagHandling('html')`, which DeepL receives
  as ``tag_handling``. The LLM translator already keeps tags while
  ``preserveFormatting`` is on, which is its default.
- **The pair is resolved before anything is created.** The site of the
  record's page names the glossary (:ref:`ADR-208 <adr-208>`); the two
  languages are the ISO 639-1 codes of the site's default language and of the
  target language. They are resolved in the plan, before ``localize`` runs: a
  page in no site, a language the site does not define, or a locale without a
  two-letter code is refused — each with its own reason — and no record is
  created that could not be translated. This takes over the target-language
  check ADR-146 left to core.
- **The translator is an argument.** ``translator`` is ``deepl`` or ``llm``.
  Without it the tool uses ``llm``, which is what
  :php:`TranslationService` chooses when neither a translator nor a
  configuration is named — and a tool call carries no configuration. A
  translator that is not available on the installation is refused before
  anything is created. The approval preview names the translator, the pair,
  the fields, and whether the site keeps a glossary for the pair: *text:
  MACHINE-TRANSLATED by DeepL (deepl) from "en" to "de" — header, bodytext;
  the site glossary for the pair applies (3 term(s))*, or *… the site keeps
  no glossary for the pair*.
- **Translate everything, then write once, then report what is stored.** All
  fields are translated before anything is written. A translator that fails,
  returns blank text, or returns an answer cut off at its output limit stops
  the step with nothing written, and the result says *The text was NOT
  machine-translated: <reason>. The translation holds the source text as the
  localize command copied it.* The translations are then written in one
  DataHandler pass, as the acting user. A value longer than an ``input``
  column's TCA ``max`` is cut to it first, as the DataHandler would cut it,
  and the result names the field; an ``eval`` of ``trim`` is applied the same
  way. The fields are read back: a plain value must be stored as written; a
  rich-text value, or one with an ``eval`` beyond ``trim``, must differ from
  the copy core made. If the write fails — an error in the DataHandler's log,
  or an exception from a hook, which the tool catches — or a field did not
  take, the result names the fields that hold the translation and those that
  still hold the copied source text. When every field took and the write
  still reported a failure — a hook that threw after the row was stored — the
  success sentence names that failure too. The result stays a success with its
  write target in every case, because a record was created and the
  provenance event (:ref:`ADR-187 <adr-187>`) must announce it.
- **An answer cut off at its output limit is no translation.** The LLM
  translator's output budget, when the caller sets none and no provider is
  pinned, grows with the text — one token per UTF-8 byte, at least the former
  2000, at most 16000 — and its result carries ``finish_reason`` and
  ``truncated`` in the metadata, so the tool and the cache can refuse a
  cut-off answer. With a pinned provider the call runs without a model
  record, so no output limit is known, and the former fixed 2000 stays.
- **No budget above what the model can produce.** A provider refuses a
  ``max_tokens`` above the model's output limit with an error rather than
  honouring it. :php:`ConfigurationCallPlanner::callOptions()` therefore caps
  whatever ``max_tokens`` wins — an explicit per-call value or the
  configuration's stored one — at the model's ``max_output_tokens`` when that
  is known (> 0). This holds for every configuration-driven call, not only
  translations.

**Translations are cached, opt-in, inside** :php:`TranslationService`.

- :php:`TranslationOptions::withCacheTtl()` switches the cache on for
  :php:`TranslationService::translateWithTranslator()`. It is off by default:
  a cached answer skips the translator, its budget pre-flight and its usage row
  — the trade :php:`CacheMiddleware` makes — and a caller has to choose that.
  The test page, for one, must reach DeepL to prove that a key works. The tool
  opts in for a day.
- **One cache for both translators, keyed by what decides the answer.** The
  key is built inside the glossary step, where the translator options are
  final: the translator, both languages, the text, and the options, which
  carry the glossary terms (LLM) or the id of the DeepL glossary holding them,
  the tag handling, formality, domain, context, provider and model. An edited
  glossary is therefore a new key on both paths: new terms on the LLM path,
  and on the DeepL path a new glossary id, because :php:`DeepLGlossarySync`
  creates a new DeepL glossary for changed terms. For the LLM translator the
  key also carries what the options do not name:

  - without a pinned provider — with or without a pinned model — the default
    configuration the chat call resolves, through the same
    :php:`ConfigurationResolver`: its uid, identifier, model and last change
    (``tstamp``), and the identifier and body of every skill it composes into
    the prompt. A pinned model does not free the call from that
    configuration; its skills, fallback chain and options still apply.
    ``tstamp`` reaches the entity only because the configuration's TCA now
    declares it as a ``passthrough`` column: the DataMapper hydrates TCA
    columns only, and without it every stored configuration read 0;
  - with a pinned provider and no pinned model, the provider's default
    model.

  When that cannot be told — no resolver, no usable default, a provider that
  cannot be asked — the call is not cached. The attribution fields
  (``beUserUid``, ``plannedCost``) are left out: who asks does not change the
  answer.
- Entries live in the existing ``nrllm_responses`` cache, tagged
  ``nrllm_translation`` (:php:`TranslationService::CACHE_TAG`). Only a
  successful result is stored: not a failure, not a blank answer, not a
  truncated one. A hit carries ``metadata['cached'] = true`` and
  ``charactersUsed = 0``.
- Saving or deleting a record that decides a translation flushes the tag,
  through the DataHandler hook :php:`TranslationCacheFlushHook`: a glossary, a
  configuration, a model, a provider, a skill or a prompt snippet. The key
  already makes an old answer unreachable after most of these edits; the
  flush covers what the key cannot see — a provider's endpoint, a model's
  settings, a snippet — removes what can no longer be hit, and gives an
  editor a way to get a fresh translation of an unchanged text. A write that
  bypasses the DataHandler flushes nothing; the key still covers the default
  configuration's last change and its skills' bodies.
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
  ``tag_handling``. :php:`TranslationService`'s constructor gains two optional
  trailing parameters, :php:`CacheManagerInterface` and
  :php:`ConfigurationResolver`, and the class the public constant
  ``CACHE_TAG``. All additive.
- :php:`LlmTranslator` asks for a larger output budget for long texts than the
  former fixed 2000 tokens, when the caller sets none and no provider is
  pinned.
- Every configuration-driven call caps ``max_tokens`` at the model's known
  output limit. A caller that asked for more got an error from the provider
  before; it now gets at most the model's limit.
- ``tx_nrllm_configuration`` declares ``tstamp`` as a ``passthrough`` column,
  so :php:`LlmConfiguration::getTstamp()` returns the stored value. The other
  entities with a ``getTstamp()`` — :php:`Provider`, :php:`Model`,
  :php:`Task`, :php:`UserBudget` — have no such column and still read 0;
  nothing reads their ``getTstamp()`` today.
- A cache hit is invisible to budgets and usage analytics. That is the reason
  the cache is opt-in.
- Batch translation stays uncached until a caller needs it.

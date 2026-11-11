.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-289:

=================================================
ADR-289: autotranslate translates through nr-llm
=================================================

:Status: Accepted
:Date: 2026-11-11
:Authors: Netresearch DTT GmbH

.. _adr-289-context:

Context
=======

A site that wants its page trees translated in the background, only where a
translation is missing, again when the source changes, switched per table and
field, with news and file references along, needs a translation workflow. The
third-party extension ``thieleundklose/autotranslate`` is that workflow: a
queue with a backend module and a console command, a save hook, and per-table
settings in the site configuration. nr-llm has the parts it lacks: a
translation service that reaches DeepL or a language model with the site's
glossary, cost tracking and budgets. A separate extension that rebuilds the
workflow on nr-llm was considered and not chosen.

autotranslate builds a ``\DeepL\Translator`` in one static helper and offers no
way to replace it. An installation that wanted nr-llm instead patched that
helper to resolve a class named in autotranslate's configuration from the
container. Its only event, :php:`AfterTranslateEvent`, fires after the
translation, before autotranslate stores the translated columns with plain
SQL.

The second gap is the notice. nr-llm marks the records its own runs write
(:ref:`ADR-239 <adr-239>`) and labels them in b13/ai-label, but autotranslate
writes past both.

.. _adr-289-decision:

Decision
========

**autotranslate keeps the workflow; nr-llm supplies the translator.** nr-llm
ships :php:`AutotranslateTranslator`, a ``\DeepL\Translator`` subclass, as a
public service. autotranslate uses it when its setting ``translatorClass`` names
the class. That setting is offered upstream as a configuration option
(`autotranslate pull request 175
<https://github.com/thieleundklose/tk-typo3-autotranslate/pull/175>`__), the
mechanism of the patch made official, so that nr-llm depends on no unreleased
autotranslate code: it works with the patch and with a release that carries
the option alike.

**The translator overrides what autotranslate calls.** ``translateText()`` sends
the texts of one call to
:php:`TranslationServiceInterface::translateBatchWithTranslator()` in one batch,
a blank text excepted. DeepL's ``tag_handling`` becomes nr-llm's tag handling,
so rich text keeps its markup. The nr-llm translator is the setting
``autotranslate.translator``; empty leaves the choice to the service, which
uses the language model. ``getUsage()`` reports no quota, because nr-llm keeps
its own budgets. ``listGlossaries()`` finds none, because a DeepL glossary id
means nothing to the language model. The language lists are DeepL's codes,
which autotranslate stores in the site configuration. The parent class is built
with a placeholder key and a server under the reserved ``.invalid`` domain, so a
method the class does not override fails without sending a text to DeepL. A
translator that answers fewer texts than it was given fails the call: a missing
answer would move every later text into the wrong field.

**On the command line the key is read as the worker.** autotranslate's batch
command is a console worker. It reads the provider key as
``worker.technicalBeUserUid``, the identity :ref:`ADR-234 <adr-234>` gives
nr-llm's own queue worker; a web request keeps its user. The integration reads
the setting itself and does not use the agent module's scope class, because a
translation component must not depend on the agent module
(:ref:`ADR-090 <adr-090>`).

**A machine-translated record is marked in the write that stores it.** Two
listeners on :php:`AfterTranslateEvent` add columns to the translated ones: the
marker ``tx_nrllm_ai_written`` on pages and content elements, and, when AI Label
is loaded and enables the table, its ``tx_ailabel_metadata``. The label follows
the rule of nr-llm's own translations: AI-modified, an AI-generated origin
kept, the human review cleared. Both apply only while ``translatorClass`` names
nr-llm's translator, and only when a text column (``input``, ``text``,
``flex``) holds text the translation changed from the source; the fields
autotranslate copies unchanged do not count. A failed translation dispatches no
event and stores neither.

**Registered only for an active autotranslate.** A compiler pass registers the
integration when autotranslate's batch command is a service, and the AI Label
listener when AI Label's API is one, as for :php:`AiLabelCompilerPass`.

.. _adr-289-consequences:

Consequences
============

- Background runs, retranslation on change, per-field settings, news and file
  references stay autotranslate's, with its limits: a batch translates every
  configured field again, and a change of the source overwrites a hand
  correction in the changed field.
- Until autotranslate releases the ``translatorClass`` option, an installation
  carries it as a patch. nr-llm does not change when the release arrives.
- The label is written with plain SQL in autotranslate's write, not through AI
  Label's API. A write through the API would be a second DataHandler run with
  its own history entry and save hooks, made before the text it labels is
  stored. It therefore leaves no history entry, as autotranslate's text write
  leaves none.
- A translation routed through nr-llm counts against the budget of the backend
  user who started it, or of the worker identity on the command line.
- nr-llm's site glossary (:ref:`ADR-208 <adr-208>`) does not apply:
  ``translateText()`` carries no page, so the translator cannot tell the site.
- A translation that autotranslate makes with DeepL itself is not marked.

.. _adr-289-alternatives:

Alternatives considered
=======================

**A new extension on nr-llm.** It would own the workflow end to end, at the
price of rebuilding a queue, a module, a context menu, a save hook and per-table
settings that autotranslate maintains already.

**A PSR-14 event upstream that hands over a translator.** It would avoid a
class name in the configuration. nr-llm would then depend on an event class
that no autotranslate release contains yet, and could not be tested or shipped
before that release.

**Marking through nr-llm's provenance event.** :php:`AfterAiRecordWrittenEvent`
names an agent run and is dispatched after the write. An autotranslate
translation has no run, and :php:`AfterTranslateEvent` comes before the write.

.. _adr-289-revisit:

Revisit when
============

- autotranslate passes the page or site to its translator, so the site glossary
  can apply.
- autotranslate offers an event or an interface for the translator instead of a
  class name.

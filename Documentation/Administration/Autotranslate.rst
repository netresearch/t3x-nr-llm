.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

==============================
Translating with autotranslate
==============================

.. _administration-autotranslate:

Page trees and records in the background
========================================

The extension
`thieleundklose/autotranslate <https://extensions.typo3.org/extension/autotranslate>`__
translates page trees and records in the background, again when the source
changes, switched per table and field in the site configuration, with news and
file references along. It translates with DeepL. nr-llm can do the translating
instead, through DeepL or the language model, with nr-llm's usage tracking and
budgets (:ref:`ADR-289 <adr-289>`). autotranslate keeps everything else: its
queue, its backend module, its context menu, its console command and its
settings.

.. _administration-autotranslate-setup:

Setup
=====

Name nr-llm's translator in autotranslate's setting ``translatorClass``, for
example in :file:`config/system/additional.php`:

.. code-block:: php
   :caption: Route autotranslate through nr-llm

   $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['autotranslate']['translatorClass']
       = \Netresearch\NrLlm\Integration\Autotranslate\AutotranslateTranslator::class;

autotranslate 3.6 and earlier do not read this setting. Until a release
carries it, the installation needs the change of
`autotranslate pull request 175
<https://github.com/thieleundklose/tk-typo3-autotranslate/pull/175>`__ as a
patch.

Which nr-llm translator does the work is the setting
:confval:`autotranslate.translator <ext-nr-llm-autotranslate-translator>` under
:guilabel:`Settings > Extension Configuration > nr_llm`: ``deepl``, ``llm`` or
the identifier of another registered translator. Empty uses the language model. The DeepL translator of
nr-llm reads its key from nr-vault, not from autotranslate's configuration.

autotranslate's console command ``autotranslate:batch:run`` reads the provider
key on the command line. Set :confval:`worker.technicalBeUserUid
<ext-nr-llm-worker-technical-be-user-uid>` to a backend user with read access to
the key, as for nr-llm's own worker. Without it, nr-vault refuses the key
unless it allows command-line access.

.. _administration-autotranslate-behaviour:

What nr-llm changes
===================

- Rich text is translated as HTML, so the markup stays.
- autotranslate shows no DeepL quota; nr-llm counts the usage, against the
  budget of the backend user who started the translation, or of the worker
  user on the command line.
- DeepL glossaries managed by autotranslate do not apply, and neither does
  nr-llm's site glossary: autotranslate does not tell the translator which page
  a text belongs to.
- A page or content element that received machine-translated text gets
  nr-llm's AI-written marker (:ref:`administration-ai-written-records`), and,
  with b13/ai-label installed, AI Label's "AI-modified" origin with its review
  cleared, so AI Label's frontend notice shows. Both are stored with the
  translated text. A field autotranslate copies unchanged does not count.

autotranslate decides what is translated. A batch translates every configured
field again, also in an existing translation, and a change of the source
overwrites a hand correction in the changed field.

.. include:: /Includes.rst.txt

.. _administration-glossaries:

================================
Managing translation glossaries
================================

A translation glossary fixes how one site translates particular terms from one
language into another — "Warenkorb" as "shopping cart", never as "basket".
The glossary applies to DeepL and to the LLM translator alike
(:ref:`ADR-208 <adr-208>`).

.. _administration-glossaries-add:

Adding a glossary
=================

1. Navigate to :guilabel:`AI > Authoring > Glossaries`.
2. Click :guilabel:`New Glossary`.
3. Fill in the fields:

   :guilabel:`Name`
      A name you recognise in the list, for example ``Shop terms DE → EN``.

   :guilabel:`Site`
      The site whose translations use the glossary. The list offers every
      configured site.

   :guilabel:`Source language` and :guilabel:`Target language`
      Two-letter ISO 639-1 codes, for example ``de`` and ``en``. A translation
      requested for a regional variant (``de-DE``, ``en-GB``) uses the glossary
      of its base language.

   :guilabel:`Term pairs`
      One pair per line, either ``source = target`` or source and target
      separated by a tab — pasting two columns from a spreadsheet produces the
      second form. Empty lines and lines starting with ``#`` are ignored. When a
      source term appears twice, the last line wins.

4. Click :guilabel:`Save`.

The list shows, per glossary, how many term pairs take effect. A line without a
separator, or with an empty side, is skipped; if the number is lower than the
number of lines you entered, look for such a line.

Hiding a glossary takes it out of every translation at once. The glossary stays
in the list so it can be switched back on.

One glossary per site and language pair is used. If two visible records claim
the same pair, the one with the lowest uid — the older one — applies, and their
terms are not combined. The list shows the records of each pair in that order,
so the first one listed for a pair is the one that applies.

.. _administration-glossaries-use:

When a glossary applies
=======================

A glossary applies when the extension that requests the translation names the
site and passes no glossary of its own:

..  code-block:: php
    :caption: Naming the site of a translation

    $options = (new TranslationOptions())->withSite('main');
    $result = $translationService->translate($text, 'en', 'de', $options);

On the LLM path the terms are added to the prompt. On the DeepL path nr_llm
creates a DeepL glossary from the terms and passes its id with the request. The
source language must be given explicitly for DeepL, because DeepL uses a
glossary only when the source language is part of the request.

.. _administration-glossaries-deepl:

DeepL glossaries
================

A DeepL glossary cannot be edited once created. nr_llm therefore creates a new
DeepL glossary the first time a changed glossary is used, deletes the previous
one, and reuses the current one as long as the terms stay the same. The DeepL
glossaries appear in the DeepL account under names starting with
``nr_llm glossary``.

DeepL supports glossaries for these languages, in any combination of two
different ones: Arabic, Bulgarian, Chinese, Czech, Danish, Dutch, English,
Estonian, Finnish, French, German, Greek, Hebrew, Hungarian, Indonesian,
Italian, Japanese, Korean, Latvian, Lithuanian, Norwegian (``nb``), Polish,
Portuguese, Romanian, Russian, Slovak, Slovenian, Spanish, Swedish, Turkish,
Ukrainian and Vietnamese. For any other pair the translation runs without the
glossary and the system log records an info line.

If DeepL refuses to create a glossary — the account's glossary limit is
reached, for example — the DeepL translation fails with the DeepL error rather
than running without the terms. Fix the glossary or hide it to translate
without it.

If DeepL rejects a stored glossary — it was deleted in the DeepL account, or
the DeepL key now belongs to another account — nr_llm creates the glossary once
more and repeats the translation once. The system log records a warning when
that happens.

..  _administration-glossaries-delete:

Deleting a glossary
===================

Deleting a glossary record does **not** delete its DeepL glossary. The record
keeps the DeepL id, so restoring it from the recycler reuses the glossary. Once
the deleted record is removed from the database for good, its DeepL glossary
stays in the DeepL account without anything referring to it.

To clean up, list the account's glossaries (``GET /v2/glossaries`` of the DeepL
API, or the glossary overview in the DeepL account) and delete the ones whose
name starts with ``nr_llm glossary`` and whose number is the uid of a glossary
record that no longer exists.

.. include:: /Includes.rst.txt

.. _adr-208:

====================================================================
ADR-208: A site glossary reaches both translators
====================================================================

:Status: Accepted
:Date: 2026-09-25
:Authors: Netresearch DTT GmbH

Context
=======

A glossary existed only as a caller option. :php:`TranslationOptions` carried
``glossary`` as an array of term pairs, :php:`TranslationPromptBuilder` and
:php:`LlmTranslator` wrote it into the prompt, and :php:`DeepLOptions` carried
a ``glossaryId`` that :php:`DeepLTranslator` put on the request. Nothing stored
a glossary, so every consuming extension had to keep its own and pass it on
every call. The DeepL path could only use a glossary someone had created in the
DeepL account by hand; a term list passed as ``glossary`` never reached DeepL at
all.

An editor who wants "Warenkorb" translated as "shopping cart" on one site wants
that on every translation of that site, whichever translator runs.

Decision
========

**A glossary is a record, ``tx_nrllm_glossary``, keyed by site identifier,
source language and target language.** Editors maintain it through FormEngine,
listed in the module :guilabel:`AI > Authoring > Glossaries`, which is built
like the prompt snippet module. The module is admin-only, like its siblings.

**A caller names the site with** :php:`TranslationOptions::withSite()`. When it
passes no ``glossary`` of its own, :php:`TranslationService` asks
:php:`GlossaryResolver` for the site's glossary for the language pair and
hands it on:

- on the LLM paths — :php:`TranslationService::translate()` and
  :php:`TranslationService::translateForConfiguration()` — as the ``glossary``
  option :php:`TranslationPromptBuilder` already renders into the prompt. The
  lookup runs after source-language detection, because which glossary applies
  depends on the source language;
- on the translator path — :php:`TranslationService::translateWithTranslator()`
  and the batch variant — as ``glossary`` for every translator except DeepL,
  which is where :php:`LlmTranslator` reads it, and as ``glossary_id`` for
  DeepL.

An explicit glossary always wins, and the two are never merged: which term
applies must be answerable by reading one list.

**Of two visible records for the same site and pair, the one with the lowest
uid applies** — the older one. The module lists the records of a pair in uid
order, so the one that applies is the first listed; the table has no manual
sort order that could suggest otherwise. Terms are not merged across records.

**The DeepL handoff creates and reuses DeepL glossaries.**
:php:`DeepLGlossarySync` uses the v2 endpoint ``POST /v2/glossaries`` with
``entries_format: tsv`` and ``DELETE /v2/glossaries/{id}``
(https://developers.deepl.com/api-reference/glossaries). A DeepL glossary
cannot be changed, so the record stores the id of the glossary created for its
current terms together with a SHA-256 hash of the language pair and the terms
(``deepl_glossary_id``, ``deepl_entries_hash``). An unchanged record reuses the
id without a request. A changed one gets a new glossary, the record is
repointed, and the superseded glossary is deleted — best effort, with a log
line on failure, and not at all while another row still carries the same id
(one duplicated at database level, for example).

The two columns are not in the TCA: no editor can set them, and a DataHandler
write that names them is ignored.

**A language pair DeepL cannot hold a glossary for translates without one,
with an info log line.** The list is the one the v2 create endpoint documents:
any two different codes of ``ar bg cs da de el en es et fi fr he hu id it ja ko
lt lv nb nl pl pt ro ru sk sl sv tr uk vi zh``. Thai is the documented
exception. The pairs endpoint ``/v2/glossary-language-pairs`` is deprecated in
favour of ``/v3/languages?resource=glossary``
(https://developers.deepl.com/api-reference/glossaries/v2-vs-v3-endpoints), so
the list is held in :php:`DeepLTranslator` rather than fetched.

**The translator path needs an explicit source language.** Without one, no
glossary is looked up: which glossary applies depends on it, and ``glossary_id``
"requires the ``source_lang`` parameter to be set"
(https://developers.deepl.com/api-reference/translate).

**A failed create fails the translation.** Only the cleanup of a superseded
glossary is best effort. When DeepL refuses to create one — the account's
glossary limit, a rejected entry — the translate call ends with the same
:php:`ServiceUnavailableException` any other DeepL error raises: a translation
that silently ignored the editors' glossary would look correct and be wrong.

**A glossary id DeepL rejects is created once more, and the translation is
retried once.** A stored id can stop working without the record changing: the
glossary was deleted in the DeepL account, or the record was written by an
installation using another key. DeepL documents no dedicated answer for an
unknown ``glossary_id``: the translate reference
(https://developers.deepl.com/api-reference/translate) lists 400 "Bad request.
Please check error message and your parameters." and 404 "The requested
resource could not be found." without naming the glossary. So a 400 or 404
whose message mentions the glossary is taken as that case
(:php:`DeepLGlossarySync::isStaleGlossaryError()`): the record's id and hash
are cleared, the glossary is created again and stored, and the call runs once
more with the new id. Any other failure, and a second rejection, reaches the
caller. The rejected id is not deleted; DeepL has just said it does not know
it.

Why the terms are one text field
--------------------------------

The pairs are stored in one ``entries`` text field, one pair per line, separated
by a tab or by the first ``=`` (:php:`GlossaryTerms::fromText()`), and not in an
inline (IRRE) child table. Both work the same way on TYPO3 13.4 and 14.3; the
choice rests on three other points:

- A site glossary is a few hundred lines. An inline table renders one form per
  pair and becomes slow to edit at that size; a text field takes a paste from a
  spreadsheet, which is how such lists usually arrive.
- It is the shape DeepL itself takes (``tsv``), so what an editor sees is what
  DeepL receives.
- The extension has no inline table anywhere. A child table would bring a
  second schema, a second retention decision and a relation to keep in step
  with every copy, move and delete of the parent.

What it costs: a malformed line is skipped rather than refused, so a typo
drops one pair silently. The list shows the number of pairs that take effect,
which is where an editor notices.

Languages are two lowercase ISO 639-1 letters. A request for ``de-DE`` matches a
``de`` glossary, the same way DeepL matches a glossary against ``EN-GB``.

Consequences
============

- ● Consuming extensions get a site's terms on both translators by passing a
  site identifier, and no longer keep glossaries of their own.
- ● DeepL receives the glossary as a DeepL glossary, not as nothing.
- ◐ An unchanged glossary costs no DeepL request; the first translation after
  an edit costs one create and one delete.
- ◑ Two concurrent first translations after an edit can both create a
  glossary; the one whose id is stored last wins and the other stays in the
  DeepL account as an orphan. The window is one request long.
- ◐ The stored id belongs to the DeepL account of the configured key. After
  switching to another account, the first translation per glossary is rejected,
  recreates the glossary in the new account and is retried.
- ◑ A glossary DeepL refuses to create blocks DeepL translations for that site
  and pair until the record is fixed or hidden.
- ◑ Deleting a glossary record does not delete its DeepL glossary. The id stays
  on the deleted row, so restoring the record reuses it; once the row is purged
  from the database, the DeepL glossary is an orphan in the account. Orphans
  are found by their name prefix ``nr_llm glossary`` in
  ``GET /v2/glossaries`` and removed with ``DELETE /v2/glossaries/{id}``, or in
  the DeepL account itself. No hook deletes them on record deletion: a record
  can be restored, and a hook would have to call DeepL from a DataHandler run.
- ◑ Which DeepL error means "unknown glossary" is inferred from status and
  message, not documented by DeepL. Should DeepL word it differently, the
  translation fails with the DeepL error instead of healing itself.
- ◑ The v2 glossary endpoints are DeepL's legacy API; DeepL recommends v3 for
  new integrations and names no removal date. Moving to v3 changes
  :php:`DeepLTranslator::createGlossary()` and nothing that calls it.

Net Score: +1.5

Alternatives considered
=======================

**Merging the site glossary into an explicit one.** Rejected: a caller that
passes terms states which terms apply, and a merge would make the answer depend
on a record the caller never saw.

**Caching the DeepL id in the caching framework.** Rejected: a cache flush
would recreate every glossary against DeepL's per-account limit, and it would
lose the old id, which is the only handle for deleting the superseded glossary.

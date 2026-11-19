.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

..  _a2a-page-metadata-skill:

================================
A2A skill: page metadata
================================

The domain handler ``nr_llm.page_metadata.v1`` publishes one defined task
through the :ref:`A2A server role <administration-a2a-server>`: write the
named metadata fields of one page in one language, as a draft in the
client's workspace (:ref:`ADR-296 <adr-296>`). The client sends checked
values instead of prose, and the run's writes are held to the page, the
table ``pages`` and the language the request names.

..  _a2a-page-metadata-skill-request:

The request
===========

The text part of the client's message is one JSON object with exactly three
keys:

..  code-block:: json
    :caption: A request for the German description and SEO title of page 42

    {"page": 42, "language": 1, "fields": ["description", "seo_title"]}

``page``
   The uid of a default-language page, as a JSON integer.

``language``
   The uid of the site language to write, as a JSON integer. ``0`` writes the
   page itself; any other value writes the page's translation in that
   language, which must exist in the client's workspace. The skill does not
   create a translation.

``fields``
   A non-empty list of distinct field names: ``title``, ``subtitle``,
   ``nav_title``, ``abstract``, ``description``, ``keywords``, ``seo_title``,
   ``og_title``, ``og_description``, ``twitter_title``,
   ``twitter_description``, ``twitter_card``. The SEO and social fields exist
   only when EXT:seo is installed; without it, a request for one is accepted
   and the write fails in the tool.

A request that does not fit is answered with the JSON-RPC error ``-32602``
before a task exists. Its message names the rule, for example
``"page" must be a positive integer`` or ``only the keys "page", "language"
and "fields" are allowed``, never a value from the request. Text beside the
three keys, an instruction such as "use admin rights" included, is such an
error. A page the client's technical user may not see (outside its web
mounts or page permissions), a row to write it may not edit, a deleted or
missing page (also one deleted in the client's workspace), and a language
the user may not edit all get the same message, so a client cannot probe the
page tree through the answer.

The A2A agent card has no field for an input schema. Write the request format
into the profile's :guilabel:`Description` and give one request as an
:guilabel:`Example`, so a client can read it from the card.

..  _a2a-page-metadata-skill-run:

What the run may do
===================

- It is bound to the scope of its assignment
  (:ref:`assignment-scope`): the page, depth 0, the table
  ``pages`` and the requested language. A write to another page, another
  table or another language is refused before it reaches an approval, also
  after a question to the client and the client's answer.
- It is offered ``get_page_content``, ``check_page_metadata`` and
  ``update_page_metadata``, and ``ask_choice`` when the profile grants it.
  Other tools of the profile are not offered to this skill.
- Its user message is written by the handler from the checked values and
  names the record to update and the requested fields. The client's text
  does not reach the model; after an ``ask_choice`` question, the client's
  answer does, as one of the options the model offered.
- A write must meet the request before it reaches an approval, and be the
  proposal of a passed check on the record as that check saw it when it
  runs (see below).
- A write waits for an approval in the backend like every A2A write. A
  configuration that requires a second approver applies as to every run.

..  _a2a-page-metadata-skill-contract:

What a write must meet
======================

The invocation rule ``page_metadata_contract`` (:ref:`ADR-297 <adr-297>`)
checks every call of ``update_page_metadata`` in a run of this skill before
it reaches an approval. A call that fails is refused, the model reads why,
and the governance log records ``invocation_denied`` with the rule's
identifier and one of these reasons:

``field_not_requested``
   The call sets a field the request did not name, or none.

``value_not_allowed``
   A value is not text, has no visible character (control characters such
   as U+0001 and fill characters such as U+3164 count as visible), or is
   longer than its limit: 255 characters for
   title, subtitle, navigation title, keywords and X card; 1000 for the
   abstract; 300 for the description and the Open Graph and X
   descriptions; 100 for the SEO, Open Graph and X titles.

``proposal_not_checked`` (rule ``page_metadata_binding``)
   No passed ``check_page_metadata`` precedes the write, or the latest
   passed check was of other arguments. A failed check does not count, and a
   later passed check of another variant replaces an earlier one.

``changed_since_check`` (rule ``page_metadata_binding``)
   The checked fields of the requested row, as the client's workspace shows
   them (its draft version when one exists, else the live row), are no longer
   what the check saw, or the row can no longer be read. The model checks the
   proposal again. Only the proposed fields count; a moved ``tstamp`` is no
   change, and no clock is compared.

``history_incomplete`` (rule ``page_metadata_binding``)
   The run's history is incomplete, for example after a requeue, and holds
   no passed check since. Writes of this skill are refused until the run
   checks again; other calls are not affected.

The rule ``page_metadata_binding`` depends on the run's history, so it is
asked when the call runs, after its approval: a check and a write in one
turn both reach the approval, and an editor can approve a write that is then
refused. Nothing is written then.

``not_the_requested_row``
   The call aims at another row of the requested page and language, at a
   workspace version uid (the tool takes the live uid only), at a row the
   scope admits by another path,
   or the requested row no longer exists in the workspace.

``request_unreadable``
   The task's request cannot be read: its profile is gone, its history is
   full (100 messages), or the configuration's privacy level stores no
   message content. Such a configuration cannot run this skill.

..  _a2a-page-metadata-skill-check:

The check before the write
--------------------------

The tool ``check_page_metadata`` takes exactly the arguments of
``update_page_metadata`` and judges them with the conditions above
(``not_the_requested_row``, ``field_not_requested``, ``value_not_allowed``).
A passed check reports the state of the proposed fields of the requested
row, as the client's workspace shows them. The run's invocation history keeps
a keyed digest of the check's arguments and of that state, never the values
(ADR-298). The write runs only when its arguments have the digest of the
latest passed check and the record's state has the digest that check
observed. The tool changes nothing; outside a run of this skill it fails.

Rotating the installation's encryption key invalidates the digests of
waiting runs: their writes are refused until the model checks again.

A call aimed at a row the scope refuses (another page, table or language, a
uid without a row) is refused by the scope with its own reason
(``outside_scope``, ``language_not_allowed``, ``unresolvable``).

A request for the default-language page (``"language": 0``) of a page that
has translations writes that page only. TYPO3 adds the page's translations to
every write of the page, with the fields it keeps equal in all languages
(``l10n_mode`` ``exclude`` and ``allowLanguageSynchronization``). In a draft
workspace that addition gives each translation the user may edit a version of
its own, with no changed value; core does so for an editor who saves the
page's description in the workspace too. None of the fields this skill sets is
one of them, so in the skill's own write that addition is left out and the
translations get no draft. A write that follows the skill's write outside of
it, such as the metadata `AI Label <https://extensions.typo3.org/extension/ai_label>`__
stores on the page's draft, is a plain write of core: on TYPO3 14 the
translations get their version there, as for an editor. The ``writes`` artifact names such
records in a block of their own, "Further records the run's completed tool
calls wrote". The exception is a page
with a file in one of its synchronised file fields (``media``, ``og_image``,
``twitter_image``), on the page itself or on a translation that follows it:
TYPO3 handles those files before the scope sees the write, so the write is
refused unless the request's scope covers the languages of the
translations, which a request for one language does not.

Discarding the page's draft does not discard such a version of a
translation: an editor who rejects the change has to discard both. Core
discards with a page's draft only the drafts whose translation parent is that
draft, which holds for a page created in the workspace. The version of a
translation of an existing page points at the live page, so it stays. In the
:guilabel:`Workspaces` module, :guilabel:`Discard` on a row discards that row's
record and its inline child records (file references, for example), and a
discarded selection the records selected, so the translation's row is
discarded on its own. :guilabel:`Discard` in the workspace preview of the page
discards every draft on the page at once: the page's, its translations', and
those of its content elements and other records. Discard the translation's row
when other drafts on the page are to stay. This is read in the code of TYPO3
14.3.12 and 13.4.40 (``DataHandler::discardLocalizationOverlayRecords()``,
``DataHandler::discardRecordRelations()`` and the module's discard actions),
and core's own test of a discarded page draft
(``WorkspacesDiscard``, ``modifyPage``) expects the drafts of the page's
translations to remain.

..  _a2a-page-metadata-skill-setup:

Setting it up
=============

#. Create an LLM configuration for the skill. On its :guilabel:`Access` tab
   switch on :guilabel:`Require a draft workspace for changes` and
   :guilabel:`Require the scope of an assignment for changes`. The skill
   refuses to run on a configuration without both.
#. Enable the tools ``get_page_content``, ``check_page_metadata`` and
   ``update_page_metadata`` in the Tools module.
#. Create a publication profile with the handler
   ``nr_llm.page_metadata.v1``, that configuration, the three tools (and
   ``ask_choice`` if the run may ask the client), and :guilabel:`May write`.
#. Pin the client that may call it to a draft workspace its technical user
   is a member of, and give that user edit access to the rows and the
   languages the skill may write and to the fields it may set. For a
   translation the default-language page only has to be visible to that
   user; a translator group that edits only its language is enough.

When the profile or the configuration does not meet these conditions, a
request is answered with ``-32004`` "This skill is not available." and the
reason is logged as a warning. A client that is not pinned to a draft
workspace is refused earlier, as for every writing profile.

Every write of this skill waits for an approval in the backend, and a
waiting task is ``WORKING`` for the client. A streamed task
(``SendStreamingMessage``) therefore holds its stream, a stream slot and a
PHP worker until the stream's time limit. Clients of this skill should send
``SendMessage`` with ``returnImmediately`` and follow the task with
``GetTask``.

..  _a2a-page-metadata-skill-result:

The result
==========

The task ends with the model's answer as text and the ``writes`` artifact
that lists the writes the run executed (:ref:`administration-a2a-server`).
The model's answer is not evidence of a write; the ``writes`` artifact is.
A record a failed call changed all the same is listed there in a block of
its own.

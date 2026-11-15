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
- It is offered ``get_page_content`` and ``update_page_metadata``, and
  ``ask_choice`` when the profile grants it. Other tools of the profile are
  not offered to this skill.
- Its user message is written by the handler from the checked values and
  names the record to update and the requested fields. The client's text
  does not reach the model; after an ``ask_choice`` question, the client's
  answer does, as one of the options the model offered.
- A write must meet the request before it reaches an approval, and follow
  a read of the page when it runs (see below).
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

``page_not_read`` (rule ``page_metadata_read_first``)
   The run has not called ``get_page_content`` successfully yet. Any page
   counts; the rule does not know which page was read. This rule is asked
   when the call runs, after its approval, so an editor can approve a write
   that is then refused.

``history_incomplete`` (rule ``page_metadata_read_first``)
   The run's history is incomplete, for example after a requeue, so it
   cannot show a read. Writes of this skill in that run are refused; other
   calls are not affected.

``changed_since_accepted``
   The row's ``tstamp`` as the client's workspace shows it (its draft
   version when one exists, else the live row) is later than the task's
   acceptance. The client sends the task again. ``tstamp`` is the
   start of the request that wrote the row, so a save that started before
   the task was accepted is not seen.

``not_the_requested_row``
   The call aims at another row of the requested page and language, at a
   version of another workspace, or the requested row no longer exists in
   the workspace.

``request_unreadable``
   The task's request cannot be read: its profile is gone, its history is
   full (100 messages), or the configuration's privacy level stores no
   message content. Such a configuration cannot run this skill.

A call aimed at another page, table or language is refused by the scope
with its own reason (``outside_scope``, ``language_not_allowed``,
``scope_unresolvable``).

..  _a2a-page-metadata-skill-setup:

Setting it up
=============

#. Create an LLM configuration for the skill. On its :guilabel:`Access` tab
   switch on :guilabel:`Require a draft workspace for changes` and
   :guilabel:`Require the scope of an assignment for changes`. The skill
   refuses to run on a configuration without both.
#. Enable the tools ``get_page_content`` and ``update_page_metadata`` in the
   Tools module.
#. Create a publication profile with the handler
   ``nr_llm.page_metadata.v1``, that configuration, the two tools (and
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

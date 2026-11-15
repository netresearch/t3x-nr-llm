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
values instead of prose, and the run is held to the page, the table
``pages`` and the language the request names.

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
   only when EXT:seo is installed.

A request that does not fit is answered with the JSON-RPC error ``-32602``
before a task exists. Its message names the rule, for example
``"page" must be a positive integer`` or ``only the keys "page", "language"
and "fields" are allowed``, never a value from the request. Text beside the
three keys, an instruction such as "use admin rights" included, is such an
error. A page the client's technical user may not show (outside its web
mounts or page permissions), a deleted or missing page, and a language the
user may not edit all get the same message, so a client cannot probe the
page tree.

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
  names the record to update. Nothing the client writes reaches the model.
- A write waits for an approval in the backend like every A2A write. A
  configuration that requires a second approver applies as to every run.

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
   is a member of.

When the profile or the configuration does not meet these conditions, a
request is answered with ``-32004`` "This skill is not available." and the
reason is logged as a warning.

..  _a2a-page-metadata-skill-result:

The result
==========

The task ends with the model's answer as text and the ``writes`` artifact
that lists the writes the run executed (:ref:`administration-a2a-server`).
The model's answer is not evidence of a write; the ``writes`` artifact is.

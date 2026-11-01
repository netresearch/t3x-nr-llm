.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-term-lists:

==========
Term lists
==========

A term list stops generated text. A forbidden term must not appear in it, and a
required term must. The editor does not get the text and reads which term
stopped it. :ref:`ADR-238 <adr-238>` records the decisions.

Open :guilabel:`AI > Authoring > Term Lists` to see the lists. The module is for
administrators, like its neighbours. To let an editor group maintain lists,
store the records on a page in that group's web mount and grant the table under
:guilabel:`Tables (modify)`. The group then edits them in the list module.

.. _administration-term-lists-scope:

Where a list applies
====================

:guilabel:`Configurations`
    Required. Text generated with these configurations is checked. Calls on any
    other configuration are not, and neither is a call with no configuration.
    Without a list naming a configuration, nothing changes for it.

:guilabel:`Site`
    Optional. A caller can state the site through the metadata key
    :php:`ProviderCallContext::METADATA_SITE`. A list bound to a site stays out
    of calls that name another site. When a call names no site, the forbidden
    terms of the list still apply and the required terms do not.

:guilabel:`Language`
    Optional, two letters. It works like the site, through
    :php:`ProviderCallContext::METADATA_LANGUAGE`.

A configuration's selection of allowed guardrails cannot switch a term list off.

.. _administration-term-lists-matching:

How terms match
===============

One term per line.

- Case is ignored. ``ß`` and ``ss`` stay different letters.
- A term matches whole words. ``Kunde`` does not match ``Kundenkonto``.
- A space in a term matches any white space, including a line break.
- There is no inflection. ``Rabatt`` does not match ``Rabatte``. List each form,
  or end the term with ``*``: ``Rabatt*`` matches ``Rabatte`` and
  ``Rabattaktion``. It matches nothing in front of the term.
- HTML does not hide a term. ``Ra<b>batt</b>`` and ``Pr&uuml;fung`` are found.

Synonyms, misspellings and paraphrases are not found.

Forbidden terms are searched in the text of the answer and in what a tool call
would write. Required terms are searched in the text of the answer, and a step
that only calls a tool is not checked for them. Choose configurations that
write whole texts: a one-line answer lacks a required term.

.. _administration-term-lists-block:

What a block does
=================

The output is not delivered and not cached, and a tool call in it does not run.
The message names the list and the terms, followed by the message of the list.
It is written in the language of the backend user. The block appears as a
governance event.

On a configuration a list names, a stream is held back and checked as a whole,
so the editor sees no text growing.

When the check itself fails, for example because the database cannot be read,
the output is blocked and the message says the check could not run. The one
exception is the table not existing yet: until the database schema update ran,
the output passes and a warning is logged.

Not screened are DeepL translations, images, speech and embeddings.

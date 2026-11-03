.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-255:

=============================================================
ADR-255: A record select explains the chosen option
=============================================================

:Status: Accepted
:Date: 2026-11-03
:Authors: Netresearch DTT GmbH

Context
=======

Integrators choose a model, a configuration, a task or a skill from a select
that shows each record's name and nothing else. The names do not say what a
record does: a configuration called "Demo GPT-4o mini" does not say what it
is for, two tasks can both be called "Translate Content", and a skill's name
is often a short title from its source. The records have a description, and
the model and provider behind a configuration are known; none of it reaches
the select.

What the platform offers, read in TYPO3 13.4.37 and 14.3.10:

- A native ``<option>`` renders one line of text. ``SelectSingleElement``
  ignores an item's ``description``; ``SelectMultipleSideBySideElement`` puts
  the label in the option's ``title`` attribute, which only a mouse hover
  shows. Only ``SelectCheckBoxElement`` reads ``description``.
- Customizable select (``appearance: base-select``) allows markup in an
  option, but it is not Baseline: Chrome and Edge 135 and Safari 27 ship it,
  Firefox 149 only behind two preferences (MDN browser-compat-data 8.1.8).
  The option's whole text would also become its accessible name.
- Core's field controls ``addRecord``, ``editPopup`` and ``listModule`` render
  for every user and leave the refusal to the page they open.
  ``editPopup`` finds its select by ``data-formengine-input-name``, which only
  a multiple select has; on a ``selectSingle`` it throws in the browser.
  ``listModule`` opens the List module on the edited record's page, where
  the nr-llm records usually are not.

Decision
========

**A field wizard under the select shows the chosen option's description and
the facts its label hides.** ``nrllmChoiceHelp``
(:php:`ChoiceHelpWizard`, :php:`ChoiceSummaryProvider`,
``Backend/ChoiceHelp.js``):

- a configuration names its model and provider, or says that it picks a
  model by criteria or has none yet; a task names its configuration; a model
  names its provider and model ID; a provider its adapter; a skill its
  identifier;
- only the options FormEngine already offers are described, so no record the
  user cannot choose reaches the page; the text is written as text, never as
  markup;
- the element is tied to every select of the field with ``aria-describedby``
  and is a polite, atomic live region. Polite, because the new text belongs
  after the option name the screen reader reads first; assertive would cut
  that name off; without a live region a changed choice would keep the old
  explanation until the select is focused again. Checked in Chromium's
  accessibility tree on 13.4 and 14.3: the select's description is the help
  text and changes with the arrow keys;
- ``selectMultipleSideBySide``: the text follows the option last activated in
  either list, so moving through the available items with the arrow keys
  compares them one by one (``change`` fires on arrow keys in Chromium and
  Firefox on both TYPO3 versions).

**Create and manage controls are shown only to users who can use them.**
``nrllmAddRecord`` is core's ``addRecord`` behind :php:`RecordControlAccess`:
the table must be modifiable and the target page must allow editing content;
the root level is open to administrators only. ``nrllmManageRecords`` opens
the nr-llm module that lists the records, through core's ``list-module.js``
(which asks to save unsaved changes first), and only for a user with access
to that module. Both give their link an accessible name; core's controls
have none, because the text sits in the icon's ``title``.

Which select gets what:

- configuration's model, task's configuration: help, create, manage;
- skills of a configuration and of a task, model's provider, term list's
  configurations: help, manage. Skills come from sources with an approval;
  providers are created through the setup wizard, which normalises the
  endpoint;
- the landing page template's configuration and image task use the same
  nodes from nr-landingpage.

Consequences
============

- One implementation, used by every extension through the render types;
  another extension's record select on an nr-llm table only needs the TCA
  entries.
- An "edit the chosen record" control is not offered on single selects:
  core's ``editPopup`` does not work there, and the manage control plus the
  description cover the need to know what a record is.
- A richer picker (a listbox or dialog per option) was not built. Comparing
  options one by one works with the help text; a dialog would duplicate the
  side-by-side element's filter and ordering. To revisit if an installation
  has dozens of similar skills.

Alternatives considered
=======================

- Customizable select: not Baseline; usable only as an enhancement on top of
  the help text, and longer accessible names for every option.
- The record description in the option label: one long line per option, cut
  off by the select's width.
- Core field controls without a check: buttons that lead to an access error
  for editors without the right.

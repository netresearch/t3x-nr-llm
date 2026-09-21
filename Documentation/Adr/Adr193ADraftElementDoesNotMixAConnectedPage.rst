.. include:: /Includes.rst.txt

.. _adr-193:

============================================================================
ADR-193: A draft element does not turn a connected page into a mixed one
============================================================================

:Status: Accepted
:Date: 2026-09-21
:Amends: :ref:`ADR-146 <adr-146>` (its free-mode element, on a page that translates in connected mode)
:Authors: Netresearch DTT GmbH

.. _adr-193-context:

Context
=======

``create_content_element_draft`` (:ref:`ADR-146 <adr-146>`) writes the element
in the language it is asked for and never sets a translation parent. ADR-146
recorded that as deliberate and named the cost among its consequences: the
description points a model at ``create_translation_draft``, and "a model that
ignores both produces an element an editor has to clean up".

On the Netresearch demo installation a model did. It created elements in
language 1 on a page that also holds connected translations in language 1, and
the page module reports *Inconsistent content detected in language "…"* on
that page.

That warning has an exact condition. It was read in
:php:`\TYPO3\CMS\Backend\View\BackendLayout\ContentFetcher::getTranslationData()`
of ``typo3/cms-backend`` 14.3.7, the version this repository resolved on the
day of this record:

- The default language is never judged; the method returns early for it.
- For any other language it walks the ``tt_content`` rows of that language on
  the page. Deleted rows are out, **hidden rows are in**, the workspace
  restriction of the acting user applies, and rows for "all languages"
  (``sys_language_uid = -1``) are skipped.
- A row with ``l18n_parent = 0`` marks the language as holding standalone
  content, a row with ``l18n_parent > 0`` as holding translations. When both
  marks are set the mode is ``mixed`` and the warning is queued.
- The warning is suppressed when
  :php:`DrawingConfiguration::getAllowInconsistentLanguageHandling()` is true,
  which :php:`DrawingConfiguration::create()` reads from the page TSconfig
  ``mod.web_layout.allowInconsistentLanguageHandling`` with a plain cast to
  bool.

The tool only ever adds rows of the first kind. So one fact decides whether a
call produces the warning: whether the page already holds a row of the second
kind in that language.

.. _adr-193-decision:

Decision
========

``create_content_element_draft`` refuses a call with ``language`` greater than
zero when the target page already holds at least one ``tt_content`` row with
that ``sys_language_uid`` and ``l18n_parent > 0``. Deleted rows do not count,
hidden rows do — as core counts them, and every draft this extension writes is
hidden.

The refusal is resolved in the tool's ``plan()``, so it is reached before
anything is written, and :php:`previewCall()` and the viewer gate answer with
it as they do for every other refusal of this tool. It comes **after** the
neutral *Page not found or not permitted.*, because it names the page: a user
who may not edit the page learns nothing about what is on it.

The message names the page uid and the language uid and says what to do
instead: create the element in the default language (0), translate it with
``create_translation_draft`` or the translation tools of the CMS, and tell the
editor that the translation is a separate step. The tool description and the
``language`` argument description say the same on the wire, so a model can
choose before it calls.

.. _adr-193-allowed:

What stays allowed, and why
===========================

**The default language.** Unchanged; it is never queried, as core never judges
it.

**Free mode.** A page that holds no row in the language, or only standalone
rows, takes another standalone one. A page that is free-mode throughout is
consistent, core raises nothing on it, and ADR-146's reasoning for creating
without a translation parent still holds there.

**The integrator's opt-in.** When the page TSconfig sets
``mod.web_layout.allowInconsistentLanguageHandling``, the tool does not refuse.
It is the switch that silences the same warning in core, read the same way
(rootline-merged through :php:`BackendUtility::getPagesTSconfig()`, cast to
bool), so the same switch lifts the refusal. The row check itself is wider
than core's, as the two sections below state. The TSconfig
is only read once a connected row was found.

.. _adr-193-workspaces:

Workspaces
==========

No workspace code is added. Every builtin writer refuses outside the live
workspace (:php:`WritesThroughDataHandlerTrait`), so the live workspace is the
only one a write happens in.

The query carries the deleted restriction and no workspace restriction, which
is the one place it is wider than core's: a connected translation that exists
only as another workspace's draft counts. That is deliberate. The draft sits on
the page and becomes a live connected translation when that workspace is
published, at which point a free element created in between would mix the
page.

.. _adr-193-limit:

What this does not prevent
==========================

The opposite order. A page that holds only free elements in a language becomes
mixed the moment a connected translation is added to it — through
``create_translation_draft``, through the backend's *Translate* wizard, or
through an extension that translates automatically. This record guards one
writer against one direction; it does not make mixed pages impossible, and it
does not repair a page that already is one.

Nor does it judge the page as a whole. A page that is already mixed is refused
like a connected one, because it holds a connected row; nothing is reported
about rows the tool did not write.

Core's query is open to listeners of
:php:`ModifyDatabaseQueryForContentEvent`; the tool's is not. An installation
whose listener hides rows from the page module can therefore be refused for a
connected row its editors do not see there.

.. _adr-193-consequences:

Consequences
============

✓ This tool can no longer be the cause of the page module's inconsistency
warning on a page that translates in connected mode.

✓ The refusal names the way that works — the default language, then a
translation — instead of only declining.

✕ One more query per call with a non-default language, and a TSconfig
resolution when that query finds something.

✕ An editor who wants a standalone element beside connected translations, and
whose integrator has not set the TSconfig switch, cannot get it from this tool.
The backend still lets them create it by hand.

✕ The opposite order stays open, as stated above.

.. _adr-193-revisit:

Revisit when
============

``create_translation_draft`` or another writer is found to produce mixed pages
from the other direction in practice, or core changes the condition
:php:`ContentFetcher::getTranslationData()` applies — the guard mirrors that
method and has to follow it.

.. include:: /Includes.rst.txt

.. _adr-199:

============================================================================
ADR-199: A copy is a hidden draft, and a page is copied without its branch
============================================================================

:Status: Accepted
:Date: 2026-09-23
:Amends: :ref:`ADR-180 <adr-180>` (its one-record rule, for the rows core's copy command brings along)
:Authors: Netresearch DTT GmbH

.. _adr-199-context:

Context
=======

:ref:`ADR-180 <adr-180>` held that one call creates one record, so an
approver judges one thing. ``copy_record`` (:ref:`ADR-198 <adr-198>`) runs
core's copy command, and that command is more than one row: it copies the
translations of a default-language record where the target site carries
their language, the file references and inline children of every copied
row, and for a page the records stored on it — for a non-admin, those of the
tables in ``tables_modify``. It can also copy a page's whole branch, and it
hides only what it copies FIRST: ``hideAtCopy`` applies to the top record,
unless the user's ``neverHideAtCopy`` preference switches even that off.

.. _adr-199-decision:

Decision
========

1. **The copy is core's copy.** The rows it brings along are the ones the
   page module's copy would bring; the tool does not pick among them, and
   the approval card counts them — the translations, and for a page the
   content elements on it.
2. **The copy is hidden, whatever core and the user's preferences say — and
   so is every translation copied with it.** The hidden column is set in the
   paste ``update`` of the same command, which reaches the copy only; the
   copied translations get it from core's ``hideAtCopy``, which the user
   preference ``neverHideAtCopy`` (pinned to 0 for the call) and page TSconfig
   ``disableHideAtCopy`` switch off, so the tool sets it on every copied
   translation that came out visible, in a second write of the same call.
   Everything is read back; a copy with a visible translation, on another
   page or in another column is deleted again, together with its
   translations, and the answer says whether that worked. Between the copy
   and the second write a copied translation can be visible for the duration
   of one request. Nothing a writer of this extension brings into being is
   visible before a human looks at it (:ref:`ADR-135 <adr-135>`).
3. **A page is copied without its subpages, always.** Core would hide only
   the top page of a copied branch and leave every copied subpage as visible
   as its original — reachable under a hidden parent, with a new URL. The
   depth is pinned to 0 for the call: TYPO3 14 reads it from the acting
   user's ``copyLevels`` preference, which the tool sets for the call and
   puts back without saving it; TYPO3 13 reads the DataHandler's
   ``copyTree``, which is 0 unless set.
4. **Translations follow core.** A translation is never copied by itself; a
   default-language record's translations are copied only where core places
   them, and one it cannot place fails the call and the copy is taken back.
   The answer counts the translations that were placed.

.. _adr-199-consequences:

Consequences
============

✓ The one-record rule becomes "one record the approver named, and what
core copies with it, counted on the card" — the rule a human applies in the
page module.

✕ A branch is copied page by page, one approval each. That is slower than
core's recursive copy and is the price of every copied page being hidden.

.. _adr-199-revisit:

Revisit when
============

A branch copy is wanted often enough that one approval per page is a
burden; the rule then needs every copied page hidden in the same command,
which core's copy does not offer.

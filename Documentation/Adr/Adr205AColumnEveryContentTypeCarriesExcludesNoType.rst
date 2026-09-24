.. include:: /Includes.rst.txt

.. _adr-205:

====================================================================
ADR-205: A column every content type carries excludes no type
====================================================================

:Status: Accepted
:Date: 2026-09-24
:Amends: :ref:`ADR-196 <adr-196>` (its rule "one excluding column excludes
    the type" no longer counts the columns of the shared form)
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-196 <adr-196>` offers a content type unless, among other things, its
form holds an *excluding* column: a FlexForm, inline records, a record
reference, a ``user`` field and every TCA type the tool does not know. One
excluding column excludes the type.

On the Netresearch demo that rule offered no type at all. The TCA was loaded,
``tt_content.CType`` declared 65 items, and every candidate carried two
excluding columns that are not its own:

- ``background_image_options``, a FlexForm bootstrap_package adds to core's
  ``frames`` palette, which every content type uses;
- ``tx_contexts_settings``, a ``user`` field EXT:contexts appends to the
  ``showitem`` of every type (64 of 65).

``create_content_element_draft`` refused every call with
``Allowed here: none``, and ``update_content_element``, which checks the same
list, refused every element. Measured on 2026-09-24 in a CLI process of the
demo's web container with the tool's own ``availableTypes()``.

Decision
========

**The form of core's** ``header`` **type is the shared form, and its columns
do not decide.** A column of that form is left at its default, like an
*unfilled* column, and never becomes a key of ``fields``. Every other
excluding column still excludes the type, as before.

``header`` is the smallest content form, and core copies it onto a plugin
registered without a form of its own. An extension that adds a column to every
content type reaches ``header`` as well, whichever way it adds it — through one
of the palettes core gives every type, or through every ``showitem``. What only
some types carry is theirs: bootstrap_package's accordion, carousel or tab keep
their inline items and ``pi_flexform`` and stay excluded.

Consequences
============

- On the demo, ``header``, ``text``, ``textmedia``, ``bullets``, ``table`` and
  the other prose types are offered again; a type with a FlexForm or inline
  column of its own is still refused.
- An extension that puts a payload column into the ``header`` form would have
  it treated as shared. No such extension is known; the column would be left
  at its default, and the element would be created without it, as for an
  unfilled column.
- An installation without a ``header`` type has no shared form, and the rule
  of :ref:`ADR-196 <adr-196>` applies unchanged.

Alternatives considered
=======================

**The columns every candidate type carries.** Measured on the demo, the
intersection over all 29 candidate types does not contain
``background_image_options``: one type of the site package lacks it. A single
type that differs would take the relief away again.

**Excluding only required columns.** An inline column with no minimum is
optional, and an accordion without items is still not a text. The rule would
offer types whose content the draft cannot carry.

.. include:: /Includes.rst.txt

.. _adr-192:

============================================================================
ADR-192: The eighth writer describes an asset, and stays out of the seventh's field
============================================================================

:Status: Accepted
:Date: 2026-09-16
:Amends: :ref:`ADR-135 <adr-135>` (a second writer on ``sys_file_metadata``)
:Authors: Netresearch DTT GmbH

.. _adr-192-context:

Context
=======

``set_file_alternative_text`` (:ref:`ADR-135 <adr-135>`) writes one field of
``sys_file_metadata`` and refuses every other argument by name. That refusal is
the right behaviour for a tool whose name states the field it writes, and it
left an assistant asked to caption an asset able to set the alternative text
and nothing else — which it reported accurately, and uselessly. ``title`` and
``description`` had no writer at all, and ``read_fal_asset_meta`` does not even
read ``description`` back.

The obvious move is to widen ``set_file_alternative_text`` into a general
metadata writer. This record says why that is the wrong one, and what the
eighth writing tool does instead.

.. _adr-192-decision:

Decision
========

``update_fal_asset_meta`` is the eighth writing tool, on exactly the terms of
the previous seven: disabled by default, in the ``editing`` group, an explicit
:php:`ToolEffect` (``IDEMPOTENT_WRITE`` — setting named scalar fields to given
values converges on repeat), a human approval before every call
(:ref:`ADR-134 <adr-134>`), a preview at suspend (:ref:`ADR-136 <adr-136>`), a
write through the ``DataHandler`` under the acting user's permissions
(:ref:`ADR-083 <adr-083>`), a read-after-write verification, and a refusal
vocabulary that never confirms a uid exists. It uses the
:ref:`ADR-146 <adr-146>` ``plan()`` shape, as :ref:`ADR-180 <adr-180>` asked the
writers after it to.

It writes ``title`` and ``description``, either or both, on one file's live
default-language metadata record. It does **not** write ``alternative``.

.. _adr-192-disjoint:

Two tools, and no field with two writers
========================================

Widening ``set_file_alternative_text`` was rejected on three grounds, and only
the third is decisive.

The first two are ordinary: it is shipped, tested code, and its name states the
one field it writes — widening it would change what it does while keeping a
name describing the old behaviour.

The third is the one that would have cost somebody something. A field with two
writers gives an approver two cards that can both claim it, and the refusal
vocabulary would need a second meaning for "this tool does not write that": at
present it means *another tool does*, which is actionable. So the two tools are
**field-disjoint by construction**, and each says so on the wire — this one's
description names ``set_file_alternative_text`` rather than only declining, so a
model told "not here" is not left to guess where.

The cost is named rather than hidden: an assistant setting all three fields
makes two calls and costs two approvals. That is the same trade
:ref:`ADR-180 <adr-180>` made for "page plus first element", for the same
reason — one card, one thing.

.. _adr-192-update:

The first ``plan()`` writer that updates
========================================

The four ``plan()`` writers before it all **create** a record, and
:php:`PlansOneEditorialWriteTrait::createRecord()` was extracted for them
(:ref:`ADR-180 <adr-180>`). This one updates, so that helper does not apply and
the datamap and the read-back are its own.

Nothing is extracted for that yet, deliberately. One implementation is not a
shape — that is the same test :ref:`ADR-146 <adr-146>` applied when it declined
to grow :php:`WritesThroughDataHandlerTrait`, and the same reason the two
pre-ADR-146 writers, which also update, stay unretrofitted. A **second**
updating ``plan()`` writer is the trigger to look again.

.. _adr-192-measured:

Two behaviours that came out of reading core rather than assuming it
====================================================================

**An omitted field is not an empty one.** A field the call leaves out is absent
from the datamap; an empty string clears the field it names. Collapsing the two
would make "set the title" also erase a description an editor wrote by hand,
which is the one way a metadata writer destroys work nobody asked it to touch.
Three tests fail when that distinction is removed.

**The field-level grant is asked BEFORE the write.** Core ships
``sys_file_metadata.title`` with ``'exclude' => true`` and ``description``
without one. The ``DataHandler`` skips a field the acting user holds no
``non_exclude_fields`` grant for — silently, with an empty ``errorLog`` — and
applies the rest of the datamap. For the one-field writer before it that is a
failure to detect after the fact; here it would be a **half-described asset**,
because a user granted ``description`` and not ``title`` would get the
description written and a failure reported. The tool therefore asks the same
question the ``DataHandler`` asks, through the same method
(:php:`BackendUserAuthentication::check('non_exclude_fields', …)`), before
writing anything, and refuses the whole call. The per-field read-back stays as
the backstop for everything that check does not model.

Worth recording about that method: it tests ``isset($groupData[$type])``
**before** ``isAdmin()``, so a user object assembled without group data fails
the grant check whatever its admin flag says. The tool inherits that faithfully
because it calls the same method; a reimplementation would not have.

**The title is bounded in bytes.** The column is ``tinytext`` — 255 **bytes**,
not 255 characters. A German title of 200 characters is 400 bytes and would be
truncated by the database rather than refused by the tool. Where an
installation declares a TCA ``max`` that is narrower, it wins: it is what the
backend form enforces, and a tool accepting more would write what an editor
could not.

.. _adr-192-consequences:

Consequences
============

✓ Eight editorial writes are available where seven were, and the editorial
metadata of an asset is writable at all.

✓ No field of ``sys_file_metadata`` has two writers, so every approval card
answers for exactly one tool.

✕ Describing an asset fully costs two approvals, because the alternative text
is another tool's.

✕ ``read_fal_asset_meta`` still does not return ``description``, so an admin
using the agent cannot read back a field this tool can write. The approval card
shows the before value, which is the channel that matters for a non-admin — the
reader is admin-only and in the ``structure`` group — but the asymmetry is real
and is left rather than widened here.

✕ The updating datamap and read-back exist twice on ``sys_file_metadata``, once
in each of the two writers that touch it.

.. _adr-192-revisit:

Revisit when
============

A **second** updating ``plan()`` writer is proposed — then the question
:ref:`ADR-146 <adr-146>` asked about the creating ones applies to these, and
the answer may be an extraction.

Also revisit if a field ever genuinely needs two writers. The disjointness
above is a rule this record chose, not one the runtime enforces.

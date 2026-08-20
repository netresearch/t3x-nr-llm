.. include:: /Includes.rst.txt

.. _adr-146:

============================================================================
ADR-146: Three more editorial writers, and what the third one reviewed
============================================================================

:Status: Accepted (its "revisit at the sixth writer" trigger fired — see :ref:`ADR-180 <adr-180>`)
:Date: 2026-08-10
:Amends: :ref:`ADR-135 <adr-135>` (its "revisit at the third writer" trigger)
:Amended: 2026-08-20 by :ref:`ADR-180 <adr-180>`
:Authors: Netresearch DTT GmbH

.. _adr-146-context:

Context
=======

Two writing tools shipped: ``update_page_metadata`` (:ref:`ADR-135 <adr-135>`)
and ``set_file_alternative_text``. Both set named scalar fields on one existing
record. :ref:`ADR-141 <adr-141>` then closed the gap that made them theoretical
— the write fence now arms on every executing segment, not only under a queue
worker's lease — so a third, fourth and fifth writer inherit a fenced execution
path rather than each re-establishing one.

ADR-135 scheduled a review for exactly this moment:

   "The next trigger is the **third** writer — and the question then is whether
   anything the trait deliberately left per tool has become common, not whether
   the trait should grow."

This record adds the three writers and answers that question with what five
implementations actually show.

.. _adr-146-decision:

Decision
========

Three more purpose-built editorial tools, on the terms the first two
established: disabled by default, in the ``editing`` group, with an explicit
:php:`ToolEffect`, a human approval before every call
(:ref:`ADR-134 <adr-134>`), a preview at suspend (:ref:`ADR-136 <adr-136>`), a
write through the ``DataHandler`` under the acting user's permissions
(:ref:`ADR-083 <adr-083>`), a read-after-write verification, and a refusal
vocabulary that never confirms a uid exists.

``move_content_element``
   Moves one content element to a page and a column. **Nothing is created and
   nothing is destroyed**: the record keeps its uid, its content, its language,
   its history and its references. That is what makes it the safest of the
   three, and why it is the only one of them that is idempotent.

   Both ends are authorised with :php:`Permission::CONTENT_EDIT`. Moving an
   element *out of* a page edits that page's content as much as moving one in,
   so one grant is not enough. An ``after_content_uid`` anchor must be on the
   target page and in the same language; a wrong anchor is refused rather than
   silently corrected, because placing the element "somewhere" would be a
   correction of an instruction the model got wrong.

   The destination column is always sent explicitly, through the DataHandler's
   extended paste form
   (``['action' => 'paste', 'target' => …, 'update' => ['colPos' => …]]``), so
   the element lands where the approval card said it would even when the anchor
   sits in a column the caller did not expect.

``create_content_element_draft``
   Creates one content element. The first writer that brings a record into
   being, and every part of it is arranged to keep that commitment small:

   - **Always hidden.** There is no argument to switch it off. Publishing is a
     separate act with a separate audience, and the approval that let the tool
     run approved a draft.
   - **The content type is an allow-list intersected with the live TCA**:
     ``header``, ``text``, ``textmedia``, ``bullets``, and only those an
     installation declares. Types whose payload is configuration rather than
     prose — ``list``, ``html``, ``shortcut`` — are unreachable.
   - **The field set is fixed**: headline, body, column, language, position.

   ``bodytext`` reaches the ``DataHandler`` and its RTE transformation exactly
   as an editor's input does. It is bounded in length and not otherwise
   filtered: an editor may write the same markup by hand, and a tool enforcing
   a rule the CMS does not have would be enforcing a rule nobody agreed to.

``create_translation_draft``
   Localizes one page or content element by running core's own ``localize``
   command rather than copying fields between records. Connected-mode
   translations, the translation parent, inline children and every localisation
   hook are core's business; a second implementation would drift.

   Two things are added on top, and both are the reason the tool exists:

   - **The result is hidden.** ``localize`` copies the source's visibility, so
     a translation of a live page would go live the moment it was created.
   - **An existing translation stops the call**, named in the refusal.
     ``overwrite`` is the only way past it: it deletes that translation through
     the ``DataHandler`` — recoverably (``deleted = 1``) and in ``sys_log`` —
     and the approval card carries that on a line of its own, so an approver
     who skims cannot miss it.

   What is *not* re-implemented: whether the target language exists for the
   record's site, and whether the source is a well-formed default-language
   record. Core's :php:`DataHandler::localize()` checks both. Its permission
   bar is **not** reused — it asks only for :php:`Permission::PAGE_SHOW`, which
   is far too weak for a write, so the tool checks ``PAGE_EDIT`` (for a page)
   or ``CONTENT_EDIT`` (for an element) itself.

.. _adr-146-effects:

The effects diverge, exactly as ADR-135 predicted
=================================================

ADR-135 kept ``getEffect()`` per tool against the argument that "a third writer
may be admin-only or non-idempotent, and a trait answering for it would turn a
decision into an inheritance". That was a prediction. It is now an observation:

=================================  =======================
Tool                               Effect
=================================  =======================
``update_page_metadata``           ``IDEMPOTENT_WRITE``
``set_file_alternative_text``      ``IDEMPOTENT_WRITE``
``move_content_element``           ``IDEMPOTENT_WRITE``
``create_content_element_draft``   ``NON_IDEMPOTENT_WRITE``
``create_translation_draft``       ``NON_IDEMPOTENT_WRITE``
=================================  =======================

The two creations are not repeatable for different reasons, and both matter to
an at-least-once runtime (:ref:`ADR-104 <adr-104>`):

- ``create_content_element_draft`` has no caller-supplied key, so a repeat
  leaves two drafts where one was asked for.
- ``create_translation_draft`` is worse than that. Without ``overwrite`` a
  repeat *refuses*, so a reaped run that already succeeded would report failure
  for a write that happened; with ``overwrite`` it discards a translation a
  human may have started editing between the two attempts. Both are wrong
  answers, and a runtime must not produce either on its own.

Had the trait answered ``getEffect()`` for all writers, the fourth one would
have inherited ``IDEMPOTENT_WRITE`` silently and the reaper would have been
free to double it.

.. _adr-146-review:

The review ADR-135 scheduled
============================

**What has become common:** one thing, and it is a query rather than a
decision. Four of the five writers read a row by uid with only the deleted
restriction. The restriction choice is the same in all four for the same stated
reason: a hidden or timed-out record is still a record an editor may work on.

That answer needed splitting once it was measured. The duplication detector put
the three new tools at 5.1 % new duplicated lines against a 3 % gate, and named
191 lines across them — not only the lookup but the unknown-argument refusal and
the viewer gate, all three written three times in one pull request. Three copies
made in one sitting are copy-paste, not three decisions, so they are extracted
into :php:`PlansOneEditorialWriteTrait`, used by exactly the three tools that
share the shape. The two shipped writers keep their own lookups and are not
touched.

**What has not:** everything else the trait left per tool, and each for the
reason it was left:

- **The neutral refusal string.** Four distinct strings now, because each pairs
  with the shape of what its tool addresses — a page, an asset, a content
  element, a record of either table. A shared string would break that pairing
  four ways instead of two.
- **The authorisation.** Four different decisions:
  :php:`Permission::PAGE_EDIT` on the record itself, ``CONTENT_EDIT`` on its
  page, ``CONTENT_EDIT`` on *two* pages, and a permission that depends on which
  table is being translated.
- **The read-back.** Five different shapes: a field map against a re-read row,
  one column of one record, a position, a created row's identity, and a
  translation's language-plus-parent-plus-hidden triple.
- **The four declarations.** See above — they diverged.

**The decision: WritesThroughDataHandlerTrait does not grow.** The question
ADR-135 posed was what has become common across the writers, and the honest
answer is a row lookup, not a mechanism. That trait carries what all five share;
putting a query into it means either binding it to ``pages`` or adding a generic
getter, and it means editing two shipped, tested writers to route through it.
Neither buys what the trait bought the first time, which was that a *decision*
stopped being made twice.

A second trait for the three tools that genuinely do share a shape is a
different question and costs neither. The two traits are separated by what they
answer: mechanics every writer performs, versus the consequences of resolving
and authorising once for both the write and its preview.

.. _adr-146-plan:

One resolution for the write and the preview
============================================

The three new tools share a shape the first two do not: a private ``plan()``
that resolves and authorises everything once, returning either the plan or the
refusal message, and is called by both :php:`execute()` and
:php:`previewCall()`. :php:`PlansOneEditorialWriteTrait` declares it abstract
and builds on it: the viewer gate is ``plan()`` asked about the viewer instead
of the acting user, which is why one trait can carry it for all three.

It exists because these three have more to get right than a field map. The
approver must read *the destination the write will actually use* — a computed
column, an anchor, a translation that is about to be discarded — and two
implementations of "where does this land" would eventually disagree with each
other in front of somebody about to press Approve.

``update_page_metadata`` and ``set_file_alternative_text`` are **not**
retrofitted to it. They are shipped, tested code, the review ADR-135 asked for
was about the trait rather than about the writers, and rewriting a working
implementation to match a newer one is a change nobody asked for. A sixth
writer should use ``plan()``.

.. _adr-146-consequences:

Consequences
============

✓ Five editorial writes are available where two were, each still one named act
on one record.

✓ The write fence, the approval pause, the preview and the fail-closed audit
apply to all five without any of them arranging it — that is what
:ref:`ADR-141 <adr-141>` bought.

✓ ``NON_IDEMPOTENT_WRITE`` has real consumers for the first time. The retry
decision that reads the persisted effect was previously exercised only by
tests.

✕ ``create_translation_draft``'s ``overwrite`` is genuinely destructive. It is
behind the approval pause, it names the translation it will discard, and the
deletion is recoverable and logged — but a human who approves without reading
loses work.

✕ The row lookup still exists twice — once in each of the two shipped writers,
which this record deliberately does not touch. Only the three new tools share
one.

✕ ``create_content_element_draft`` can create a free-mode element in a
non-default language, which is legitimate but is not a translation. The tool
description says so on the wire and points at ``create_translation_draft``; a
model that ignores both produces an element an editor has to clean up.

.. _adr-146-revisit:

Revisit when
============

A **sixth** writer is proposed, or any writer needs to write more than one
record per call. The one-record rule is what makes every refusal whole and
every preview readable; a tool that batches would need a different answer to
"what did the approver agree to", not a bigger version of this one.

Also revisit if ``NON_IDEMPOTENT_WRITE`` ever produces a reaped run in
practice. The effect is declared and the runtime honours it, but no production
installation has yet had one abandoned mid-creation, so the failure path is
argued rather than observed.

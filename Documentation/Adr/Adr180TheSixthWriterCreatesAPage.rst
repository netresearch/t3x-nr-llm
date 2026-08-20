.. include:: /Includes.rst.txt

.. _adr-180:

============================================================================
ADR-180: The sixth writer creates a page, and the one-record rule holds
============================================================================

:Status: Accepted
:Date: 2026-08-20
:Amends: :ref:`ADR-146 <adr-146>` (its "revisit at the sixth writer" trigger)
:Authors: Netresearch DTT GmbH

.. _adr-180-context:

Context
=======

Five writing tools exist (:ref:`ADR-135 <adr-135>`, :ref:`ADR-146 <adr-146>`).
Two of them bring a record into being — a hidden content element and a hidden
translation — and none of them creates a **page**. A backend assistant asked to
"add a subpage with an introductory text" can therefore draft the text but has
nowhere to put it, and answers that it cannot, which is correct and useless in
equal measure.

ADR-146 named two triggers for coming back to the writers: a sixth writer, and
any writer that needs to write more than one record per call. Both were on the
table at once, because the obvious shape of this tool is "page **and** first
element in one call" — one approval instead of two. This record is the review
that trigger asked for.

The alternative that was evaluated and rejected is wrapping a third-party MCP
server's generic ``WriteTable`` tool into the registry. It is one tool over the
whole TCA — the shape :ref:`ADR-135 <adr-135>` refuses — it writes into a
workspace of its own choosing with no way to publish from here, and as a remote
tool it is admin-only by this extension's own rule. The page writer is a
purpose-built action on the terms every other writer already meets.

.. _adr-180-decision:

Decision
========

``create_page_draft`` is the sixth writing tool, on exactly the terms of the
previous five: disabled by default, in the ``editing`` group, an explicit
:php:`ToolEffect` (``NON_IDEMPOTENT_WRITE`` — two calls leave two pages), a
human approval before every call (:ref:`ADR-134 <adr-134>`), a preview at
suspend (:ref:`ADR-136 <adr-136>`), a write through the ``DataHandler`` under
the acting user's permissions, a read-after-write verification, and a refusal
vocabulary that never confirms a uid exists. It uses the ADR-146 ``plan()``
shape, as that record asked a sixth writer to.

What it creates is as small as a page can be:

- **Always hidden.** There is no argument to switch it off. Core's own default
  for a new page happens to be hidden too, but the tool does not rely on that —
  an installation may set ``TCAdefaults.pages.hidden = 0``, and the read-back
  catches the page that came out visible and deletes it again.
- **Always a standard page.** ``doktype`` is fixed. A shortcut, a mount point,
  a link or a folder carries configuration (a target, a mount, a URL) rather
  than content, and a model that needs one needs a different conversation with
  an editor first.
- **Always the default language.** A page in another language is a translation
  of an existing page and has a tool of its own, ``create_translation_draft``.
- **The field set is fixed:** title, navigation title, position (``parent`` and
  an optional ``after_page_uid`` that must be a subpage of the same parent).
  ``slug`` is left to the DataHandler's generator; ``hidden``, ``doktype``,
  ``fe_group``, ``perms_*``, ``is_siteroot``, ``TSconfig``, ``backend_layout*``
  and every other page field are refused by name.

Authorisation is :php:`Permission::PAGE_NEW` on the **parent**, checked by the
tool and then again by the DataHandler, which for a new page additionally
enforces ``tables_modify``, the page-type grant (``pagetypes_select``) and the
field-level grants. The DataHandler's own permission check on a new page reads
the language field from the incoming record, so the tool states
``sys_language_uid = 0`` explicitly — a non-admin is refused when it is missing.

The editor-action declaration (:ref:`ADR-152 <adr-152>`) names ``pages`` with
``parent`` as the required argument that carries the uid: the record an editor
selects is the page the new one goes under.

.. _adr-180-one-record:

The one-record rule holds
=========================

The tool creates the page and **nothing on it**. "Page plus first element" was
the tempting shape, and it is refused for the reason ADR-146 gave in advance:
the one-record rule is what makes every refusal whole and every preview
readable. An approver reading a card that says "a page *and* an element" is
judging two records with two permission sets in one click, and the refusal
vocabulary — "the whole call is refused rather than partially applied" — would
need a second meaning for the case where the page may be created and the
element may not.

A model that wants both calls ``create_page_draft`` and then
``create_content_element_draft`` on the uid it was given; the page tool's
success message hands over that uid and names the next tool. Two approvals is
the cost, and it is the right cost: each card shows one thing, and the person
approving the element can see the page it goes on.

The mechanism that *does* span records already exists and is not this:
:ref:`ADR-162 <adr-162>`'s batch planner runs one editor action over several
records as N ordinary runs — N approvals, N audits — and is the answer to "ten
pages under this parent", not a bigger single tool.

.. _adr-180-consequences:

Consequences
============

✓ Six editorial writes are available where five were; a backend assistant can
now build a subpage and its first text, each step approved and each step
visible to the approver as one record.

✓ The write fence, the approval pause, the preview and the fail-closed audit
apply without the tool arranging any of it (:ref:`ADR-141 <adr-141>`).

✓ The read-back's safety net is exercised by a test that sets the
installation-level default to visible, so the net is known to hold on the
installation where it matters rather than only on stock core.

✕ Two approvals for "page with text". A person who wants one click per page
will ask for a combined tool; the answer is this record, not a new one.

✕ The page arrives without content and without properties beyond its title,
which is less than the backend's new-page wizard offers. That is the point,
not a gap: the wizard is for editors, who may set everything; the tool is for
a model, which may set what it can be held to.

.. _adr-180-revisit:

Revisit when
============

A writer is proposed that genuinely needs more than one record per call and
cannot be expressed as two tools in sequence or as a batch over one action.
The question then is what the approver agreed to, and it has no answer in this
record.

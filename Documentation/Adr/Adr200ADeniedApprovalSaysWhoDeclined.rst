.. include:: /Includes.rst.txt

.. _adr-200:

============================================================
ADR-200: A denied approval tells the model who declined it
============================================================

:Status: Accepted
:Date: 2026-09-23
:Authors: Netresearch DTT GmbH

.. _adr-200-context:

Context
=======

When a human denies a pending tool call (:ref:`ADR-084 <adr-084>`), the
resume feeds one tool result per pending call back to the model and the
model answers from it. Until now that result read
``Error: tool "update_page_metadata" was denied by the operator.``

On the Netresearch demo a chat user asked for a meta description, pressed
*Deny* on the approval card himself, and was told: "I could not set the meta
description because the write was refused by the system/operator" (demo
conversation 102, run 179: ``approved = false``, ``decidedBy`` = the chatting
user). The model could not know better. "The operator" names nobody, and it
is the one word in the result that says who decided.

Both facts the model needs are already in scope where the result is
written: :php:`ToolLoopService::resume()` receives the decider's backend user
uid, and the execution context carries the run owner, because the resume
runs under the owner's identity (:ref:`ADR-083 <adr-083>`). In the chat the
run owner is the person the model is talking to.

.. _adr-200-decision:

Decision
========

The result of a denied call leads with fixed tokens, then says in plain words
who declined and what the model should tell the user::

    Error: approval_denied (decided_by: run_owner). The approval for tool
    "update_page_metadata" was declined by the user who started this run, the
    person you are talking to. Nothing was executed. Tell them that they
    declined the approval themselves ...

``decided_by`` takes three values, exposed as constants on
:php:`ToolLoopService`:

``run_owner``
    The decider is the backend user the run belongs to.

``other_user``
    Someone else decided — an approver in the AI Tasks module
    (:ref:`ADR-133 <adr-133>`). The model is told "another backend user", not
    a uid: it does not need one to answer, and the person in the chat does not
    need to learn it from the model.

``unknown``
    No decider uid was passed (a bare loop consumer). The result says "the
    human reviewer" and tells the model not to call it a system error.

The ``Error:`` prefix and ``toolIsError = true`` stay, so every consumer that
tells a refusal from an execution by those still does.

.. _adr-200-consequences:

Consequences
============

✓ The model can say "you declined the approval" to the person who did, and
"another user declined it" otherwise. Neither is a guess from prose.

✓ The token is stable text, so a consumer that wants to react to a human
"no" — rather than to a failure — can match it without parsing a sentence.

✕ The result is longer, and it is instruction-like text inside a tool
result. It is written by this extension, not by a tool or a user, so it
carries no injection risk the old sentence did not.

✕ The persisted ``tool`` event carries the new text only at the ``full`` and
``redacted`` privacy levels (:ref:`ADR-064 <adr-064>`); at the default
``metadata`` level the result is stripped as before, and the ``approval``
event's ``approved`` and ``decidedBy`` remain the audit record.

.. _adr-200-revisit:

Revisit when
============

The chat or another consumer needs the reason as structured data rather
than as a tool result the model reads — then it belongs on the
:php:`RunStep` of the refused call, next to :php:`ToolOutcome`.

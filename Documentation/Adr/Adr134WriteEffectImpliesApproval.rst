.. include:: /Includes.rst.txt

.. _adr-134:

============================================================================
ADR-134: A builtin's declared write effect implies human approval
============================================================================

:Status: Accepted
:Date: 2026-08-09
:Authors: Netresearch DTT GmbH

.. _adr-134-context:

Context
=======

Two declarations describe a tool that changes something, and until now they were
unconnected.

:php:`ToolEffectInterface` (:ref:`adr-111`) says what a tool does to the world.
It feeds the write fence, the lease, the audit step and the retry decision — four
readers, none of them about authorisation.

:php:`RequiresApprovalInterface` (:ref:`adr-084`) says a human must approve the
call before the loop executes it. It is the only thing the approval scan in
:php:`ToolLoopService` looks for, and **no production class implements it**: the
sole implementers are two anonymous test classes.

A tool could therefore declare ``NON_IDEMPOTENT_WRITE`` and still run
unattended. The two statements would have to be made in the same commit by
someone who knew both existed, and nothing failed if only one of them was.

The effect declaration is the better of the two to key on. It is a property of
the code and deliberately not configurable (:ref:`adr-111`): an administrator
cannot relabel a write as a read to dodge the audit, which is exactly the
property an authorisation input needs. The marker is an opt-in nobody has yet
opted into.

.. _adr-134-decision:

Decision
========

A tool counts as approval-bound in the loop's approval scan when it implements
:php:`RequiresApprovalInterface`, **or** it implements
:php:`ToolEffectInterface`, its ``getEffect()`` is a write, and it is **not** a
:php:`RemoteToolInterface`.

Both write cases qualify. ``isWrite()`` is the predicate, not idempotency —
whether a repeat compounds governs retry, not whether a human should have seen
the call in the first place.

The check is an :php:`instanceof` on the tool the scan already fetched. No
resolver, no new dependency: nothing here needs the registry-wide view
:php:`ToolEffectResolver` exists to provide, and its unknown-tool fallback
(``NON_IDEMPOTENT_WRITE``) would turn every unregistered name into a suspend
instead of the refusal the invocation path already gives it.

The pre-existing fail-closed rule is unchanged: only an **offered** tool
suspends. A registered-but-not-offered tool named by a model steered through
injected prose still falls through to the invocation gate, which refuses it —
there is no spurious approval prompt for a tool the run never allowed.

Remote tools are exempt
-----------------------

The exemption is load-bearing, not a convenience.

:php:`McpTool::getEffect()` returns ``NON_IDEMPOTENT_WRITE`` for **every**
imported tool, a pure search tool included. That value is a fail-closed
assumption about a body this codebase cannot inspect (:ref:`adr-116`), not the
tool's statement about itself. Treating it as one would suspend every MCP tool
on every call and leave the shipped MCP client unusable.

The remote axis needs a source of its own, and it will not be the server: the
``readOnlyHint`` annotation is stored verbatim for display and read by no
resolver, because a remote server must not be able to influence its own
authorisation. An operator-declared, server-level column is the shape that
works, and it is tracked separately from this decision.

Registration stays as it is
---------------------------

:php:`ToolRegistry` refuses a tool that implements both
:php:`RequiresApprovalInterface` and :php:`RequiresInputInterface`
(:ref:`adr-105`). That ban is **not** extended to effect-declaring tools. It
would be a registration refusal with no reader: the runtime already refuses an
input-requiring pending call on the approval-resume path, fail-closed, because
that path carries no user input. Widening a compile-time ban to cover a case the
runtime already handles buys nothing and would reject a legitimate tool that
needs both a human's data and a human's consent.

.. _adr-134-consequences:

Consequences
============

●● A write that ships without the approval marker still pauses for a human. The
declaration that already had to be right for the audit and the retry now also
carries the authorisation, so the two cannot drift apart.

● Nothing changes for the tools shipped today. Every builtin reads — which
:php:`ToolEffectCoverageTest` pins — so the new branch is inert until the first
writer lands. That test stays the builtin list; its scope over
:php:`ToolRegistry::builtinNames()` is untouched, because a provider-supplied
tool must not be able to satisfy or break a guarantee about code in this
repository.

◐ The reasoning is in one place. A tool author declares an effect for
:ref:`adr-111`'s reasons and gets the human-in-the-loop pause without knowing the
marker exists.

✕ Remote tools remain outside the coupling. An MCP tool that genuinely writes
runs without a pause today, exactly as it did before this decision — the gap is
named rather than closed, and closing it needs the operator-declared server
column.

.. _adr-134-revisit:

Revisit when
============

The operator-declared remote effect column lands. At that point a remote tool
has a statement about itself that did not come from the server, and the
exemption here should narrow to "remote **and** undeclared" rather than
"remote".

Also revisit if a builtin ever needs to write without a pause. Today that is not
expressible, deliberately: the way out is to not declare a write, which the
audit and the retry would immediately make wrong. A real case for a
write-without-approval builtin is a case for a third declaration, not for
loosening this one.

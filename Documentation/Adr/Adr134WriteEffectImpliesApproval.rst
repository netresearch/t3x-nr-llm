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
:php:`RequiresApprovalInterface`; when it is a remote tool whose operator
declaration says so; **or** when it implements :php:`ToolEffectInterface`, its
``getEffect()`` is a write, and it is not remote.

Both write cases qualify. ``isWrite()`` is the predicate, not idempotency —
whether a repeat compounds governs retry, not whether a human should have seen
the call in the first place.

Every check is an :php:`instanceof` or a getter on the tool the scan already
fetched. No resolver, no repository, no new dependency on the loop: nothing
here needs the registry-wide view
:php:`ToolEffectResolver` exists to provide, and its unknown-tool fallback
(``NON_IDEMPOTENT_WRITE``) would turn every unregistered name into a suspend
instead of the refusal the invocation path already gives it.

The pre-existing fail-closed rule is unchanged: only an **offered** tool
suspends. A registered-but-not-offered tool named by a model steered through
injected prose still falls through to the invocation gate, which refuses it —
there is no spurious approval prompt for a tool the run never allowed.

A remote tool's effect is not its consent
----------------------------------------

The effect coupling stops at the remote boundary, and that is load-bearing,
not a convenience.

:php:`McpTool::getEffect()` returns ``NON_IDEMPOTENT_WRITE`` for **every**
imported tool, a pure search tool included. That value is a fail-closed
assumption about a body this codebase cannot inspect (:ref:`adr-116`), not the
tool's statement about itself. Treating it as one would suspend every MCP tool
on every call and leave the shipped MCP client unusable.

The remote axis therefore has a source of its own, and it is not the server: the
``readOnlyHint`` annotation is stored verbatim for display and read by no
resolver, because a remote server must not be able to influence its own
authorisation. It is an operator-declared, server-level column, added below.

The operator declares it, per server
------------------------------------

``tx_nrllm_mcp_server.requires_approval`` is that column, built like
``data_class`` beside it (:ref:`adr-094`): the operator declares it, the server
never does, and there is no code here to derive it from.

It differs from ``data_class`` in having a default, and defaults to
``1`` — approval required. A data class has no safe guess, so an undeclared
server is inert instead. A yes/no does have one: a server nobody has judged
asks first. The reading is fail-closed the whole way down —
:php:`McpServerRecord::approvalRequired()` treats **only** a literal ``0`` as
"no approval", so a missing column, a NULL, an empty string or a value from a
schema this version does not know all come back as "required". The alternative
would be a byte this code cannot read letting an unattended remote write
through.

The flag reaches the scan on the tool, not through a lookup.
:php:`McpToolProvider` already builds every :php:`McpTool` from the very server
record that carries it, so it is a constructor argument, surfaced by
:php:`RemoteApprovalInterface::requiresApproval()`. The scan runs once per tool
call in the loop; giving it a repository would put a query on that path and a
persistence dependency into a class that must not know MCP exists.

:php:`RemoteApprovalInterface` extends :php:`RemoteToolInterface` deliberately.
A free-standing "declare your own approval" interface would quietly make a
write-without-approval **builtin** expressible again, which the last section of
this ADR says is not to be. Extending the remote marker means a class cannot
reach for the declaration without also claiming that its behaviour lives
outside this codebase — the one case in which an operator declaration beats
reading the code.

An existing install is pinned, not converted. The default of ``1`` lands on
every pre-existing row when the schema updates, which would turn a working
integration into one that suspends on every call.
:php:`McpServerApprovalDefaultUpdateWizard` writes an explicit ``0`` on the
servers that were already importing tools (``last_imported > 0``), following
:ref:`adr-113`/:ref:`adr-115`: the new default is the safe one, and the wizard
exists only so that safety is not applied retroactively to something that
already worked. A server that was configured but never imported offers no
tools, so there is no running integration to preserve — it keeps the default,
which is also what keeps the wizard from ever offering to switch approval off
for something new.

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

◐ A remote tool pauses when an operator says so, and never because of what its
effect or its server claims. The judgement an MCP tool cannot supply about
itself is made once, per server, by the person who connected it.

✕ It is a per-server switch, not a per-tool one. A server whose catalogue mixes
a search with a delete is approved on the coarser of the two, and the operator's
only finer instrument is a second server entry. Per-tool declarations would have
to be stored against catalogue rows the import rewrites, and nothing reads them
yet.

.. _adr-134-revisit:

Revisit when
============

A per-tool remote declaration is actually asked for — the coarseness above is a
known consequence, not an oversight, and the catalogue table is rewritten on
every import, so a per-row flag needs a reconciliation rule before it needs a
column.

Also revisit if a builtin ever needs to write without a pause. Today that is not
expressible, deliberately: the way out is to not declare a write, which the
audit and the retry would immediately make wrong. A real case for a
write-without-approval builtin is a case for a third declaration, not for
loosening this one.

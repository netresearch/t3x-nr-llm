.. include:: /Includes.rst.txt

.. _adr-201:

=====================================================================
ADR-201: A consumer can ask which tools a run will not be offered
=====================================================================

:Status: Accepted
:Date: 2026-09-23
:Authors: Netresearch DTT GmbH

.. _adr-201-context:

Context
=======

A model sees the tools it is offered and nothing else. The composite gate
(:ref:`ADR-094 <adr-094>`) drops a tool that is disabled, admin-only for a
non-admin, outside the configuration's tool groups, or above the provider's
trust-zone ceiling — and the model cannot tell that tool from one that does
not exist. Asked for what a dropped tool does, it says there is no way to do
it, and the user is sent nowhere.

On the Netresearch demo a configuration's tool groups held back sixteen
developer tools for six runs (demo conversation 104). Asked "which tools may
we NOT use?", the model answered that it saw no list of blocked tools. The
gate had recorded all sixteen refusals as governance events; nothing handed
them to the model.

:php:`ToolCallPolicyInterface::explain()` already returns a
:php:`ToolPolicyDecision` with the reason for every tool it is asked about.
Called without a list it asks about the globally ENABLED tools only, so a
tool an administrator switched off never appears — and that is the most
common reason a user cannot use one.

.. _adr-201-decision:

Decision
========

:php:`UnavailableToolsResolverInterface` (``@api``, public in the container)
runs the gate over every REGISTERED tool for one configuration and one
backend user and returns the decisions that refuse, each carrying the tool
name and a :php:`ToolDenialReason`: ``toolDisabled``, ``requiresAdmin``,
``configurationGroup`` or ``trustZone``.

The ordering of the gate is unchanged: a tool that is both disabled and above
the ceiling reports ``toolDisabled``, so the list never reveals a trust-zone
axis to a caller an earlier gate already stopped.

A builtin tool's trust-zone refusal while enforcement is in ``observe`` mode
(:ref:`ADR-115 <adr-115>`) is not listed: the tool IS offered in that mode, so
it is available. Enforcing, the same tool is withheld and listed with
``trustZone``. A remote tool never benefits from observe mode — the gate
enforces the ceiling on it in both modes (``ToolCallPolicy::decide()``, per
ADR-115) — so a remote tool above the ceiling is listed with ``trustZone``
whatever the setting says.

Remote tools — the ones an MCP server contributes (:ref:`ADR-116 <adr-116>`),
named ``mcp_*`` — are listed for administrators only. Their names come from
operator configuration rather than from this extension, and an editor has no
use for the catalogue of a server they cannot configure; an administrator
does, to tell a tool the gate holds back from one that is missing. Builtin
tools are listed for everyone.

What the consumer does with the list is its own decision. The chat of
``nr_mcp_agent`` puts it into the system prompt, compact — one name and one
reason per line — so the assistant can answer "there is a tool for that, it is
not enabled for you, ask an administrator".

There is no reason code for a missing record permission. The gate decides per
tool, before any argument exists; whether the user may read or write a given
table or page is decided by the tool itself when it runs, and reported in its
result.

.. _adr-201-consequences:

Consequences
============

✓ A consumer can tell the model what exists and why it is not available,
without re-deriving any gate.

✓ The list and the gate cannot drift: the list IS the gate's output.

✕ The model, and through it the user, learns the names of builtin tools they
cannot use, including admin-only ones and the fact that a trust-zone ceiling
applies. Remote tool names reach only an administrator's model.
Tool names and reason codes are policy facts, not instance data
(:php:`ToolPolicyDecision::message()` says the same of its own text); the
consumer decides whether to pass them on.

✕ One gate evaluation per registered tool per call. The enabled set (two
small queries, the tool-state and group-state overrides) and the
configuration's allow-list are resolved once per call and shared by every
evaluation, which :php:`ToolCallPolicyInterface::explain()` now does for
every caller.

.. _adr-201-revisit:

Revisit when
============

A gate is added that decides per user and per tool before a call — then it
needs its own :php:`ToolDenialReason` case, and this list reports it without
change.

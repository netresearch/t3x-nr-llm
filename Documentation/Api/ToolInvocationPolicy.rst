.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-tool-invocation-policy:

=====================
Tool invocation rules
=====================

An installation may add a :php:`ToolInvocationRuleInterface` implementation to
restrict calls by their final arguments, actor, configuration, target or prior
outcomes. Symfony autoconfiguration registers the rule. Its :php:`identifier()`
must return a unique, stable code matching :php:`[a-z][a-z0-9_.-]*`, at most
64 ASCII characters long.

The existing :php:`ToolCallPolicyInterface` first checks whether the tool remains
permitted. The invocation policy then calls every rule until one denies. A rule
returns :php:`ToolInvocationDecision::allow()` or
:php:`ToolInvocationDecision::deny('outside_site')`. Reason codes use the same
format as identifiers; arguments and exception text never form a denial message.
No rules preserves the existing permission behavior.

The context contains the tool name, final arguments, configuration and
:php:`ToolExecutionContext`. The latter carries the initiating actor, acting
backend user and run reference. Input resumes supply the validated human input
after it has replaced the model's values for declared input fields. Approval
resumes evaluate the same policy immediately before the approved call executes.

Implement :php:`ToolTargetResolverInterface` when the rule needs a target. Return
a :php:`ToolInvocationTarget` with the authoritative kind and identifier, or
:php:`null` for calls the resolver does not understand. Multiple resolvers
claiming a call, or an exception while resolving it, refuse execution. A missing
target remains :php:`null`; a rule requiring a target must explicitly deny it.
Target references are stored in the history, so they must contain identifiers,
never credentials or document content.

:php:`ToolInvocationHistory` is independent of the model transcript. Each
observation contains the tool, outcome and optional target reference. Suspensions
persist it and both resume paths carry it forward. A rule whose
:php:`requiresCompleteHistory()` returns :php:`true` is refused when a legacy or
damaged state cannot prove its history. A bare :php:`runLoop()` call starts a
complete empty history only when it assembles a fresh prompt and all three seed
counters are zero. A call with :php:`skipAssembly` or any nonzero seed counter
must supply authoritative :php:`ToolExecutionContext::$initialInvocationHistory`
to prove its prior observations; otherwise the history remains incomplete.
A rule exception refuses execution as
:php:`rule_failed`. Denials retain the rule identifier and stable reason in the
``invocation_denied`` governance event without arguments or result content. The
event's reason is the bounded rule code, separate from the :php:`ToolDenialReason`
used for ``tool_denied`` events.

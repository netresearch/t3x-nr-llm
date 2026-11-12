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

A refusal may carry an explanation, :php:`ToolInvocationDecision::deny('outside_site', 'Say what to do.')`:
one sentence for the person behind the run, in their language. The refused
call's result passes it on after the codes. It is never recorded.

A rule may also be asked before an approval card is shown, so that nobody is
asked to approve a call it refuses. It opts in by implementing
:php:`PreApprovalInvocationRuleInterface`, a marker without methods, and
promises that its answer comes from the call, the configuration and the
execution context alone, never from the history, that it is the same each time
it is asked about the same call, and that it writes nothing. A call refused
before the card does not wait for approval, so an answer that later turns into
"allow" would let the call run unapproved.
The loop then asks it, through :php:`PreApprovalInvocationPolicyInterface`,
where it decides whether a call suspends: a call it refuses does not suspend
and is refused when it runs, with one ``invocation_denied`` event. The same
rule is asked again immediately before every call executes. It is therefore
asked at least twice for a call that waits for approval, so it has to be cheap.
A rule that does not implement the marker is asked only when the call runs, as
before. The built-in :php:`RequireDraftWorkspaceRule` opts in
(:ref:`editorial-profile`).

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

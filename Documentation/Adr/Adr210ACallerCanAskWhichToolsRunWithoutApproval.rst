.. include:: /Includes.rst.txt

.. _adr-210:

====================================================================
ADR-210: A caller can ask which tools run without approval
====================================================================

:Status: Accepted
:Date: 2026-09-26
:Authors: Netresearch DTT GmbH

Context
=======

:php:`ToolLoopServiceInterface::runLoop()` suspends a run as soon as the
model calls a tool that needs a human approval, and throws
:php:`ToolApprovalRequiredException` with the state to resume from
(:ref:`ADR-084 <adr-084>`). A caller that has no approval step, such as an
editor dialog that runs one task and shows the answer, cannot resume, so for
it the exception is a failed run.

Such a caller wants to offer the model only the tools that never suspend. The
rule that decides this, :php:`ToolApprovalRule::requiresApproval()`
(:ref:`ADR-134 <adr-134>`), is ``@internal``, and so is the registry it needs
the tool from. :php:`ToolCallPolicyInterface` (``@api``) answers which tools
may be offered, but its :php:`ToolPolicyDecision` says nothing about approval.
A consumer is therefore left to re-derive the rule from internal classes, or to
offer every tool and fail when one suspends.

Decision
========

**A new** ``@api`` **interface,** :php:`UnattendedToolFilterInterface`, **with
one method:** ``unattended(list<string> $toolNames): list<string>`` returns the
names, in their order, of the tools that run without an approval.

- It asks :php:`ToolApprovalRule::requiresApproval()` about the tool the
  registry holds under each name. The rule stays the only place that decides,
  so a tool that gains a write effect or the approval marker leaves the
  unattended set without a change here.
- A name the registry does not know is left out. An unknown tool cannot be
  shown to be free of approval, and leaving it in would offer the model a
  call that suspends.
- It filters; it does not decide what may be offered. A caller first asks
  :php:`ToolCallPolicyInterface::filterOfferable()` and then narrows that list,
  so the five policy gates of :ref:`ADR-094 <adr-094>` still apply.

**A separate interface, not a method on** :php:`ToolCallPolicyInterface`.
Adding a method to an ``@api`` interface breaks every class that implements it.
The two questions are also different: the policy answers whether a tool may
take part in a run at all, this filter answers whether it can take part
without a human.

Consequences
============

● A caller without an approval step runs the tool loop on
:php:`ToolCallPolicyInterface::filterOfferable()` narrowed by
:php:`UnattendedToolFilterInterface::unattended()` and never meets a
suspension.

● The approval rule has one reader more and still one definition.

◐ An operator setting that makes a read tool approval-bound, such as the
web page reader's (:ref:`ADR-202 <adr-202>`), removes that tool from the
unattended set too, so a caller without an approval step offers it no
longer. That is what the setting asks for.

✕ The answer is per tool, not per call. A tool whose approval depends on its
arguments would need a finer contract; no tool in this extension has one.

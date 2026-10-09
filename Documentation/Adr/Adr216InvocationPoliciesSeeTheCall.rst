.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-216:

==============================================================
ADR-216: Invocation policies see the call they decide
==============================================================

:Status: Accepted
:Date: 2026-10-09
:Amends: :ref:`ADR-093 <adr-093>` (the execution gate also receives the
    arguments, target and observed history of the particular call)
:Authors: Netresearch DTT GmbH

Context
=======

The existing tool policy decides whether a tool may participate in a run:
registration, availability, the acting user, the run's allow-list and the
provider's trust zone. It cannot distinguish two invocations of that tool.
An installation may allow a metadata writer on one page but deny it on another,
or allow an external lookup until the run has read private material. Those
decisions need the arguments, target and earlier executions.

F13's agent sends such a context to an external policy engine. The useful
boundary is the context and enforcement point. A second service is not needed
for a TYPO3 installation whose rules already live in PHP.

Decision
========

1. **Keep the offerability contract.** ``ToolCallPolicyInterface`` and its
   five gates remain source compatible. A separate invocation-policy contract
   decides a typed context immediately before execution. The decisions compose
   with AND: a new rule cannot make a tool available or undo an existing denial.

2. **Let installations contribute rules.** Tagged PHP invocation rules receive
   the same context and return an allow or deny with a stable, non-secret
   reason. No configured additional rules preserves existing behaviour.
   A configured rule that throws or cannot resolve a required fact denies the
   call. Registration rejects ambiguous rule identifiers rather than choosing
   whichever service the container enumerates first.

3. **Pass facts, not instructions.** The context carries the actual arguments,
   acting identity, configuration, run reference and a target resolved by the
   installation's tool-target resolver. Unknown is an explicit target state;
   it is not a guessed resource or an empty value interpreted as permission.
   Argument rules may examine requested values, but a tool's target resolver
   owns the interpretation of those values as TYPO3 resources or remote
   destinations. A rule requiring a known target denies an unknown one.

4. **History describes execution.** Prior entries name the tool, outcome and
   target that the runtime observed. They come from the runtime's own records,
   not model-provided role messages or the truncated prompt. Failed, denied,
   cancelled and successful invocations remain distinct. A failed write may
   have affected its target, so a conservative sequence rule may count it.
   The policy sees earlier siblings in a multi-call turn in execution order.

5. **One execution point covers every path.** The shared invocation method
   performs the additional gate before remote-call charging and before
   ``ToolInterface::execute()``. Fresh runs, queue workers, approval resume and
   typed-input resume all use it. Resume supplies the final arguments after
   human input was applied and restores the earlier observed history.
   Existing pins, approval, schema, actor and live availability checks remain.

6. **Approval is not policy permission.** A person may approve a pending call
   whose target or rules changed while it waited. The live invocation policy
   still decides when execution resumes; a denial is a tool failure, never an
   execution or a new approval request that silently replaces that denial.

7. **Explain without copying secrets.** A denial reaches the ordinary tool
   result and existing run/governance recording with its rule identifier and
   bounded reason. Policy metadata does not copy arguments, target content,
   credentials or transcript text into another persistent log.

Alternatives considered
=======================

Replacing the existing public policy would break consumers and mix offerability
with facts that only exist after the model proposed a call. Controller checks
would miss queue and resume. Rebuilding history from the model's current prompt
would forget executions when the context window is shortened.

OPA is an optional future adapter when several services genuinely share an
operator-managed policy deployment. It is not required by this contract and is
not introduced by this change.

Consequences
============

The public addition is a context, history/target values, decision, rule contract
and policy facade. Existing tool and offerability interfaces do not gain a
method. New constructor dependencies are optional trailing additions where the
constructor is published. The API snapshot records the additive surface.

Persisted suspended states gain optional policy-history metadata. Old states
without it are recognised; a history-dependent rule cannot treat an incomplete
history as proof that no earlier operation happened. The implementation must
either restore authoritative entries or fail closed for that rule.

Specification: ``specs/014-contextual-tool-invocation/spec.md``. Unit and
functional contracts cover fresh, queued and resumed calls; fuzzy tests cover
the limits of any newly introduced argument or history bounds. ``make gate``
is the final implementation check.

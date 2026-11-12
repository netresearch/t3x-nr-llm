.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-293:

=======================================================
ADR-293: A configuration can require a draft workspace
=======================================================

:Status: Accepted
:Date: 2026-11-12
:Relates: :ref:`ADR-216 <adr-216>`, :ref:`ADR-260 <adr-260>`, :ref:`ADR-261 <adr-261>`, :ref:`ADR-262 <adr-262>`
:Authors: Netresearch DTT GmbH

Context
=======

A run records the workspace its initiator worked in when it started
(:ref:`ADR-260 <adr-260>`), and the write tools write there. Nothing makes a
workspace compulsory. An editor who starts a request in the live workspace gets
live writes, an administrator included, and the approval card is the only
control. An installation that wants every machine change to be a draft the
editor reviews in TYPO3's workspace module had no switch for it.

The invocation rules (:ref:`ADR-216 <adr-216>`) are the place for such a
restriction, but they had two properties that mattered here. No rule shipped
with the extension. And the policy ran only when a call executed, which is
after the approval card: a rule refusing writes in live would have asked the
editor to approve a call that is then refused.

Decision
========

**A setting on the configuration.** ``tx_nrllm_configuration.require_draft_workspace``
(``LlmConfiguration::getRequireDraftWorkspace()``) is off by default. Every
existing record keeps its behaviour. It is an administrator-visible setting on
the access tab, next to the second-approver switch.

**The first built-in invocation rule.** ``RequireDraftWorkspaceRule``
(identifier ``draft_workspace_required``) refuses a call when the setting is on,
the tool's declared effect is a write (``ToolEffectResolver``, as for the
approval card), and the run does not work in a draft workspace. "Works in one"
means the workspace the run recorded is above 0 and the acting backend user the
run resolves to now is set up in exactly that workspace. The test is the
workspace and never the administrator flag, so an administrator is refused in
live like anybody else. Two reason codes tell the cases apart:

- ``live_workspace``: the run recorded live, or recorded no workspace (a run
  from before ADR-260, which writes live only).
- ``workspace_unavailable``: the run recorded a draft workspace, but its user
  has no access to it any more, or the run has no acting user.

Reads, and tools that declare no effect, pass. A rule does not make a tool
workspace-aware: tools that write outside TYPO3's workspaces (MCP servers,
remote agents) declare a write, are allowed once the run is in a draft
workspace, and still write wherever they write. Such tools are taken out of the
configuration with ``excluded_tools`` (:ref:`ADR-261 <adr-261>`).

**An explanation for the person behind the run.** ``ToolInvocationDecision``
gets an optional ``explanation``: one sentence in the acting user's language,
which the refused call's result passes on after the codes. For this rule it
says that the configuration requires a draft workspace, that nothing was
changed, and to switch to a draft workspace in the backend and send the request
again. The texts are catalogue entries (``approvalPreview.workspace.requiredLive``
and ``requiredGone``, English and German). The explanation is never recorded;
the governance log keeps the rule identifier and the reason code.

**Asked before the card, and again when the call runs.** A rule that
implements ``PreApprovalInvocationRuleInterface`` (a marker) may be asked
before an approval card is shown. ``ToolInvocationPolicy::decideBeforeApproval()``
(``PreApprovalInvocationPolicyInterface``) asks only those rules. The loop asks
it where it decides whether a call suspends: a call that the rule refuses does
not suspend, so it runs, and ``invoke()`` refuses it and records one
``invocation_denied`` event. The same decision is taken again right before
every call executes, which includes every call of a resumed run, so a setting
switched on, or a workspace withdrawn, while the run waits refuses the approved
call. Asking before the card records nothing, so a call is refused and logged
once.

Beside a call that does wait, a refused call stays on the card because the turn
is approved as one (:ref:`ADR-132 <adr-132>`). Its preview lines are replaced by
the explanation and flagged as a preview that is no state to compare, which the
resume leaves alone (:ref:`ADR-184 <adr-184>`). Approving the turn writes the
other calls only.

**Only the rules that opted in are asked early.** The history of a turn before
its card is not what it will be when its calls run: nothing of the turn has
executed. A rule that depends on the history (a rule that wants a read before a
write) would refuse early what it allows later. Such a rule does not implement
the marker and is asked when the call runs, as before; the marker is the rule
author's promise that the answer comes from the call, the configuration and
the execution context alone, and that the rule writes nothing.

**What is already there and what is not built.**

- *Tool allow-list.* A configuration has an allow-by-group list
  (``allowed_tool_groups``) and an exclude-by-name list (``excluded_tools``),
  both enforced live and again at resume by ``ToolCallPolicy``. It is not a
  fixed list: a tool registered later inside an allowed group is offered. An
  editorial configuration that must stay fixed names the groups it wants and
  excludes the tools it does not. No per-configuration allow-by-name list is
  built here.
- *Publishing.* ``publish_record`` is a declared write with its own card entry
  and heading; it clears the hidden flag of one row and, under this setting,
  only in a draft workspace, where that is a workspace version. No tool
  publishes a workspace. It can be proposed in the same turn as a draft edit,
  and the turn is approved as one, so the separate control is ``excluded_tools``
  on ``publish_record``, which also keeps the model from offering it.
- *Task scope* (restricting a run to one page and its content) is not part of
  this change. It needs a source of the scope, and nr-mcp-agent sets the
  subject record only for the first turn of a guided process, not for plain
  chats.

Consequences
============

- ● An installation can make draft review the rule for a configuration. A write
  in live is refused before anyone is asked, administrators included.
- ● The refusal tells the editor what to do, in their language.
- ● The first built-in rule exercises ``ToolInvocationRuleInterface`` in
  production, and rule authors get an early-evaluation opt-in without a change
  to the frozen policy interface.
- ◑ A rule that opted in is asked twice per call at the card (once to decide
  whether to suspend, once to describe the card) and once more when the call
  runs. It must be cheap and free of side effects, and give the same answer
  every time: a call it refused at the card does not wait for approval, so a
  later "allow" for the same call would let it run unapproved. The built-in
  rule reads nothing that changes within one round of the loop.
- ◑ A refused call beside a waiting one still appears on the card. It says so,
  but it is on the card.
- ◑ A turn that mixes calls is refused call by call. The model reads each
  call's result and may propose again.
- ◑ Undeclared third-party tools count as reads, as everywhere else, and are
  not refused. Declaring ``ToolEffectInterface`` is the tool author's part.
- ✕ The setting does not make workspace-unaware tools safe. Remote tools write
  where they write.

Net Score: +2

Revisit when
============

- a second rule needs the pre-approval evaluation with a history, which would
  need a history snapshot that is true at the card;
- task scope is built: it needs a resolver for all write tools and a source for
  the scope, and a decision about whether nr-mcp-agent forwards the page of a
  plain chat as the subject record.

Alternatives considered
=======================

**Evaluate the whole policy before the card.** Rejected: a customer rule that
reads the history would refuse early what it allows when the call runs.

**A check inside each write tool.** Rejected: a dozen implementations, none for
third-party tools, and no governance event. The rule is one place and records a
denial the same way as every other.

**Refuse only after the approval, as the policy did.** Rejected: the editor
approves a card, and the answer is a refusal.

**A new gate beside the invocation rules.** Rejected: ADR-216 exists so that
installation restrictions are one chain; a second chain would have two places
to look and two orders.

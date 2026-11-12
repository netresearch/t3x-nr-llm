.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-293:

=======================================================
ADR-293: A configuration can require a draft workspace
=======================================================

:Status: Accepted
:Date: 2026-11-12
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

Reads pass. A write is a declared write effect, or a tool that needs approval
without declaring an effect. A rule cannot make a tool workspace-aware, and a
draft workspace cannot hold what a remote tool writes. So a write by a remote
tool (an MCP tool, a delegation to a remote agent) is refused in every
workspace with the reason ``remote_write``. MCP tools declare a write whatever
they do, so a configuration that needs them stays without the setting. A
third-party tool that declares neither an effect nor an approval counts as a
read, as everywhere else.

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
it where it decides whether a call suspends: a call that the rule refuses on
purpose does not suspend. It is settled apart, and never executed on the
strength of that refusal: the policy is asked as for any call, which refuses and
records one ``invocation_denied`` event; if the rule has changed its mind by
then, the call is refused for the approval it skipped, not run. A refusal that
reports a failure of the policy (``rule_failed``, ``history_incomplete``,
``policy_failed``) is no refusal here: the call waits for approval like any
write and is asked again when it runs. The same decision is taken again right
before every call executes, which includes every call of a resumed run, so a
setting switched on, or a workspace withdrawn, while the run waits refuses the
approved call. Asking before the card records nothing, so a call is refused and
logged once.

Beside a call that does wait, a refused call stays on the card because the turn
is approved as one (:ref:`ADR-132 <adr-132>`). Its preview lines are replaced by
the explanation, and the stored preview carries a ``refused`` tag. The inbox
card marks the call (``PendingCallView::$refusedByRule``), shows the explanation
to every viewer, who may hold no permission on the record, and offers no
structured target or preview of a write that will not happen. A refusal without
an explanation gets a generic line, so the card never offers such a call as an
ordinary one. What the
approver read is an explanation, not a preview of what the call would do, so the
resume refuses a tagged call unconditionally and records ``invocation_denied``
with the reason ``refused_on_card``, whatever the rule says by then. This is the
variant of :ref:`ADR-262 <adr-262>` and ADR-266, where a call the card showed
refused stays refused. The other variant, a fresh preview and a new approval
when the rule now allows, would ask the editor to approve a call after the
assistant had been told it is refused. Approving the turn writes the other
calls only.

**Only the rules that opted in are asked early.** The history of a turn before
its card is not what it will be when its calls run: nothing of the turn has
executed. A rule that depends on the history (a rule that wants a read before a
write) would refuse early what it allows later. Such a rule does not implement
the marker and is asked when the call runs, as before; the marker is the rule
author's promise that the answer comes from the call, the configuration and
the execution context alone, and that the rule writes nothing. Nothing depends
on the promise for safety: a call refused before the card is never executed
without an approval, whatever the rule answers later.

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
  runs. It must be cheap and free of side effects.
- ◑ A call the rule refused at the card and then allows is refused rather than
  run, and the assistant may have to propose it again.
- ◑ A refused call beside a waiting one still appears on the card. It says so,
  but it is on the card.
- ◑ A turn that mixes calls is refused call by call. The model reads each
  call's result and may propose again.
- ◑ Undeclared third-party tools without an approval count as reads, as
  everywhere else, and are not refused. Declaring ``ToolEffectInterface`` is the
  tool author's part.
- ◑ A configuration under the setting cannot use MCP tools at all.
- ✕ Writers outside the assistant's tool loop, such as the field fill writer,
  do not consult the setting. The writers of tables that are not versioned (redirects)
  refuse in a draft workspace and are refused by this rule in live, so under the
  setting they never run; take them out of the configuration.

Net Score: 0

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

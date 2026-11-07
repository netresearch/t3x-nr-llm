.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

===============================
ADR-214 historical consequences
===============================

This appendix preserves the decision and its amendments. For present use,
follow :ref:`adr-214` and its current guide links.

.. _adr-214-alternatives:

Considered alternatives
=======================

**Keep the untrusted frame for every skill and add a separate "tour" record.**
A new record type would hold the steps and be composed as instructions.
Rejected: it creates a second kind of reusable prose next to skills, with its
own source, review and audit, and it still needs the approval, loading and
pinning of this decision for that record.

**A trust flag without version binding.** Mark a skill or a source as trusted
once. Rejected: the next sync or the next backend edit would turn new text into
instructions without anyone reading it. ADR-035 already disables an enabled
skill whose body changed, for the same reason; an instruction is a stronger
privilege than being enabled.

**A checksum over the body alone.** Today's value. Rejected: the frontmatter
carries the tool allow-list, the section heading and the catalogue text, and
an upstream change to it would keep a skill approved and enabled.

**A versioned value in the existing checksum column.** Rejected: the column is
``varchar(64)``, and the manifest fingerprint and the legacy check still need the
body-only hash. A separate column keeps both meanings.

**A revision table with every version.** Rejected in favour of the approval
snapshot: only approved versions are ever composed as instructions or pinned,
and the snapshot holds exactly those. Backend edits are in ``sys_history``.
Unapproved synced versions are not kept, as today.

**Checking the provenance threshold once, at approval.** Simpler on paper.
Rejected: a source downgraded after approval would keep instructing, and the
check at compose and resume is one comparison per source.

**Limiting revocation to new runs.** Rejected; see
:ref:`item 6 <adr-214-d6>`.

**Pins carried in the request.** The continuation request listing the pins
it holds, as the second version of this record had it. Rejected: an ``@api``
caller could hand in any approved version, unattached, disabled, a process
without its invocation, or an older digest. nr_llm derives the pins from the
predecessor run instead.

**Releasing a process run in the Agent Runs inbox.** The earlier versions of
this record let an inbox release reach the chat through a hand-back, and
every review round since found a new defect in that path: the hand-back
missed new messages, a cancel raced with the release, several approvals in
one run were told apart wrongly, and open points under four-eyes never
closed. Rejected: process runs are decided only on the chat card, and
four-eyes tours are left to a later decision.

**Progress and highlight only in an earlier turn.** Keeping ADR-084's
suspend-before-any-call rule and asking the process skill to report progress
in a turn of its own. Rejected: it depends on the model following prose, and
the decision moves process state into tools precisely to avoid that.

**Loads that last one turn.** Defining a load as valid for the run that made
it, so the model loads again on every chat turn. Rejected: the model would
re-load at whatever digest is current, so a tour could change version
mid-conversation, and every turn would pay the load again.

**Admitting a section only with every current turn kept.** The first version
of this record. Rejected: every chat turn is a new run carrying the whole
history, so a long tour would fail permanently once its history passed the
headroom, although ``fit()`` could still send it.

**A denial reason per call.** Letting one card carry a reason for each of
several write calls. Rejected for process runs: an approval decides the whole
turn (ADR-132), and one proposal per card is also what the editor reads.
One write per turn keeps the existing turn semantics.

**Let the model load any skill, trusted or not.** Simpler, and the usual shape
of skill systems. Rejected: the model reads page content, and a page could ask
it to load a skill the editor never chose. For an untrusted skill that is
fenced text and mostly cost; for a process it would start a flow by content
injection.

**Deferring model loading, the catalogue and load modes.** Suggested to keep
the first version small. Rejected by the product requirement: a skill must be
reachable both by slash command and by the model matching its description.

**Reply buttons as an input pause, followed by the write approval.** Rejected:
two pauses for one decision, and the variant that collects the answer in the
approval pause is what ADR-134 bans. Approve-means-apply with an enumerated
denial reason is one pause.

**Admin-only TCA for snippets, or a mark on the record.** Rejected; see
:ref:`item 8 <adr-214-d8>`.

**A workflow engine.** Steps, transitions and state as data, with the model
filling in the text. Rejected: it is a new runtime next to the agent loop,
and every process would need a schema change rather than a text change, which
is what the product owner asked to avoid. The run's existing suspensions
(ADR-105, ADR-134) cover the interaction points.

**One extension per process.** Rejected by the product owner: every process
would need a release and a deployment, and editors could not adjust one.

**Processes as prompt snippets.** Snippets are always on, unversioned and
unapproved. Rejected for processes; kept for shared context
(:ref:`item 8 <adr-214-d8>`).

.. _adr-214-consequences:

Consequences
============

● Editors and administrators write a process as a skill in the backend, see
its history in ``sys_history`` and the diff against the last approved
version, and an approved version guides the chat without a release.

● The instruction privilege is bound to a digest over everything the model
reads of a version and to the source that vouches for it. A changed body or
frontmatter, from a sync or an edit, or a move to another source, loses it
until someone with the approval permission approves again, and every
approval and revocation is in the append-only audit.

● A conversation cannot silently continue with an instruction that was
revoked, detached or replaced: every instruction section is pinned, derived by
nr_llm from the predecessor run, and checked at start, pickup, resume and
continuation. No caller can hand a pin in.

● A loaded skill cannot widen the tool allow-list, a backend author cannot
widen it without an approval, and a resumed run cannot gain tools through the
skill allow-list.

● Instruction sections are no longer cut from the tail and are admitted
against the smallest send ``fit()`` can reach, so a section that does not fit
is refused visibly and a long tour does not strand on the budget. A tour
whose last run was purged by retention cannot be continued; the editor is
told to start a new conversation.

● Marked snippets, such as an editorial profile, reach the chat on
configurations that opt in, and only in the text an administrator marked.

◐ The governance profiles gain an expectation for the new threshold. Proposed:
``verified`` for ``local_only``, ``controlled_cloud`` and ``development``,
``first_party`` for ``enterprise_strict``. ``skills.minTrustLevel`` keeps its
default and its profile values.

◐ **Upgrade note.** What changes on an existing installation, and what does
not:

- No approval exists after the upgrade, so every skill keeps the fenced frame.
  Existing attachments migrate to ``always``, so no catalogue and no load
  tool appear on existing configurations.
- The snippet path changes nothing until an administrator marks a snippet and
  opts a configuration in.
- Legacy rows keep verifying against ``body_checksum`` until the sync or the
  upgrade wizard writes ``version_digest``. The first sync compares the
  new-form digest of the stored fields with the incoming one, so an unchanged
  skill is not disabled and a frontmatter change is caught. The wizard
  disables and audits a synced row whose stored ``name``, ``description`` or
  ``allowed_tools`` no longer match its ``raw_frontmatter``.
- After the migration, an upstream change to a skill's frontmatter alone
  disables an enabled skill, which today it does not. Expect more re-reviews
  for sources that change their frontmatter.
- Forced skills' ``allowed-tools`` now count for the run they are forced onto;
  today they count for nothing. The change goes both ways: when the
  configuration's skills declare nothing, a declaring forced skill narrows the
  run from every tool to its list; when they declare a list, the forced
  skill's tools are added to it. Forcing is an administrator's act in the
  playground, so the widening follows the rule that attachment grants tools.
- A backend edit of a synced skill's ``name`` or ``description`` now fails the
  integrity check, like a body edit; FormEngine shows those fields read-only
  for synced sources.
- Eight fields gain ``exclude => true`` (:ref:`item 3 <adr-214-d3>`). A
  non-admin group that was granted ``tables_modify`` on the skill tables loses
  write access to them until an administrator grants the fields; the
  extension ships those tables in an admin-only module (ADR-035 item 7), so no
  shipped surface changes.
- A process run that requests two approval-bound calls in one turn gets an
  error for the second; its reads execute before the card appears, and any
  other call gets an error and does not run. Outside process runs nothing
  changes.
- In a process tour, a new chat message while a card or an input pause
  waits withdraws it: the waiting run is cancelled and the proposal shown as
  discarded, with no open point. Only "Überspringen" on the card records an
  open point. Runs of ordinary chats, without a process pin, are not
  cancelled and stay releasable in the inbox, as today.
- Process runs appear in the Agent Runs inbox read-only and cannot be decided
  there, by administrators neither.
- A process skill cannot be started on a configuration with
  ``require_second_approver`` set.
- A backend skill that was never approved contributes a declared empty tool
  list. Attached to a configuration whose other skills declare nothing, it
  turns off every tool except the load tool until a version is approved. That
  is the price of never letting unreviewed text grant or lift a restriction.

✕ **An approved skill is an instruction with the acting user's reach.** It can
steer which tools the model calls and with what arguments, within the run's
allow-list and the user's TYPO3 permissions. The controls that bound it are
the approval of the digest, the allow-list fixed at run start and the global
tool state, and the write approval of ADR-134, which no skill can switch off.
Approving a version is therefore a privileged act and gets its own
permission.

✕ **Content can still ask the model to load a skill.** The catalogue lists
only approved instruction skills that are not processes, and the load tool
re-checks each at load time, so the worst outcome is approved text in the
wrong place, not foreign text as instructions, and no change to the tool
allow-list. Message role remains defence in depth, not a trust boundary
(ADR-036): an untrusted, fenced skill can still influence output, as today.

✕ **Manifest fingerprints cover identifier/body pairs only.** They are not
upstream signatures. Frontmatter is covered by the version digest and approval;
the expected manifest digest still needs a trusted delivery channel.

✕ The work spans nr_llm (digest column and migration, compose path, approval,
load tool, run-start allow-list, pins and continuation, budget charge,
snippet path, denial reason, one-approval rule, guarded cancel, the
process-run decider and four-eyes guards, ``pendingTarget()``, the write
completeness on every write tool and the loop-level hook-failure flag on the
write step, progress, highlight and choice builtins,
catalogue API, actor-scoped access check) and nr_mcp_agent (slash
commands, the approval card's three answers, the input pause, naming the
predecessor run, the withdraw order in ``queueTurn()``, progress, highlight,
open-point table and listing tool). The process use case works only when
both ship.

.. _adr-214-open:

Open questions
==============

- **The approval permission.** :ref:`ADR-117 <adr-117>` withdrew nr_llm's own
  capability checkboxes, and :ref:`ADR-169 <adr-169>` routes record
  management through TYPO3's permission model. Which mechanism carries "may
  approve a skill version" is open; until it is decided, administrators only.
- **Who may author backend skills.** ADR-035 item 7 keeps skills in an
  admin-only module, and ADR-169 keeps ``tx_nrllm_skill`` out of non-admin
  management because the sync writes it. Backend-authored skills are written
  by people, so that has to be revisited for the new source. The fields that
  must not travel with ``tables_modify`` — on the skill and on its source —
  are the eight that :ref:`item 3 <adr-214-d3>` excludes; whether more belong
  there is part of that revisit.
- **Names.** ``skills.instructionTrustLevel``, ``version_digest``, the
  ``process`` frontmatter key, the load modes, the snippet mark, the
  configuration opt-in, :php:`ApprovalDenialReason`, ``cancelIfWaiting()``,
  ``pendingTarget()``, :php:`WriteCompleteness` and ``hookFailedAfterWrite`` are
  working names.
- **Catalogue size.** Whether the catalogue needs its own byte cap, or the
  admission rule of :ref:`item 7 <adr-214-d7>` covers it.
- **Headroom.** Whether 10 % of the budget is the right reserve, measured on
  the first processes that run.
- **Flipping existing attachments to on_demand.** Whether and when existing
  ``always`` attachments move to ``on_demand``, which changes what an existing
  configuration sends.
- **Four-eyes tours.** Whether and how a process can run on a configuration
  that requires a second approver: the second person would have to decide on
  a surface other than the chat card, which this decision forbids for
  process runs.
- **Process completion without a report.** A tour the model abandons without
  a completion report keeps its process pin until the conversation ends or
  the configuration changes. This original question was resolved by :ref:`ADR-228 <adr-228>`:
  ``AgentRuntimeInterface::endProcess()`` explicitly ends the process pin.

.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

===========================
ADR-214 historical decision
===========================

This appendix preserves the decision and its amendments. For present use,
follow :ref:`adr-214` and its current guide links.

.. _adr-214-decision:

Decision
========

**A skill version that an authorised person approved is composed as an
instruction. The approval binds to a digest over everything the model reads
of that version and to the source that vouches for it. Skills can be written
in the backend, are listed in context and loaded when needed, and a
conversation keeps the versions it started with until they are revoked.
Process state and every write go through tools and the run, not through the
prompt.** No new process engine is built; a process is a skill. None of this
is implemented at the time of writing; the records it amends keep describing
the current code until the implementing changes land.

.. _adr-214-d1:

1. One digest names one version
-------------------------------

**The version digest is a sha256 over the body and the normalised
frontmatter fields** — every field that composition, the catalogue or
:php:`AllowedToolsResolver` reads: ``name``, ``description``, ``body``,
``support_status``, ``allowed_tools`` and the process marker of
:ref:`item 6 <adr-214-d6>`. The input is a canonical serialisation of those
fields under a format version: fixed key order, ``allowed_tools`` as ``null``
(no declaration) or as a sorted list without duplicates, after the same string
splitting :php:`SkillSyncService::apply()` applies. ``raw_frontmatter`` is not
hashed as such, because its JSON depends on key order; a frontmatter key that
nothing reads does not change the version. A field that a later change starts
to read joins the digest under a new format version.

**The digest lives in a new column** (working name ``version_digest``,
holding the format version and the hex value). ``body_checksum`` is a
``varchar(64)`` (``ext_tables.sql``) and keeps its body-only meaning, because
two readers still need exactly that: the manifest fingerprint and the legacy check
below.

**The same digest is used wherever this extension compares versions:**

- the sync's change test, so a frontmatter-only change is a change: an enabled
  skill is disabled and audited as ``ingest_disabled_on_change``
  (ADR-035 item 5), and no approval matches the new digest;
- the compose-time integrity check of a synced skill, which recomputes the
  digest from the stored fields and compares it with ``version_digest``
  (ADR-036 item 6, now covering the frontmatter fields as well);
- approvals, revocations and pins (items 2, 6).

**The manifest fingerprint is the exception.** :php:`SkillSyncService` builds the
per-source manifest from body hashes
(``Classes/Service/Skill/SkillSyncService.php#$manifest[$sourcePrefix . $parsedSkill->path] = hash('sha256', $parsedSkill->body)``),
and :php:`SkillManifestVerifier` compares it with the administrator's expected
SHA-256 digest (ADR-061). This checks identifier/body pairs, not a public-key
signature or publisher identity independently of the delivery channel.
Frontmatter is covered by the version digest and approval, not that manifest.

**Stored rows migrate without a mass re-review and without a blind window.**
A row with an empty ``version_digest`` is a legacy row. Compose keeps
verifying it against ``body_checksum`` as today, and it can never be an
instruction, because no approval names it.

- **The first sync after the upgrade compares like with like.** For a legacy
  row it first computes the new-form digest from the stored fields, then
  compares that with the digest of the incoming version. A frontmatter change
  upstream — a widened ``allowed-tools`` included — is therefore a change on
  the very first sync and disables an enabled skill; an unchanged skill is
  not disabled merely because its row was legacy.
- **The upgrade wizard does not bless earlier edits.** ``name`` and
  ``description`` are editable in FormEngine today
  (``Configuration/TCA/tx_nrllm_skill.php#'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.name'``),
  so the stored fields of a synced row may differ from what the sync wrote.
  Before it writes ``version_digest``, the wizard verifies the body against
  ``body_checksum`` and re-derives ``name``, ``description`` and
  ``allowed_tools`` from the stored ``raw_frontmatter``, which the sync wrote
  and FormEngine shows read-only. A row that fails either check is disabled,
  audited and left without a digest until the next sync rewrites it.

The synced ``name`` and ``description`` become read-only in FormEngine for
synced sources, as the body's checksum already is: an edit to any of them now
fails the integrity check, as a body edit does today.

.. _adr-214-d2:

2. The trust level decides the framing, and trust binds to one digest and one source
------------------------------------------------------------------------------------

**Two conditions make a skill an instruction:** its provenance level
(:php:`SkillTrustLevel`) is at or above a new threshold, and an approval
exists for its current digest and its current source and is not revoked. The
threshold is a new extension setting (working name
``skills.instructionTrustLevel``), default ``verified``. A value below
``skills.minTrustLevel`` is read as ``skills.minTrustLevel``, since a skill
that is not admitted cannot instruct.

- **The provenance level for this check is read from the source record**
  (``tx_nrllm_skill_source.trust_level``), for every source type, not from the
  column the sync denormalises onto the skill. A re-classified source takes
  effect at once instead of at its next sync.
- **Admission reads the source record too, for backend-authored skills.**
  :php:`SkillComposer::effectiveSkills()` admits by the denormalised
  ``tx_nrllm_skill.trust_level``, whose only writer is the sync
  (``Classes/Service/Skill/SkillSyncService.php#$skill->setTrustLevel($source->getTrustLevel())``)
  and whose TCA default is ``untrusted``. A backend-authored skill has no sync,
  so under every governance profile above ``development`` it would never be
  admitted. For the backend source, admission therefore reads the source
  record's level; the synced sources keep reading the denormalised column
  (ADR-061 item 1).
- **The threshold is checked whenever an instruction is composed** and at
  every check of a pin (:ref:`item 6 <adr-214-d6>`), not only when the version
  is approved. It is one enum comparison per source.
- **An instruction skill is composed into the system message,** as a labelled
  section after the configuration's own prompt and the snippet block, without
  the guard preamble and the fence. The model reads it as instructions of the
  installation.
- **Every other admitted skill keeps today's frame:** first user message,
  guard preamble, ``BEGIN/END UNTRUSTED SKILL DATA`` fence, fence markers in
  the body neutralised.
- **Provenance and approval stay separate fields.** The provenance level is
  what a source is (ADR-061, set on the source). The approval is a statement
  about one digest from one source. "Reset to untrusted" means "no unrevoked
  approval matches"; the provenance level is never rewritten for it.

**An approval row is the snapshot of the version it approves.** It holds the
skill uid, the source uid, the digest, the body and the normalised fields that
went into the digest, the provenance level at the time, the approver and the
time. Binding the source means that moving an approved skill to another
source — a ``first_party`` one, or the backend source, which has no
stored-value integrity check — leaves it without a matching approval. No
separate revision table is kept: the snapshot is what a pinned run composes
(:ref:`item 6 <adr-214-d6>`), and it is what the approval form diffs the
current version against.

**The approval request carries the digest the approver saw.** The approval
form shows the current version and its diff against the most recent approved
snapshot, and it posts the digest it rendered. The server recomputes the
current digest and refuses the approval when the two differ, then shows the
form again with the new state. This is the rule of ADR-184 applied to a skill
version: an approval is a decision about the state it showed.

**Revocation is per digest and wins.** A revocation names one skill and one
digest. A revoked digest becomes an instruction again only through a new,
explicit approval of that digest, never because a sync or an edit reproduced
the same bytes. A digest that was approved and never revoked is an
instruction again when a later edit or sync reverts to exactly that version:
it is the same text someone approved.

**Approving needs a dedicated permission,** separate from the right to edit or
enable a skill. Until the mechanism is decided (see
:ref:`open questions <adr-214-open>`) only administrators can approve or
revoke. **Every approval, revocation and refused approval is audited** in
``tx_nrllm_skill_audit`` (ADR-061 item 5) as new :php:`SkillAuditEvent` cases
carrying the digest, with the same append-only guarantee.

.. _adr-214-d3:

3. Skills can be written in the backend
---------------------------------------

**A further source type holds skills authored in the backend,** next to the
GitHub source types, which stay as they are. A backend-authored skill has the
same fields and the same compose path. Its provenance level is set on its
source record like any other source's, and both admission and the instruction
threshold read it there (:ref:`item 2 <adr-214-d2>`).

- **The digest of a backend-authored skill is computed, not stored and
  trusted.** Compose and the approval form compute it from the record's
  current fields and match it against the approvals. There is no stored-value
  comparison for this source, because an edit is the expected way the record
  changes. The stored-value check of :ref:`item 1 <adr-214-d1>` stays for the
  synced sources, where the sync is the only legitimate writer and a backend
  edit is the tampering it exists to catch. No write path has to maintain the
  digest of a backend skill, so nothing relies on a DataHandler hook, which
  Extbase writes would skip (:ref:`ADR-169 <adr-169>`, section 3).
- **Authoring goes through FormEngine and the DataHandler.** That gives the
  record TYPO3's ``sys_history`` (who changed what, when, with a diff and a
  rollback) and the ``exclude`` boundary. Any nr_llm controller action that
  writes the content of a backend skill uses the DataHandler, not an Extbase
  repository.
- **A backend skill's tools come from its approved snapshot.**
  :php:`AllowedToolsResolver` reads the live ``allowed_tools`` declaration.
  For a synced skill that value is held by the integrity check and by the
  disable-on-change of the sync. A backend skill has neither: an author could
  widen the run's tools with an edit that needs no approval. A backend skill
  therefore contributes the ``allowed_tools`` of its most recent unrevoked
  approved snapshot, not the live field. An edit to an approved backend skill
  thus keeps the approved tools until the new version is approved. A backend
  skill that was never approved, or whose approvals are all revoked,
  contributes a declared empty list: it grants nothing, and it still counts as
  a declaration, so it can never leave a run unrestricted. A skill the run
  holds a pin for follows the pin rule of :ref:`item 5 <adr-214-d5>` instead.
- **Eight fields are excluded** with ``exclude => true``. On
  ``tx_nrllm_skill``: ``trust_level``, ``body_checksum``, ``version_digest``,
  ``enabled``, ``allowed_tools`` and ``source``. On ``tx_nrllm_skill_source``:
  ``trust_level`` and ``type``. The implementing change excludes more fields
  that decide the same things: on the skill ``hidden``, ``support_status``,
  ``orphaned`` and ``raw_frontmatter``; on the source ``hidden``, ``enabled``,
  ``url``, ``ref``, ``pinned_sha``, ``expected_fingerprint`` and
  ``github_token`` (``specs/012-approved-skill-versions/spec.md``, item 11).
  ``readOnly`` keeps a field out of FormEngine; ``exclude`` keeps it out of a
  DataHandler write by a group that holds ``tables_modify`` but was not granted
  the field. ``source`` and the source ``type`` are excluded because moving a
  synced skill onto the backend source, or turning its source into a backend
  source, would switch off the stored-value integrity check; the approval's
  source binding of item 2 covers the case where an administrator does it.
  The approval rows and their audit are written only by the approval action,
  which is administrator-only until the permission is decided.

The approval rule of :ref:`item 2 <adr-214-d2>` applies unchanged: an author
who may edit a skill cannot thereby make it an instruction.

.. _adr-214-d4:

4. Skills load on demand: by slash command and by the model
-----------------------------------------------------------

**Enabling stays per skill.** The ``enabled`` flag of ``tx_nrllm_skill`` is
the site-wide switch, the counterpart of ``tx_nrllm_tool_state`` for tools;
attachment decides which configuration offers it.

**An attachment carries a load mode,** ``always`` or ``on_demand``. Existing
attachments migrate to ``always``, which is today's full composition; new
attachments default to ``on_demand``. The implementing change stores the mode
in the attachment table and shows it as two fields of the configuration,
"Skills loaded on demand" first and "Skills loaded always", because FormEngine
cannot edit a column of a select relation's MM row; the "default" is
therefore the field order rather than a value, the column default ``always``
is the migration, and ``LlmConfiguration::addSkill()`` keeps attaching
``always`` for existing callers. Task attachments carry no mode: a task stays
one prompt and one answer (:ref:`item 11 <adr-214-d11>`) and has no loop that
could load (``specs/012-approved-skill-versions/spec.md``, section (c)).

**A skill is named by its uid, not by its identifier.** Identifiers are unique
only per source; :php:`SkillComposer` already keys skills by source and
identifier because cross-source twins exist. Invocation, the load tool and the
catalogue use the skill uid; the identifier is display text.

**A body reaches a run in one of three ways:**

- **Always.** An ``always`` attachment is composed at run start, as today: an
  instruction skill as a system section, any other skill in the fenced block.
  A process skill is never composed this way (:ref:`item 6 <adr-214-d6>`).
- **Explicit invocation.** The caller passes a skill uid when it starts or
  continues a run, from a slash command or a button
  (:ref:`item 10 <adr-214-d10>`). Any enabled skill attached to the
  configuration can be invoked, in either load mode. An instruction skill
  becomes a system section, any other skill goes into the fenced block.
- **Loading by the model,** through a dedicated read-only tool that takes a
  skill uid from the run's catalogue. The catalogue lists the ``on_demand``
  attachments whose current version is an instruction and which are not
  process skills: uid, name and description, from the approved version, so
  every catalogue text is covered by the digest someone approved. Page
  content, search results and tool output are untrusted and can ask the model
  to load something; restricting the catalogue to approved versions means the
  worst such a request achieves is adding text the installation already
  approved. A skill that is not an instruction never appears in the model's
  catalogue, so no third-party description sits outside the fence. The tool
  result confirms the load and does not carry the body: the loop writes the
  section into the run's system message before the next model call, so an
  instruction never travels in the tool role.

**The load tool re-checks at load time.** It refuses a uid that is not in this
run's catalogue, and it re-checks every condition of the catalogue at the
moment of the load: still attached ``on_demand``, ``enabled``, not orphaned,
not a process, and an instruction (an unrevoked approval for its current
digest and source, provenance at or above the threshold). A catalogue built
at run start is a list of candidates, not a grant.

**The catalogue and the load tool are offered only when the run's catalogue
is not empty,** which needs at least one ``on_demand`` attachment. A
configuration whose attachments are all ``always`` — every configuration
after the upgrade — gets neither, so existing runs send what they sent before.

**Loading is idempotent per skill and digest.** A load or an invocation of a
skill whose section the conversation already holds, whether by an ``always``
attachment, an invocation or an earlier load, appends nothing and returns
"already loaded". A skill whose current digest differs from the one the
conversation holds is refused: it keeps the version it has
(:ref:`item 6 <adr-214-d6>`).

**One rule for the tail drop.** An instruction section is never dropped from
the tail, whether it came from an ``always`` attachment, an invocation or a
load; the budget rule of :ref:`item 7 <adr-214-d7>` admits it or refuses it.
Every fenced skill, however it reached the run, stays in the fenced block,
which ``skills.maxBytes`` keeps bounding by dropping from the tail as
:php:`SkillComposer::composeBlock()` does today (ADR-036 item 5).

.. _adr-214-d5:

5. The skill allow-list is fixed at run start and can never gain tools
----------------------------------------------------------------------

**The run's skill allow-list is resolved once, at run start, over every
effective skill attached to the run** — enabled, not orphaned and admitted, as
:php:`SkillComposer::effectiveSkills()` selects them: the configuration's
attachments in both load modes, the forced skills of :php:`RunAugmentation`,
and the invoked skill. The load mode does not matter: under the union
semantics of ADR-038 item 5, which stay, an ``on_demand`` skill's declaration
grants its tools from run start, before its body is loaded, and loading it
later changes nothing. This is the trade-off taken: **attachment grants
tools, a load grants nothing.** Attaching or forcing a skill is an
administrator's act; a load is something content can ask for. Content that
talks the model into loading skill B therefore cannot grant tools the run did
not already have. What changes against ADR-038 is the set the union is taken
over, and that it is taken once. A process skill is effective here only in a
run that invokes it or holds its pin: attached or forced without an
invocation it contributes nothing, no tools and no restriction
(:ref:`item 6 <adr-214-d6>`).

**The resolved list is stored with the run** — on the run request a queued
run persists and in :php:`SuspendedRunState` — and :php:`ToolCallPolicy`
receives it instead of resolving the configuration again. ``decide()`` and
``explain()`` take the run's list as an argument; a caller with no run (the
tool explanation in the backend) keeps today's configuration-only resolution
and says so.

**A resume intersects, it never re-derives from the configuration alone.**
The ADR-165 re-gating at resume resolves the live list over the run's own
skill set — the configuration's attachments plus the forced and invoked
skills, re-read by the uids the suspended state stores, the way ADR-165
already re-reads forced uids — and intersects it with the stored list, where
``null`` imposes nothing. A forced skill's tools therefore survive every
resume, the stored list stays the upper bound, and a live ``null`` caused by
a disabled declaring skill no longer widens a resumed run to every tool. A
change while the run waits can take tools away or leave them, but can never
add one through the skill allow-list. The other gates are re-read at resume
as today: a tool switched on globally while the run waited is offered if the
skill list admits it (:ref:`ADR-039 <adr-039>`).

**A pinned skill contributes from its pinned snapshot.** A skill the run
holds a valid pin for (:ref:`item 6 <adr-214-d6>`, which requires the skill to
be still attached or forced on the same lineage) contributes the
``allowed_tools`` of the pinned snapshot, whether or not it is still enabled
and whatever its current digest is — in the start-time union of every chat
turn and in the live list a resume intersects with. A detached skill has no
valid pin, so detaching takes its tools away at the next turn, as attachment
granted them. Every chat turn is a new
run, so without this rule a sync that disables a pinned skill, or an edit
that leaves a pinned backend skill unapproved, would take away the tools the
instruction still in the system message relies on. Unpinned skills follow
the rules above and, for backend skills, :ref:`item 3 <adr-214-d3>`.

**The load tool sits outside the skill allow-list.** It is exempt from the
skill-derived list and from the configuration's ``allowed_tool_groups``
gate (:php:`AllowedToolsResolver::applyGroupGate()`), so a skill declaring
``allowed-tools: []`` blocks every other tool but not the load tool. It still
passes the global tool state (ADR-039) — an administrator who disables it
switches model loading off — and the trust-zone gate. It reads only approved
skill text and has no effect outside the run's system message. The exemption
does not hold in a run that invokes a process skill: there the load tool is
offered only when the process skill's vouched declaration lists it, so an
invoked process skill without a vouched declaration leaves the run no tool.
The binding takes effect once the process wiring of items 6 and 10 hands the
invoked skills to the allow-list resolution.

.. _adr-214-d6:

6. A conversation holds pinned instructions, and a process binds it
-------------------------------------------------------------------

**A frontmatter marker** (working name ``process: true``) declares a skill to
be a process. A process skill is started by explicit invocation only; it is
never in the model's catalogue, so page content cannot start a process. A
process skill whose current version is not an instruction cannot be started.

**A process skill is composed only through an invocation.** The guarantee is
the compose-time skip: a process skill that reaches the ``always`` path or the
forced path (:php:`RunAugmentation`) is skipped, with a notice naming it, and
reaches a run only through an invocation. A forced process skill is skipped
the same way, not refused. The skip also covers a skill that turns into a
process skill after it was attached, because the marker is part of the
synced frontmatter. No notice is shown when a held pin already names the
skill: the process is then composed from its pin, and the attachment or the
forcing adds nothing. FormEngine additionally refuses the ``always`` load
mode on the attachment of a process skill; that is a convenience on top of
the skip, not the guarantee, because the Extbase write paths skip it
(:ref:`ADR-169 <adr-169>`, section 3). A process skill is attached ``on_demand``; an invocation reaches
either load mode. A consumer that wants a process for a whole conversation
passes it as an invocation, never as a forced skill. So every process pin
passes the start checks of
:ref:`item 10 <adr-214-d10>` — no service account, no four-eyes
configuration, no second process pin.

**Two guards keep a process run inside the chat.** Both are enforced by
nr_llm, not left to the consumer:

- **Only the chat card decides a process run.** A run that holds a process pin
  can be decided — approved, denied, or answered — only by its initiator
  (:php:`AiActorContext::isInitiatorOf()`). :php:`ResumeCoordinator::approve()`
  and :php:`ResumeCoordinator::submitInput()` refuse any other actor,
  administrators and holders of the approve grant (:ref:`ADR-130 <adr-130>`)
  included, with a typed refusal naming the chat. The two backend surfaces
  that can reach a run refuse it server-side as well, for the initiator too:
  :php:`AgentRunController` (its ``approveAction`` and ``submitInputAction``,
  also in the non-admin editor module) and :php:`ToolPlaygroundController`
  (its ``resumeAction()`` and ``submitInputAction()``, which accept any run
  uuid for an administrator). The Agent Runs inbox lists such a run read-only, with
  a note that it is decided in the chat. That leaves the chat card as the one
  shipped surface; an ``@api`` caller acting as the initiator acts for the
  same person. Each
  turn of a process run therefore has one write call, decided on the card by
  the person the chat belongs to, and nothing is ever released out of band.
  Because nobody but the initiator can decide, a process run must have one
  who can: a service account, for which ``isInitiatorOf()`` is always false,
  cannot start a process skill, and nr_mcp_agent offers and starts process
  skills only for users who may approve in the chat (its permission to
  approve one's own changes, nr_mcp_agent pull request #200). A tour started
  without that permission would be deny-only, so it is not offered.
- **No process on a four-eyes configuration.** Starting a process skill on a
  configuration with ``require_second_approver`` set is refused with a
  message that names the setting: under ADR-172 the editor could never apply
  a proposal, and the first guard leaves nobody else to. The pin check at
  start, pickup, every resume and every continuation includes it: a run whose
  configuration has switched the setting on since stops with the same
  message. On an approval, :php:`ResumeCoordinator::approve()` applies this
  rule to a run holding a process pin in a fixed order: first the initiator
  check of the first guard, then the existing refusal of an unreadable
  suspended state before the claim, then the four-eyes stop, and only then
  its ADR-172 gate, which a process run therefore never reaches. Without the
  stop, the self-approval refusal would leave the run waiting, and under the
  first guard nobody else could decide it. The run is stopped with the
  four-eyes message through a terminal transition guarded on
  ``WAITING_FOR_APPROVAL`` like the guarded cancel of item 10, since this
  happens before any claim; a corrupt run keeps its state for the operator,
  as today. Four-eyes
  tours are an open question for a later decision.

**Every instruction section is pinned, not only the process.** A pin is a
skill uid, a source uid and a digest, recorded for each section — from an ``always``
attachment, an invocation, a load or a forced skill — on the run request and
in :php:`SuspendedRunState`. A pinned section is always composed from the
approval snapshot of its digest, not from the body the record holds by then.

**Pins outlive the run that created them, and nr_llm carries them itself.**
A chat turn is a new run: the chat rebuilds the system message and sends the
history on every turn, and it persists the final answer, not the tool
messages (``ChatService::runAgentTurn()``, ``ChatService::applyResult()``). A
section loaded in turn 3 would otherwise be gone from turn 4's system message.
Each run therefore stores its pins, its invocation and its subject record with
the run record (uids and digests, no content). A continuation request names
only its predecessor run, by run uuid (:ref:`item 10 <adr-214-d10>`), and the
runtime derives the pins, the invocation and the subject record from that run.
No caller can hand in a pin: a pin exists only because an earlier run of the
same lineage admitted the section. Four rules bound the derivation:

- **Only the predecessor's initiator, or an administrator, may continue it.**
  :php:`AiActorContext::mayActOnRun()` with a scope is not used for this,
  because what it admits depends on the scope: for a human who is not an
  administrator it allows only their own run, except under
  ``agent:approve``, where the approve grant opens other users' runs; a
  service account passes on any scope it holds. A continuation derives
  another run's subject record and instructions, which is more than reading
  or deciding it, so the runtime requires that the request's backend user is
  the predecessor's ``beUser`` or that the actor is an administrator: the
  existing :php:`AiActorContext::isInitiatorOf()`, which already excludes
  service accounts and backs the four-eyes refusal in
  :php:`ResumeCoordinator::approve()`, or an administrator actor.
- **A predecessor that holds a process pin must be terminal.** nr_llm only
  checks this and refuses otherwise; it cancels nothing. Getting the
  predecessor to a terminal state is the chat's job
  (:ref:`item 9 <adr-214-d9>`): a new message cancels a waiting process run
  through the guarded cancel. A predecessor without a process pin keeps
  today's behaviour: it may still be waiting, its pins are derived all the
  same, and it stays releasable in the inbox, as an abandoned approval in an
  ordinary chat is today.
- **A missing or unreadable predecessor refuses.** Finished runs are purged by
  age (:php:`AgentRunRepositoryInterface::purgeOlderThan()`, governed by the
  agent-run retention setting) and conversations by inactivity, on separate
  clocks, so a conversation can outlive its last run. The request is then
  refused with a message telling the editor to start a new conversation; it
  is not silently turned into a start without pins.
- **A stopped run stores what still holds.** When a pin fails, the run stops
  with the pin's message (below) and stores its pins minus the failed one. The
  next turn therefore continues without that section instead of failing on it
  for ever; one revoked skill never blocks the conversation.

The runtime re-composes each derived pin into the new run's system message,
and the pinned snapshot also supplies the skill's tool declarations
(:ref:`item 5 <adr-214-d5>`).

**The pin rules, at start, at worker pickup, at every resume and at every
continuation.** A pin holds while an unrevoked approval for its digest and
source exists, its snapshot still hashes to the digest, the skill record
exists and is not orphaned, and the source's provenance is at or above the
threshold. When one of these fails, the run stops with a message that names
the skill and the reason. It does not continue without the section, and it
does not fall back to the fenced frame. On a resume after an approval, the
check runs before the approved write executes, next to the ADR-184 preview
check, so a write is never carried out under a revoked instruction.

**Attachment ends a pin without stopping the run.** A pin also requires that
the skill is still attached to the request's configuration, in either load
mode, or was forced on a run of the same lineage. Detaching a skill drops its
pin at the next turn, and a continuation whose configuration differs from its
predecessor's drops all pins, process pin included, because the new
configuration starts with its own attachments. Neither is silent: the run
records a notice naming the dropped skills and the reason, which the chat
shows before the answer, and the turn continues without them.

This is chosen over limiting revocation to new runs. A revocation is the
brake for text that is already acting as an instruction, and a run suspended
for an approval can wait for days; the transcript it replays already contains
the composed text, so without the check a revoked instruction would keep
steering it. The check is one lookup per pin.

**The enabled flag gates new use; revocation stops running use.** A sync that
changes an enabled skill disables it (ADR-035 item 5) so the new version is
reviewed. Pins carried by a run or a continuation keep working, because that
text is still approved: a sync during a tour does not end the tour at the
next message. A disabled skill cannot be invoked, loaded or started as a
process without a pin. An administrator who wants running tours stopped
revokes the digest.

**A newer approval does not replace a pin.** A conversation keeps the
version it started with until it ends or the pin fails; a new conversation
gets the current version. An older unrevoked version therefore stays usable
by the conversations that already hold it, and only by them. An administrator
who wants it gone everywhere revokes it.

**A process pin ends with the process.** The progress builtin of
:ref:`item 9 <adr-214-d9>` has a completion report. When the model reports
completion in a turn without a pending approval, the run releases the process
pin. In a turn that also holds a pending call, the completion report gets an
error result ("report completion after the decision") and releases nothing,
so a last "Andere Variante" still runs under the process. On release, the
next turn composes no process section and the one-approval rule of item 9 no
longer applies. The other pins of
the conversation stay. While a process pin is held, a second process
invocation is refused with a message naming the running process; there is
never more than one process pin.

Fenced, non-instruction skills keep today's behaviour: composed from the
current record, checked by the integrity check, and re-gated by ADR-165 when
forced.

.. _adr-214-d7:

7. Appended system text is charged, and admission does not strand a conversation
--------------------------------------------------------------------------------

**Every text appended to a system message is charged by**
:php:`ContextWindowManager::fit()`, whatever the first message holds: the
snippet block of :ref:`item 8 <adr-214-d8>` and the instruction sections. In
the agent loop, sections are written into the transcript's system message at
assembly or at load, so they are part of the measured messages. On the path
where the shaping stage appends them after ``fit()``, they are passed to
``fit()`` as charged text, the way the fenced block already is. Nothing is
appended after ``fit()`` uncharged.

**Admission is checked against the smallest send the fit can reach.** A new
section — an ``always`` attachment at the start of a conversation, an
invocation, a load — is admitted only when the head (system message with
every section already held, plus the new one, and the first user message)
together with the newest turn stays at or below the budget minus a headroom
of 10 % of the budget (working value). That is the floor ``fit()`` reaches by
dropping older turns (:ref:`ADR-107 <adr-107>`), so a section that passes fits the local estimate. Token estimation and
provider accounting can differ; admission is not a provider acceptance guarantee. Otherwise nothing is appended: a load returns an error result
naming the skill, and a start fails before the first provider call with a
message naming the skill. The implementing change applies the check at the
start of an agent run and at a load; a single call through the manager's
configuration paths (completion, translation, a chat without the loop) is
charged but not refused, because it carries no older turns the fit could
drop instead (``specs/012-approved-skill-versions/spec.md``, section (d)).

**A section the conversation already holds at the same digest is not
re-admitted.** A pin derived from the predecessor run is composed without a
new admission check. Without that rule a long tour would fail permanently once
its history grew past the headroom, although ``fit()`` could still send it by
dropping old turns.

``fit()`` itself is unchanged: it keeps dropping the oldest turns when the
transcript grows, and the system message, which carries the sections, is part
of the head it never drops. This is why ADR-107 is not amended: its drop
rule stays as it is, and only the admission of a new section, which ADR-107
does not govern, is decided here.

.. _adr-214-d8:

8. Snippets reach a caller's system message only when marked and opted in
-------------------------------------------------------------------------

**The snippet block is kept apart from the configuration's system prompt
until the messages are shaped.** When the caller sent no system message,
nothing changes: snippets are appended to the configuration's system prompt
(ADR-031). When the caller already sent one, the configuration's own system
prompt stays suppressed (per-call precedence, as ADR-031 and ADR-139's
characterisation tests pin), and a snippet is appended to the caller's first
system message only when both hold:

- **the snippet's composed block is marked by an administrator.** What
  reaches a system message is the block
  :php:`PromptSnippetComposer::composeSections()` emits: the snippet's name
  as a label, then its text. Both fields are editable without ``exclude``, so
  the mark (working name ``caller_system_digest`` on
  ``tx_nrllm_promptsnippet``, ``exclude => true``) stores a sha256 of that
  composed block, label and text, together with the snippet's tags, which
  decide the configurations that select it. The marking form shows the block
  and the tags and posts the digest of what it rendered; the server
  recomputes it and refuses the mark when the two differ, the rule item 2
  applies to skill approvals. The snippet is appended only while the digest
  of its current block and tags matches. An edit of the name, the text or the
  tags by anyone who may edit them — under ADR-169 a non-administrator —
  takes it out of the chat's system message until an administrator marks the
  new block; and
- **the configuration opts in** (working name
  ``snippets_in_caller_system_message`` on ``tx_nrllm_configuration``,
  ``exclude => true``, default off). Its ``snippet_tags`` selection, which
  routes marked snippets to it, is likewise an administrator's: both fields
  carry ``HIDE_FOR_NON_ADMINS``, which the DataHandler enforces for every
  non-administrator whatever a group grants.

A forced snippet (playground, queued runs) reaches a caller's system message
under the same two conditions, and joins that message rather than one of its
own. Unlike a skill section, which carries a pin that a resume checks
(:ref:`item 6 <adr-214-d6>`), a snippet block already in a suspended
transcript is replayed as composed, as snippets always were (ADR-166,
ADR-175): an unmark or an edit takes it out of every run composed afterwards,
not out of a run waiting for a human. Marks and unmarks are logged with the
acting backend user.

A mark on the record alone was rejected: the ``snippet`` and ``name`` fields
are not excluded, so a non-administrator could keep a marked record and
replace its text or its label. A fixed label on the caller-system path was
considered and rejected: the name is how a snippet is told apart in the
system message, and digesting it costs nothing. Admin-only TCA for the whole
snippet table was rejected as well: it
would reverse ADR-169's recommendation that non-administrators manage
snippets, for the sake of one path. With the text-bound mark, a
non-administrator can still write snippets that reach the configuration's own
system prompt, as today, but cannot route text into the chat's system
message.

The configuration's ``system_prompt`` keeps its reach: it is still suppressed
by a caller's system message, so this decision routes no new text through it.
Editing it already is an instruction-level privilege wherever it is sent;
whoever is granted ``tables_modify`` on ``tx_nrllm_configuration`` under
ADR-169 gets that privilege. This decision does not change that grant.

This item can ship first. Item 2 appends instruction sections to the caller's
system message through the same mechanism, but that path is governed by
the approval, not by the snippet mark.

.. _adr-214-d9:

9. Process state lives in tools and the run
-------------------------------------------

A process skill describes the steps. What the editor sees and what persists
comes from tools and the run, so that it does not depend on the model
following prose. nr_llm provides the generic capabilities; nr_mcp_agent
renders them and owns the open points.

- **Progress.** A read-only builtin reports the process's position (for
  example "point 3 of 7") or its completion, as integers and a flag, not as
  prose; it records the report as an event of its own kind, so the last report
  survives the ``metadata`` privacy level and is readable through the run's
  events and status. A completion report releases the process pin
  (:ref:`item 6 <adr-214-d6>`).
- **Highlight.** A read-only builtin names the record the current point is
  about. It accepts only targets the run registered from the invocation's
  subject record, which every continuation derives from its predecessor run
  (:ref:`item 6 <adr-214-d6>`), for example the content elements of the
  selected page; the UI maps a target to its element. The model never supplies
  a CSS selector or any other markup. It records the target as an event of
  its own kind holding the table name and uid, record identity in the sense
  of :ref:`ADR-185 <adr-185>` and no field values.
- **One approval-bound call per turn in a process run.** An approval decides
  the whole pending turn (:ref:`ADR-132 <adr-132>`;
  :php:`ToolLoopService::resume()` applies one decision to every pending
  call), and today the loop suspends before any call of the turn executes
  (:ref:`ADR-084 <adr-084>`). In a run that holds a process pin, the runtime
  counts the calls for which :php:`ToolApprovalRule::requiresApproval()` is
  true — declared writes, approval-bound reads (ADR-202) and remote tools that
  require approval — not the write-declaring ones. Before it suspends:

  - the pending call is the first approval-bound call that declares a write
    (:php:`ToolEffectResolver::effectFor()`). Only when the turn holds no
    such call is it the first approval-bound call of any kind, for example
    an external fetch whose approval is on (ADR-202) or a remote tool;
    it alone is the pending set and the preview of the card;
  - every further approval-bound call gets an error result ("one approval per
    turn in a process") appended to the transcript, so the model sees it and
    the pending set does not contain it;
  - an input-requiring call in the same turn gets the error result it would
    get at resume today, appended the same way;
  - a call that needs no approval, that
    :php:`ToolEffectResolver::effectFor()` resolves as a read and that is not
    a :php:`RemoteToolInterface` executes, in turn order, and its result is
    appended. Builtin reads declare no effect and resolve as reads by default
    — none of the 17 builtins that implement :php:`ToolEffectInterface`
    declares a read — so they keep working next to a proposal. A remote tool
    never runs early, even one that declares no effect. Progress and
    highlight are builtin reads, so they are visible while the editor
    decides, and a denial reaches only the one pending call;
  - every remaining call — one that needs no approval but is not a read, for
    example a tool of an MCP server whose operator switched approval off
    (ADR-134), which resolves as ``NON_IDEMPOTENT_WRITE`` — gets an error
    result ("not executed while a proposal waits for approval") appended, and
    does not run. An unknown tool name resolves as a write too
    (:php:`ToolEffectResolver::effectFor()`), so it never runs early.

  This keeps ADR-132's invariants: the turn digest is computed over the
  pending calls (:php:`PendingTurnDigest::forState()`), which are now exactly
  the one approval-bound call, and one decision still covers the whole pending
  set. It amends ADR-084's "check the whole turn before executing any of its
  calls" for process runs, and only for reads: nothing that writes runs before
  the editor's answer, so "nothing is saved without an explicit confirmation"
  holds. The calls of one turn are parallel tool calls with no order between
  them on the provider side, and only reads run early, so executing them first
  changes no state the pending write could depend on. Outside process runs the turn
  suspends before any call, as today.
- **One approval card per proposal; approve means apply.** A proposal is the
  pending write call itself. Its approval card shows the preview lines of
  ADR-136 (current and proposed text), bound by ADR-184. A pending call that
  is not a write is not a proposal: its card shows a plain approve and deny,
  without the three answers and without a denial reason. For a write, the
  three answers map onto the one pause:

  - **"Übernehmen"** approves the call, which writes.
  - **"Andere Variante"** denies it with the reason ``variant``.
  - **"Überspringen"** denies it with the reason ``skip``.

  The reason is a closed type: a string-backed enum (working name
  :php:`ApprovalDenialReason`, cases ``variant`` and ``skip``; ``keep`` was
  added by the :ref:`amendment of 2026-10-24 <adr-214-amendment-keep-reason>`) on
  :php:`ApprovalDecision`, which is an ``@api`` value object with a public
  constructor. The constructor rejects a reason together with
  ``approved = true``. The reason travels through
  :php:`ResumeCoordinator::approve()` into :php:`ToolLoopService::resume()`,
  and the denial result renders it through a ``match`` over the enum cases,
  as ``decided_by`` is rendered today, as a fixed token beside ``decided_by``
  (ADR-200). The model either proposes a new variant — a new write call and a
  new card — or moves on to the next point; the open point is recorded by the
  chat, not by the model (below). A denial without a
  reason keeps today's text. No pause combines input and approval: the
  ADR-134 ban stays, and nothing about the answer is collected through the
  input path.
- **The proposal is also given as structured values.** The chat shows each
  field of a proposal as "Aktuell" and "Vorschlag", with the length of a text
  against its recommended range, and never parses the preview lines for them.
  A write tool may implement :php:`StructuredPreviewInterface`; it returns
  one :php:`FieldProposal` per field it would change: the column, its TCA
  label in the reader's language, the value stored now, the value the call
  would write, and an optional :php:`FieldMeasure`. :php:`PendingCallView`
  carries the list next to the preview lines, which stay as they are, so the
  addition is additive: a tool without the interface gives an empty list.
  ``update_page_metadata``, ``update_content_element`` and
  ``set_page_social_image`` implement it.

  - **The values come from the tool, never from the model.** The current
    value is read from the database, the proposed one is the argument after
    the tool's own checks, and the label is the TCA's.
  - **Read for the viewer, when the card is rendered.** Capturing the values
    at the pause would change :php:`ToolLoopService` and the persisted state;
    instead :php:`WaitingRunViewFactory` asks the tool with the backend user
    the card is rendered for, which in the chat is the user who started the
    run. A record or field that user may not edit, a call the tool would
    refuse, a card without a viewer, and a card whose preview lines are
    withheld give no entries. "Aktuell" can therefore be newer than the
    preview lines captured at the pause; the approval still binds to the
    lines (:ref:`ADR-184 <adr-184>`), and the write re-checks everything.
  - **Ranges are configuration.** The extension configuration
    ``tools.structuredPreview.ranges`` holds ``table.field:min-max`` entries;
    the shipped default is ``pages.description:140-160``, the meta
    description length of the product specification "Guided Tour: SEO
    Optimierung", section 5, and no other field has one. The length is the
    plain text counted in characters. Without a range a single-line or meta
    field carries its length alone, and a body text carries none. (Replaced
    by the :ref:`amendment of 2026-10-30
    <adr-214-amendment-length-only-with-range>`: without a range no field
    carries a length.)
  - **The values are raw.** A rich-text body is its stored HTML, and the
    proposal is model-chosen text; nr_llm passes both through as data and
    renders neither, and the consumer escapes or sanitises them.
- **Writes need an approval, always.** No process skill, trusted or not, can
  switch that off (ADR-134).
- **A new message withdraws a waiting process run.** The conversation
  states are those of nr_mcp_agent: ``AwaitingApproval`` for a card, and
  ``AwaitingInput`` for an input pause, which nr_mcp_agent pull request #197
  adds together with its ``submitInput()`` endpoint. When a message arrives
  while the conversation is in either state and its run holds a process pin,
  nr_mcp_agent's ``queueTurn()`` acts in this order:

  #. it claims the conversation row first, with its compare-and-set
     ``updateIf()``, and answers 409 when the claim fails. A decision the
     chat has made is never in this window: deciding a card records the
     decision and moves the conversation to ``Processing`` in one
     compare-and-set from ``AwaitingApproval`` (``ChatService::recordDecision()``,
     which writes through ``updateIf()``), and a message to a
     conversation in ``Processing``, or one that still records a decision
     (``hasPendingApprovalDecision()``), is answered 409 before any cancel.
     The outcome of the chat's own decision always arrives through the
     result path;
  #. it then cancels the run through the guarded cancel of
     :ref:`item 10 <adr-214-d10>`, which succeeds only from
     ``WAITING_FOR_APPROVAL`` or ``WAITING_FOR_INPUT`` and reports the run's
     status after the attempt;
  #. if the cancel won, or the run was already terminal although the chat
     made no decision on it — which under the first guard of item 6 means an
     operator cancelled it — the turn is dispatched with the run as its
     predecessor. The chat records nothing for the withdrawn proposal and
     shows only that it was discarded ("Vorschlag verworfen"). A run that no
     longer exists records nothing
     either, and the turn follows the missing-predecessor rule of
     :ref:`item 6 <adr-214-d6>`;
  #. if the run is ``QUEUED`` or ``RUNNING`` — the chat's own decision is
     being carried out — it puts the row back as it was, status and run
     uuid, through ``updateIf()`` from the status it claimed, and answers 409.
     A failed put-back means the worker's result already landed in the row,
     so it writes nothing; the result path (``applyResult()`` writes the
     whole row) wins either way.

  Because the first guard of :ref:`item 6 <adr-214-d6>` leaves the chat card
  as the only way to decide a process run, a run that is not waiting can only
  be one the chat itself released, so the guarded cancel never withdraws a
  proposal somebody else approved. Runs without a process pin are not
  cancelled; ``queueTurn()`` keeps leaving them waiting, as today.
- **Applied means the chat's own approved call wrote everything it
  planned.** The chat decides every card of a process run itself, one write
  call per turn, and gets the result back through the normal result path:
  the run result that its ``approve()`` returns, or that its worker hands to
  ``applyResult()``. Its ``steps`` carry the segment's trace on every outcome
  (:php:`AgentRunResult`), also when it ends in the next suspension. The
  approved call's step is the first tool step of that result, with no call id
  needed: every resume segment starts a fresh trace
  (:php:`AgentRunExecutor::executeResume()`), and
  :php:`ToolLoopService::resume()` records the pending calls — under the
  one-approval rule exactly one — before it re-enters the loop. Provider call
  ids could not serve anyway, since some restart per response (the Ollama
  adapter synthesises ``call_<index>``).

  **Which reading applies.** When the result holds the approved call's first
  tool step, the chat reads one of the three states below from that result
  alone; that includes a run that failed after the call ran. "Vorschlag
  verworfen" applies only to the endings without such a step: a waiting run
  withdrawn by a new message or cancelled by an operator, a resume refused
  before the call ran (the four-eyes stop, a failing pin), and a purged run. A
  stale preview is none of these: the resume re-suspends with a fresh card
  (:ref:`ADR-184 <adr-184>`), and the editor decides again.

  Exactly one of the three applies:

  - **Applied.** The first tool step's ``toolIsError`` (:php:`RunStep`) is
    false, and it is followed by the write step (``RunStep::KIND_WRITE``,
    :ref:`ADR-182 <adr-182>`) of that call with the completeness
    ``COMPLETE`` and without the hook-failure flag (below).
  - **Approved, check the record.** The first tool step is OK and one of
    these holds: the write step carries the hook-failure flag, whatever its
    completeness; the write step's completeness is not stated; or no write
    step follows it while the run's outcome is ``CANCELLED``, ``LEASE_LOST``
    or ``FAILED``. :php:`RunTrace::recordToolResult()` appends the tool step
    and lets its listener run before it appends the write step, and the
    listener :php:`AgentRunExecutor` installs can throw in between — the
    cancellation probe, the lease renewal, the audit persist — so the write
    may have happened without a write step to show it. The ADR relies on this
    display state and does not require reordering
    :php:`RunTrace::recordToolResult()`; appending both steps before any
    listener runs would narrow the window, and the display state stays
    correct either way.
  - **Not applied.** Every other case: an error on the first tool step, a
    write step with the completeness ``PARTIAL`` and without the hook-failure
    flag, or a tool that leaves no write step on a run that did not abort,
    such as a remote tool, which is shown as approved but not applied.

  Only the applied state closes an open point; the other two record nothing.

  **Completeness is stated by every write, not listed.** A list of partial
  branches cannot be complete: a coverage test can only find the flags that
  are set, and a branch that forgets one looks like success. So
  :php:`ToolResult::withWriteTarget()` gains a completeness argument, a
  string-backed enum (working name :php:`WriteCompleteness`) with the cases
  ``COMPLETE`` and ``PARTIAL``, and :php:`RunTrace::recordToolResult()`
  copies it onto the write step it already records. Each tool decides per
  return. Known ``PARTIAL`` branches today, as examples and not as the
  definition:

  - ``Classes/Service/Tool/Builtin/UpdateContentElementTool.php#in part: %s took.``
    (some planned fields did not take);
  - ``Classes/Service/Tool/Builtin/DeleteRecordTool.php#but not completely:``
    (records that should have gone with it are still there);
  - ``Classes/Service/Tool/Builtin/MovePageTool.php#Not completely:``
    (translations stayed behind);
  - ``Classes/Service/Tool/Builtin/ReplaceFileReferenceTool.php#The translations are not settled:``
    (appended to both of its success returns), and
    ``Classes/Service/Tool/Builtin/ReplaceFileReferenceTool.php#did not take on the new reference``
    (a text the replacement was asked to set);
  - ``Classes/Service/Tool/Builtin/CreateTranslationDraftTool.php#The text was only PARTLY machine-translated:``
    (only partly translated).

  **A hook failure after the write is reported beside the completeness, not
  as it.** :php:`ToolLoopService` prefixes a result with ``hookFailureNote()``
  when a DataHandler hook of the installation failed after the last write of
  the call; a failure during the writes is rethrown and ends the call as
  failed, unless the tool catches it and reads back, as
  ``create_translation_draft`` does for its text write
  (:php:`CreateTranslationDraftTool`, the catch around its text write in
  ``translateTexts()``); the outcome stays the tool's to state
  (:ref:`ADR-206 <adr-206>`). This decision keeps that: the completeness the
  tool stated is not changed. Today the signal exists only as text in the
  result, which the chat does not parse, so the write step gains a boolean
  next to the completeness (working name ``hookFailedAfterWrite``). The loop
  sets it on the result at the place where it adds the note, from the same
  ``ToolDataHandler::takeFailures()`` call, and
  :php:`RunTrace::recordToolResult()` copies it onto the write step. A write
  step with the flag set is shown as "approved, check the record" and closes
  no open point, whatever its completeness. ADR-206's rule is not changed, so
  this decision does not amend it.

  **Public API.** :php:`ToolResult` is on the frozen ``@api`` surface
  (:ref:`ADR-182 <adr-182>`), and a required argument would break every
  third-party tool that calls ``withWriteTarget()``. The argument therefore
  defaults to ``null``, meaning "not stated", which the chat shows as
  "approved, check the record" and which closes no open point. Every builtin
  passes an explicit value: a coverage test on the pattern of
  :php:`ToolEffectCoverageTest` asserts that every ``withWriteTarget(`` call
  under ``Classes/`` passes a completeness argument. The test skips the
  method's own declaration and docblock in ``ToolResult.php``, and it accepts
  any expression of the enum type, not only a literal case, because some
  tools decide in a helper — :php:`ReplaceFileReferenceTool` decides inside
  its ``settleTranslations()`` step. What it refuses is an omitted argument or
  an explicit ``null``. The hook-failure flag is set only by the loop, through
  a new transformation on :php:`ToolResult` that leaves every other member as
  it is. Both additions are additive on the frozen surface, and the
  API-surface snapshot records them.

  **The completeness must survive** :php:`ToolResult::withBoundedChannels()`,
  the transformation every tool result passes through in
  :php:`ToolLoopService`: it rebuilds the result by constructor position, so
  the docblock's earlier claim that a property added later is carried by
  default did not hold, and the completeness and the hook flag would have
  been dropped there. The rebuild of a successful result passes both on
  (``Classes/Domain/ValueObject/ToolResult.php#$this->writeCompleteness,``),
  an error result carries neither, and ``ToolResultTest`` asserts that every
  member survives.

  Nothing is inferred from persisted events, and no other path marks a point
  applied.
- **Pure choices use a choice builtin.** A choice without a write — which page,
  which finding first, whether to continue — is a ``WAITING_FOR_INPUT``
  suspension of a generic builtin that implements
  :php:`RequiresInputInterface` with an enumerated schema built from its
  arguments (ADR-105). It declares no write effect, so the ADR-134 ban does
  not apply to it, and it never stands in for the approval of a write.
- **Open points persist in nr_mcp_agent, recorded server-side.** Recording
  must follow the card decision, and a model call cannot guarantee that: the
  model may skip it or make it for a point the editor applied. So no tool
  records them. nr_mcp_agent records an open point in exactly one case: the
  editor presses "Überspringen" on the card. The chat decides that card
  synchronously and knows the target from the card it rendered. Nothing else
  records an open point — not a proposal withdrawn by a new message, not a
  guarded or operator cancel, not the four-eyes stop, not a resume that
  fails after the claim, not a purge. The chat shows each of those by the
  precedence rule of the applied state (above): one of the three states
  where the approved call ran, "Vorschlag verworfen" where it did not. That
  is a limitation of this
  decision: a finding lost that way is found again the next time the process
  runs on the record. The key is the pending write itself: the process skill
  uid, the subject record, and the
  target record and field names of the pending call. These are a new
  obligation, not something the preview provides: :php:`ToolPreviewInterface`
  returns prose lines only. An approval-bound write tool gains a method that
  returns, for a pending call, the target table, uid and field list as
  structured values (working name ``pendingTarget()``), or no target for a
  call that creates its record, and nr_llm exposes
  them next to the ADR-136 preview the card shows, so the chat parses no
  prose. Two findings on the same record therefore stay apart when they
  touch different fields, and a proposal is always keyed to what the card
  showed, not to an earlier highlight. A pending call without a structured
  target — a remote tool, or a tool that has not implemented the method —
  records nothing. A write that names a record but no field, such as a move,
  a publish or a delete, is keyed by the record with an empty field list. A
  write that creates its record has no uid before it runs, so
  ``pendingTarget()`` returns no target for it, and a skipped create records
  no open point; this is a limitation of this decision, not an oversight.
  Only an applied write to the same record and field closes an open point:
  a card for that record and field that the chat approved and whose call
  came back as applied (above). The reason
  ``variant`` records nothing, because a new proposal follows. Open points
  are kept in a table nr_mcp_agent owns; listing them for the model stays a
  read tool nr_mcp_agent registers, and they are offered again when the
  process is started on that record. nr_llm stores no open points.

.. _adr-214-d10:

10. The public API
------------------

Working names; the shapes are the decision.

- **Start with a skill.** :php:`AgentRunRequest` gains an optional skill
  invocation: the skill uid and an optional subject record (table and uid).
  :php:`AgentRuntimeInterface::run()` and ``enqueue()`` (``@api``) accept it.
  At start the runtime checks that the skill is attached to the configuration
  and enabled, that the request's actor may use the configuration, that the
  subject record is readable by that actor, for a process that the version is
  an instruction, that no process pin is held, that the configuration does
  not set ``require_second_approver``, and that the actor is not a service
  account.
- **The checks are actor-scoped.** :php:`LlmConfigurationService::hasAccess()`
  reads the global backend user, which is wrong for a run the worker executes
  after ``enqueue()`` and for an ``@api`` caller acting for someone else. The
  access check is the existing
  :php:`ConfigurationResolver::actorMayUse()`, which takes the request's
  :php:`AiActorContext` and which nr_mcp_agent already calls, and the
  subject-record check runs against the backend user the run executes as
  (:php:`ExecutionIdentity`), at start and again at worker pickup.
- **Continue from a predecessor run.** :php:`AgentRunRequest` gains an
  optional predecessor run uuid instead of any pin data. The runtime loads
  that run under the four rules of :ref:`item 6 <adr-214-d6>` (initiator or
  administrator, terminal when it holds a process pin, refusal when it is
  missing, stored pins minus a failed one) and derives the pins, the invocation and the
  subject record from it. A continuation waives exactly the conditions that
  gate new use: ``enabled``, and for a loaded skill the catalogue conditions
  (``on_demand`` attachment, not a process). It re-checks the rest on every
  continuation: the actor's access to the configuration
  (:php:`ConfigurationResolver::actorMayUse()`), the subject record's
  readability for the backend user the run executes as, and the pin rules of
  item 6: revocation, a failing snapshot, an orphaned record or a provenance
  drop stops the run, and a detached skill or a changed configuration drops
  the pin with a notice. A request that names a new invocation
  and a predecessor is a new start for that invocation and a continuation for
  the rest. An invocation whose skill uid a derived pin already names is not
  a new start: it is the idempotent case of item 4, so a chat that passes the
  conversation's process skill as an invocation on every turn does not trip
  the "no process pin is held" check. Passed as a forced skill instead, a
  process skill is skipped with a notice, not refused, unless a held pin
  already names it (:ref:`item 6 <adr-214-d6>`). Inside one run,
  ``approve()`` and ``submitInput()`` continue as today.
- **Catalogue for slash commands.** An ``@api`` service returns the skills an
  actor may invoke on a configuration — uid, identifier, name, description,
  load mode, whether it is a process and whether its current version is an
  instruction — filtered by attachment, ``enabled``, the actor's access to the
  configuration (the actor-scoped check above), and optionally to process
  skills only. A backend AJAX route serves the same list as JSON for the
  slash-command menu. Descriptions of non-instruction skills are flagged as
  untrusted text for the consumer to escape.
- **Guarded cancel and pending-write facts.** :php:`AgentRuntimeInterface`
  gains a cancel that succeeds only from ``WAITING_FOR_APPROVAL`` or
  ``WAITING_FOR_INPUT`` and returns whether it won, together with the run's
  status after the attempt (working name ``cancelIfWaiting()``), under the
  initiator-or-administrator rule of item 6. It returns nothing about the
  proposal it withdrew, and nr_llm keeps no pending target after a run
  leaves the waiting state. Today's :php:`AgentRunPersister::cancel()` also
  ends a queued or running run and stays the operator's tool. A run's status
  exposes whether it holds a process pin and, while it waits for approval,
  the pending write's structured target from the write tool's
  ``pendingTarget()`` (:ref:`item 9 <adr-214-d9>`), which is what the chat
  renders on the card.
- **Process runs refuse other deciders.** :php:`ResumeCoordinator::approve()`
  and ``submitInput()`` refuse an actor who is not the initiator of a run
  holding a process pin; ``approve()`` then checks an unreadable state and
  then the four-eyes pin rule, before its ADR-172 gate.
  :php:`AgentRunController` (``approveAction``, ``submitInputAction``) and
  :php:`ToolPlaygroundController` (``resumeAction()``,
  ``submitInputAction()``) refuse such a run server-side, and the inbox shows
  it read-only (:ref:`item 6 <adr-214-d6>`).
- **Approval decision.** :php:`ApprovalDecision` gains the optional
  :php:`ApprovalDenialReason` of :ref:`item 9 <adr-214-d9>`; the rendered
  tokens sit on the ``@api`` :php:`ToolLoopServiceInterface` with the existing
  ``decided_by`` constants, so the API-surface snapshot guards them.
- **Builtins.** nr_llm ships the load tool, the progress and highlight
  builtins and the choice builtin; nr_mcp_agent ships the read tool that
  lists open points. Until nr_llm ships progress and highlight, nr_mcp_agent
  pull request #201 provides them as its own tools declaring ``READ_ONLY``;
  the one-approval rule of item 9 treats them like any read. Their only write
  is the chat's own run-state bookkeeping, no TYPO3 record. That highlight
  accepts any content element of the conversation's page the user may see,
  which is wider than the registered targets of item 9; the narrower rule
  applies once nr_llm ships the builtin.

.. _adr-214-d11:

11. Tasks keep their one-shot contract
--------------------------------------

A task stays one prompt and one answer. Process skills run in agent runs. No
workflow engine, step table or state machine is added.

.. _adr-214-amendment-process-precedence:

Amendment (2026-10-22): a running process governs sequence and answer structure
===============================================================================

**Context.** Neither this record nor :ref:`ADR-228 <adr-228>` says which
instruction wins when the process a run invokes and another approved skill
prescribe different things. :php:`SkillComposer::composeBlock()` composed both
under one heading, ``## Approved skills``, whose preamble tells the model to
follow every section below it; the process section came last, after the
sections of the ``always`` attachments.

That was measured on a production configuration with an approved editorial
skill attached ``always``, whose rules include "prioritise rather than list,
especially for SEO", and a guided SEO tour invoked as the run's process. The
tour prescribes two tool calls after its overview: one that reports progress
and one that offers a choice. Its own body says that the tour takes
precedence over general answer rules. In two runs the model grouped the
overview by priority, as the editorial rule asks, and ended the turn without
a tool call. With the editorial skill detached, the same page, model, request
and tour produced both calls in the first turn. The process already came last,
and its body already claimed precedence, so neither position nor the body's
wording decided it. The frame the composer writes treated the two as equals.

**Decision.** The spirit of :ref:`item 6 <adr-214-d6>` decides it: a process
is invoked explicitly for this run, so its sequence and its answer structure
govern the run, and the other approved instructions keep applying to wording,
terminology and safety.

1. **The process is a section of its own, ahead of the approved skills.** It
   is composed under the heading ``## Active process`` with a preamble that
   says the process was started explicitly in this conversation and governs
   the run: follow its steps in order, end each step with the tool call the
   step prescribes, and give each answer the structure the process
   prescribes. Where an approved skill prescribes a different sequence or a
   different answer structure, the process takes precedence. The approved
   skills keep applying to wording, terminology and safety, and the
   configuration and its safety rules apply in full.
2. **The approved skills say so too.** In a run that composes a process, the
   ``## Approved skills`` preamble gains one sentence: while the active
   process runs, the approved skills keep applying to wording, terminology
   and safety, and the process takes precedence where they prescribe a
   different sequence or answer structure. A section the model loads in such
   a run joins the approved skills; when it opens their section, it carries
   the same sentence.
3. **The process outranks other approved skills, nothing else.** It does not
   take precedence over the configuration's system prompt, the snippets, the
   safety rules or the write approval of :ref:`item 9 <adr-214-d9>`.
4. **A run without a process composes exactly as before.** Pins, digests,
   the order of the composed identifiers and pins, the drop order and the
   fenced block are unchanged. The fenced block additionally defuses the
   ``## Active process`` heading and its preamble, as item 2 already does for
   the approved-skills frame, so a fenced body containing either literal
   reads differently than before.

**Why the process comes first.** Recency was not the lever: the process was
already the last section and lost. Placed first, the governing frame and the
steps are read before the rules they outrank, and the rules open with the
reminder. A section loaded later still lands at the end of the approved
skills, without moving the process.

**Consequence.** Unit tests pin the composed text; whether the model follows
it can only be measured against the model. The measurement is the same A/B
after the release is deployed: with the editorial skill attached, the first
turn of the tour calls the progress tool and the choice tool. The failure
signature is the one measured above: the turn ends with the finish reason
``stop`` after an overview grouped by priority, without a tool call. If it
persists, the frame is not the lever, and the next decision lies outside
composition.

.. _adr-214-amendment-keep-reason:

Amendment (2026-10-24): "keep" is a third denial reason
=======================================================

**Context.** Guided tours offer a fourth answer on the approval card,
"Behalten": the editor keeps the current content, the point is settled and
nothing is written. The chat had no case of its own for it and sent
``skip``, the nearest one. The denial result then told the model that whoever
declined "chose to skip it", and the model repeated that to the editor
("du hast diesen Punkt übersprungen") although the editor had not skipped
the point. This was measured on the demo with nr-mcp-agent 0.17.8 and
nr-llm 0.41.3.

**Decision.** :php:`ApprovalDenialReason` gains the case ``KEEP`` with the
token ``keep``, declared as ``ToolLoopServiceInterface::DENIAL_REASON_KEEP``.
The denial result renders it beside ``decided_by`` like the other two. Its
advice says that whoever declined chose to keep the current content, that the
point is settled, that the change is not to be proposed again and not to be
called skipped, and that the model moves on to the next point.

**Compatibility.** The enum is ``@api``. It gains a case and loses none, so a
caller that passes ``variant`` or ``skip`` behaves as before. A consumer that
switches over the enum without a default arm has to handle the new case; the
release is therefore a patch release, which keeps dependents constrained to
``^0.41`` installable.

**Consequence.** Unit tests pin the token and the advice, and that the keep
advice does not say "skip". Whether the model now words the answer correctly
is measured on the demo after the extension that sends the case is deployed:
after "Behalten" the assistant must not say that the point was skipped. The
failure signature is the old sentence in the assistant's answer.

.. _adr-214-amendment-length-only-with-range:

Amendment (2026-10-30): a length only against a configured range
================================================================

**Context.** :ref:`Item 9 <adr-214-d9>` gave a single-line or meta field its
length even without a configured range. The approval card then shows a row
such as "Länge 23 Zeichen" for a page title, and an editor asked what that
number is for. It carries no judgement: the card's length row exists to
compare a proposal with a target, and without a target there is nothing to
compare.

**Decision.** A proposal carries a :php:`FieldMeasure` only when a range is
configured for its ``table.field`` in ``tools.structuredPreview.ranges``.
Without a range there is no measure, for every field type.
:php:`FieldMeasurer::measure()` returns null whenever no range is configured.
A configured range on a rich-text field still counts its plain text.

**Compatibility.** :php:`FieldMeasurer` is not ``@api`` (ADR-127), but
:php:`StructuredPreviewInterface`, an ``@api`` extension point, tells
implementors to take ranges from it. Its fourth parameter,
``$countWithoutRange``, therefore stays where it is, now optional, deprecated
and ignored: removing it would move ``$richText`` into its place, and an
existing call passing ``true`` would silently count a single-line value as
rich text. :php:`FieldMeasure` and :php:`FieldProposal` keep their shape: a
measure stays optional, and a consumer that already omits the row when no
measure arrives needs no change. With the shipped default, only
``pages.description`` shows a length.

**Consequence.** Unit and functional tests pin that a field without a range
gives no measure and that a range configured on ``pages.title`` gives the
count with the range, and that a call in the old four-argument form keeps
its meaning. The failure signature on the card is a length row with a count
and no range.

.. _adr-214-amendment-reads-beside-a-question:

Amendment (2026-10-31): reads beside a question run before the pause
====================================================================

**Context.** A guided tour reports where it stands and what the current point
is about with two reads, the progress and the highlight of
:ref:`item 9 <adr-214-d9>`. On the demo, 65 answer turns of the Backend AI
Chat between 2026-10-23 and 2026-10-30 (nr-llm 0.41.16, nr-mcp-agent 0.27.0,
one OpenAI model) spent 92.7 % of their active time in model calls, with a
fixed cost of about 1.3 s per call on top of the output tokens (a linear fit
over the 224 calls). 52 of the 65 turns held a
round whose only output was a progress, highlight or open-points call, and
those rounds took 35 % of the active time. The tours asked for the progress,
the highlight and the proposal as numbered steps, and the model made one round
of each.

Item 9 already lets the progress and the highlight sit in the same response as
the proposal: reads beside a pending write run before the pause. A question
(:php:`RequiresInputInterface`, for example the choice builtin) had no such
rule. Its turn paused before any call ran, and the reads beside it ran only
after the editor had answered. A tour that reports its progress in the same
response as its question therefore showed no progress while the editor
decided, and kept the extra round to avoid that.

**Decision.** In a run that holds a process pin, a turn that pauses for input
settles the calls beside the question as item 9 settles the calls beside a
proposal. The question alone is pending, and it goes last in the stored turn.
Before the pause, a read that needs no approval and is not remote runs in turn
order, and a load runs as in any other turn. A second input-requiring call
gets an error result, as it would at resume: one answer satisfies one
question. Any other call that may not run early
gets "not executed while a question waits for an answer". A turn that also
holds an approval-bound call is a proposal turn, as before: approval keeps its
precedence. Outside process runs a question still pauses before any call of
its turn runs.

The progress is not derived from the work call alone. The point number and
the number of points come from the model's own overview, and a second
proposal for the same field can be another variant of the same point or a
new point, which the call cannot tell apart. Both tools stay as they are, and
the tours ask for the progress, the highlight and the step's proposal or
question in one response.

**Compatibility.** Nothing writes earlier than before, so "nothing is saved
without an explicit confirmation" holds. An answer still reaches exactly the
one question. The pending set of an input pause in a process run is now that
question alone, so a resume no longer runs the reads beside it a second time.
:php:`SuspendedRunState` keeps its shape. The release is a patch release,
which keeps dependents constrained to ``^0.41`` installable.

**Consequence.** Unit tests pin the settled turn, the order of the stored
calls, the refusals, the unchanged pause outside process runs, and a resume
that runs the question alone. Whether the model batches the calls is measured
on the demo after the tours ask for it, the same way as the numbers above,
from the run timeline: rounds per turn, rounds whose only calls are progress,
highlight or open points, and active time per turn. The failure signature is a
progress or highlight call alone in a round, before the proposal or question
of the same point.

**Measured (2026-10-31, nr-llm 0.41.18).** Four tours on the demo, every
proposal answered with "keep". Beside a question the model batched progress,
highlight and the question in one response, and the overview batched its
progress with its question in 2 of 3 runs. Beside a proposal it never did:
0 of 10 approval turns held the write together with a progress or highlight
call, so the failure signature named above fired. The point and the highlight
of a proposal are therefore derived by the consumer from what the editor
decided and from the pending call's :php:`$pendingTarget`: nr-mcp-agent ADR-020,
amendment of 2026-10-31. Nothing changes in this extension.

.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-214:

===========================================================================
ADR-214: Approved skill versions instruct, load on demand and run processes
===========================================================================

:Status: Accepted
:Date: 2026-10-08
:Amends: :ref:`ADR-036 <adr-036>` (item 3 and the tail drop of item 5, for
    skills approved as instructions; item 6: the checksum becomes the version
    digest); :ref:`ADR-035 <adr-035>` (items 4 and 5: the checksum covers the
    frontmatter fields, so a frontmatter-only change is a change);
    :ref:`ADR-061 <adr-061>` (items 1 and 4: the trust level also decides the
    framing, and backend-authored skills are admitted by their source's level); :ref:`ADR-038 <adr-038>` (item 5: the allow-list is resolved once
    per run over every effective attached skill); :ref:`ADR-031 <adr-031>` (a
    caller's system message keeps snippet text an administrator marked, on
    configurations that opt in); :ref:`ADR-200 <adr-200>` (a denial carries an
    enumerated reason); :ref:`ADR-165 <adr-165>` (resume also re-reads the
    invoked skill and intersects the stored allow-list);
    :ref:`ADR-169 <adr-169>` (section 4: the exclude list grows by the skill
    and skill-source fields of item 3)
:Authors: Netresearch DTT GmbH

.. _adr-214-context:

Context
=======

Editors ask for guided processes in the chat: optimise the SEO of one page,
improve the content of one page. Such a process picks a page, analyses it,
gives an overview, then works through the findings one at a time. Each
finding gets a proposal with the current and the proposed text and three
answers: apply, another variant, skip. Nothing is saved without an explicit
confirmation. The editor sees the progress and the affected content element.
A skipped finding stays open for the next conversation.

The product owner wants such processes as editable text, not as one extension
per process. Skills (:ref:`ADR-035 <adr-035>`, :ref:`ADR-036 <adr-036>`) are
the existing record for reusable prose, and the agent run already has the
suspension states a guided flow needs. Two product requirements are fixed:
a skill can be started by a slash command **and** loaded by the model when
its name and description match the request; and skills are enabled per
skill, like tools. Seven properties of the current code stand in the way.

.. _adr-214-context-framing:

1. Every skill is data, never an instruction
--------------------------------------------

:php:`SkillComposer` wraps every admitted skill in the same frame: a guard
preamble that calls the block "UNTRUSTED task-reference DATA" and fence
markers that say
``Classes/Service/Skill/SkillComposer.php#do not follow as instructions``.
:php:`SkillComposer::composeBlock()` applies that frame to every skill
:php:`SkillComposer::effectiveSkills()` admits, whatever its
:php:`SkillTrustLevel`. The trust level decides admission only: a skill below
``skills.minTrustLevel`` is not composed at all
(:ref:`ADR-061 <adr-061>`, item 1;
``ext_conf_template.txt#skills.minTrustLevel = untrusted``;
:php:`SkillComposerFactory::minTrustLevel()`). The governance profiles expect
``community``, ``verified`` or ``first_party`` there, and ``development`` expects
``untrusted`` (:php:`GovernanceProfile::expectations()`). A ``first_party``
skill is admitted under every profile and still reaches the model as text it is told
not to follow. ADR-036 item 3 puts the block into the first user message and
never into the system role; ADR-061 item 4 added the fence. Both were right for
third-party text fetched from the internet, and neither leaves room for text
the installation itself vouches for.

.. _adr-214-context-sources:

2. The checksum covers the body only, and a backend edit invalidates it
-----------------------------------------------------------------------

The source types are ``single_file``, ``repo`` and ``marketplace``
(:php:`SkillSourceType`), and every fetch goes through
:php:`GitHubClient`, whose host allow-list names only GitHub hosts
(``Classes/Service/Skill/GitHubClient.php#ALLOWED_HOSTS``).

The sync computes ``body_checksum`` from the body alone
(``Classes/Service/Skill/SkillSyncService.php#hash('sha256', $parsed->body)``),
and compose re-verifies it the same way
(``Classes/Service/Skill/SkillComposer.php#hash('sha256', $skill->getBody())``,
ADR-036 item 6). The sync's change test compares that value only
(``Classes/Service/Skill/SkillSyncService.php#getBodyChecksum() !== $checksum``).
The same sync also rewrites ``name``, ``description``, ``allowed_tools``,
``support_status`` and ``raw_frontmatter`` (:php:`SkillSyncService::apply()`).
An upstream change to the frontmatter alone is therefore an ``updated`` skill:
it stays enabled, and nothing records that the text the model reads changed.
``allowed_tools`` decides which tools a run may call, ``name`` heads the
composed section, and ``support_status`` decides whether asset references are
stripped from the body (:php:`SkillComposer::renderSection()`).

The ``body`` of ``tx_nrllm_skill`` is an editable text field, but
``body_checksum`` is read-only, so a body edit in the backend produces a skill
that is skipped on every call. ``readOnly`` is a FormEngine setting only; no
field of ``tx_nrllm_skill`` carries ``exclude``, so a DataHandler write by a
group with ``tables_modify`` would reach ``trust_level``, ``body_checksum`` and
``enabled`` (:ref:`ADR-169 <adr-169>`, section 1). A sync overwrites the record
in place, so no earlier version is kept.

.. _adr-214-context-loading:

3. Every attached body is sent in full, and the tail is dropped
---------------------------------------------------------------

Skills attach to a configuration and to a task through MM tables
(ADR-036 item 8). The admin playground can force further skills onto one run
(:php:`RunAugmentation`). A queued run persists those as uids only
(:php:`AgentRunRequestCodec`), so the worker composes whatever body the record
holds when it picks the run up. A suspended run replays the transcript stored
in :php:`SuspendedRunState`, which already contains the composed text, and
re-reads the forced skills by uid only for the ceiling check
(:ref:`ADR-165 <adr-165>`). Nothing records which version of a skill a run
was composed with. Every admitted body is composed in full. When the block
exceeds ``skills.maxBytes`` (default 24 000), whole skills are dropped from
the end, task skills first (ADR-036 item 5). There is no way to list a skill
without sending its body, to load it when it is needed, or to invoke one
explicitly.

.. _adr-214-context-snippets:

4. Snippets do not reach a caller that brings its own system message
--------------------------------------------------------------------

Prompt snippets (:ref:`ADR-031 <adr-031>`) are appended to the
``system_prompt`` option. :php:`MessageShaper::applySystemPrompt()` returns
the message list unchanged when it already holds a system message
(``Classes/Service/MessageShaper.php#if ($isSystem)``), and ADR-031 records
this as intended: "a caller-supplied system *message* still suppresses the
configuration's system prompt — and with it the snippet block". The chat of
the ``nr_mcp_agent`` extension builds its own system prompt
(``ChatService::buildSystemPrompt()``), which always starts with a fixed
identity text, and ``ChatService::runAgentTurn()`` prepends it as a system
message whenever it is not empty. Read together, the code says snippets never
reach the chat. This is inferred from the code of both extensions; no run was
measured.

Neither ``tx_nrllm_promptsnippet`` nor ``tx_nrllm_configuration.system_prompt``
is restricted to administrators, and :ref:`ADR-169 <adr-169>` section 2
recommends that non-administrators manage both tables. Whoever edits text that
reaches a system message holds an instruction-level privilege.

.. _adr-214-context-budget:

5. Text appended to an existing system message is not charged
-------------------------------------------------------------

:php:`ContextWindowManager::fit()` charges the configuration's system prompt
only when the first message is not a system message
(:php:`ContextWindowManager::missingSystemPromptTokens()` returns 0 otherwise),
plus the fenced skill block passed as ``$injectedText``. When the budget is
exceeded, :php:`ContextWindowManager::drop()` removes the oldest whole turns
(:ref:`ADR-107 <adr-107>`). Text that a later stage appends into an existing
system message is not charged at all, and a larger head is paid for by
evicting earlier turns, not by a refusal.

.. _adr-214-context-tools:

6. The tool allow-list is resolved from the configuration alone, live
---------------------------------------------------------------------

:php:`AllowedToolsResolver::resolve()` returns the union of the
``allowed-tools`` declarations of the effective skills, or ``null`` ("no
restriction") when none declares. :php:`ToolCallPolicy` calls it with the
configuration and no task
(``Classes/Service/Tool/ToolCallPolicy.php#$this->allowedTools->resolve($configuration)``),
in :php:`ToolCallPolicy::decide()` and in :php:`ToolCallPolicy::explain()`.
Task skills and forced skills therefore restrict nothing at run time, although
:ref:`ADR-038 <adr-038>` item 5 names the task. A resume re-resolves the list
live (:php:`ToolLoopService::resolveOfferedNames()`): if the only declaring
skill is disabled while the run waits, the resumed run gets ``null``, which
is every tool. The resolver's own docblock names the same widening for the
budget drop and rejects it there.

.. _adr-214-context-runs:

7. The run suspends for input or for approval, never for both
-------------------------------------------------------------

A tool can suspend a run in ``WAITING_FOR_INPUT`` and resume it with input
validated against the schema the tool declared (:ref:`ADR-105 <adr-105>`).
No production tool implements :php:`RequiresInputInterface` today; only test
fixtures do. A builtin that declares a write effect requires a human approval
(:ref:`ADR-134 <adr-134>`), and the approval binds to the preview lines the
approver saw: a resume recomputes them and bounces when they differ
(:ref:`ADR-184 <adr-184>`). ADR-134 bans a write tool that also requires input,
because the approval resume carries no input
(:php:`ToolLoopService::resume()` takes a boolean). A denial feeds the model a
fixed text that says who declined and nothing about why
(``Classes/Service/Tool/ToolLoopService.php#private function approvalDeniedResult(``,
:ref:`ADR-200 <adr-200>`). On a configuration with
``require_second_approver`` the run owner cannot approve their own write
(:ref:`ADR-172 <adr-172>`;
``Classes/Controller/Backend/AgentRunController.php#runs.error.selfApproval``).

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
two readers still need exactly that: the signed manifest and the legacy check
below.

**The same digest is used wherever this extension compares versions:**

- the sync's change test, so a frontmatter-only change is a change: an enabled
  skill is disabled and audited as ``ingest_disabled_on_change``
  (ADR-035 item 5), and no approval matches the new digest;
- the compose-time integrity check of a synced skill, which recomputes the
  digest from the stored fields and compares it with ``version_digest``
  (ADR-036 item 6, now covering the frontmatter fields as well);
- approvals, revocations and pins (items 2, 6).

**The signed manifest is the exception.** :php:`SkillSyncService` builds the
per-source manifest from body hashes
(``Classes/Service/Skill/SkillSyncService.php#$manifest[$sourcePrefix . $parsedSkill->path] = hash('sha256', $parsedSkill->body)``),
and :php:`SkillManifestVerifier` checks what upstream signed (ADR-061). That
format belongs to the upstream publisher and is not changed here, so a signed
manifest vouches for the body only; the frontmatter is covered by the digest
and the approval, not by the signature.

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
  (``Configuration/TCA/tx_nrllm_skill.php#'label' => 'LLL:EXT:nr_llm/Resources/Private/Language/locallang_tca.xlf:tx_nrllm_skill.name'``),
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
  therefore contributes the ``allowed_tools`` of the approved snapshot that
  matches its current digest. While its current version is unapproved, it
  contributes a declared empty list: it grants nothing, and it still counts as
  a declaration, so attaching an unapproved backend skill can never leave a
  run unrestricted.
- **Eight fields are excluded** with ``exclude => true``. On
  ``tx_nrllm_skill``: ``trust_level``, ``body_checksum``, ``version_digest``,
  ``enabled``, ``allowed_tools`` and ``source``. On ``tx_nrllm_skill_source``:
  ``trust_level`` and ``type``.
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
attachments default to ``on_demand``.

**A skill is named by its uid, not by its identifier.** Identifiers are unique
only per source; :php:`SkillComposer` already keys skills by source and
identifier because cross-source twins exist. Invocation, the load tool and the
catalogue use the skill uid; the identifier is display text.

**A body reaches a run in one of three ways:**

- **Always.** An ``always`` attachment is composed at run start, as today: an
  instruction skill as a system section, any other skill in the fenced block.
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
over, and that it is taken once.

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

**The load tool sits outside the skill allow-list.** It is exempt from the
skill-derived list and from the configuration's ``allowed_tool_groups``
gate (:php:`AllowedToolsResolver::applyGroupGate()`), so a skill declaring
``allowed-tools: []`` blocks every other tool but not the load tool. It still
passes the global tool state (ADR-039) — an administrator who disables it
switches model loading off — and the trust-zone gate. It reads only approved
skill text and has no effect outside the run's system message.

.. _adr-214-d6:

6. A conversation holds pinned instructions, and a process binds it
-------------------------------------------------------------------

**A frontmatter marker** (working name ``process: true``) declares a skill to
be a process. A process skill is started by explicit invocation only; it is
never in the model's catalogue, so page content cannot start a process. A
process skill whose current version is not an instruction cannot be started.

**Every instruction section is pinned, not only the process.** A pin is a
skill uid and a digest, recorded for each section — from an ``always``
attachment, an invocation, a load or a forced skill — on the run request and
in :php:`SuspendedRunState`. A pinned section is always composed from the
approval snapshot of its digest, not from the body the record holds by then.

**Pins outlive the run that created them.** A chat turn is a new run: the
chat rebuilds the system message and sends the history on every turn, and it
persists the final answer, not the tool messages
(``ChatService::runAgentTurn()``, ``ChatService::applyResult()``). A section
loaded in turn 3 would otherwise be gone from turn 4's system message. The
run result therefore reports every pin the run holds, and a continuation
request carries all of them (:ref:`item 10 <adr-214-d10>`). The runtime
re-composes each carried pin into the new run's system message.

**The pin rules, at start, at worker pickup, at every resume and at every
continuation.** A pin holds while an unrevoked approval for its digest and
source exists, its snapshot still hashes to the digest, the skill record
exists and is not orphaned, and the source's provenance is at or above the
threshold. When one fails, the run stops with a message that names the skill
and the reason. It does not continue without the section, and it does not
fall back to the fenced frame. On a resume after an approval, the check runs
before the approved write executes, next to the ADR-184 preview check, so a
write is never carried out under a revoked instruction.

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
dropping older turns (:ref:`ADR-107 <adr-107>`), so a section that passes can
always be sent. Otherwise nothing is appended: a load returns an error result
naming the skill, and a start fails before the first provider call with a
message naming the skill.

**A section the conversation already holds at the same digest is not
re-admitted.** A pin carried by a continuation is composed without a new
admission check. Without that rule a long tour would fail permanently once
its history grew past the headroom, although ``fit()`` could still send it by
dropping old turns.

``fit()`` itself is unchanged: it keeps dropping the oldest turns when the
transcript grows, and the system message, which carries the sections, is part
of the head it never drops.

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

- **the snippet's current text is marked by an administrator.** The mark
  (working name ``caller_system_digest`` on ``tx_nrllm_promptsnippet``,
  ``exclude => true``) stores a sha256 of the ``snippet`` text at the moment
  an administrator sets it. The snippet is appended only while the digest of
  its current text matches. An edit by anyone who may edit the ``snippet``
  field — under ADR-169 a non-administrator — therefore takes it out of the
  chat's system message until an administrator marks the new text; and
- **the configuration opts in** (working name
  ``snippets_in_caller_system_message`` on ``tx_nrllm_configuration``,
  ``exclude => true``, default off).

A mark on the record alone was rejected: the ``snippet`` field is not
excluded, so a non-administrator could keep a marked record and replace its
text. Admin-only TCA for the whole snippet table was rejected as well: it
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
  example "point 3 of 7"); the run exposes the last report through its events
  and status.
- **Highlight.** A read-only builtin names the record the current point is
  about. It accepts only targets the run registered from the invocation's
  subject record, for example the content elements of the selected page; the
  UI maps a target to its element. The model never supplies a CSS selector or
  any other markup.
- **One write per turn in a process run.** An approval decides the whole turn
  (:ref:`ADR-132 <adr-132>`; :php:`ToolLoopService::resume()` applies one
  decision to every pending call). In a run started with a process skill, a
  turn that requests more than one write-declaring call gets an error result
  for every write call after the first, before the run suspends, so the card
  shows exactly one proposal and one answer covers exactly one write. Read
  calls in the same turn are unaffected.
- **One approval card per proposal; approve means apply.** A proposal is the
  pending write call itself. Its approval card shows the preview lines of
  ADR-136 (current and proposed text), bound by ADR-184. The three answers
  map onto the one pause:

  - **"Übernehmen"** approves the call, which writes.
  - **"Andere Variante"** denies it with the reason ``variant``.
  - **"Überspringen"** denies it with the reason ``skip``.

  The reason is a closed type: a string-backed enum (working name
  :php:`ApprovalDenialReason`, cases ``variant`` and ``skip``) on
  :php:`ApprovalDecision`, which is an ``@api`` value object with a public
  constructor. The constructor rejects a reason together with
  ``approved = true``. The reason travels through
  :php:`ResumeCoordinator::approve()` into :php:`ToolLoopService::resume()`,
  and the denial result renders it through a ``match`` over the enum cases,
  as ``decided_by`` is rendered today, as a fixed token beside ``decided_by``
  (ADR-200). The model either proposes a new variant — a new write call and a
  new card — or records the point as open and moves on. A denial without a
  reason keeps today's text. No pause combines input and approval: the
  ADR-134 ban stays, and nothing about the answer is collected through the
  input path.
- **Writes need an approval, always.** No process skill, trusted or not, can
  switch that off (ADR-134).
- **The release stays on the chat surface.** For process runs the editor
  decides the card in the chat, as today. Where ``require_second_approver`` is
  set (ADR-172), the run owner cannot approve their own write; a denial stays
  theirs. "Andere Variante" and "Überspringen" therefore work as everywhere,
  and "Übernehmen" cannot complete in the chat: the card states that a second
  person must release the change in the Agent Runs inbox, the run stays
  ``WAITING_FOR_APPROVAL``, and the process shows the point as waiting for
  release. The chat reads the switch from the configuration to label the card
  before the editor presses it, and the server-side refusal stays.
- **An out-of-band release is handed back to the conversation.** Today a
  continuation that ran outside the chat leaves the conversation saying it
  "happened somewhere this conversation cannot see"
  (``ChatService::reconcile()``). For a process run that would leave the point
  shown as waiting for ever. The run therefore keeps its final answer, its
  last progress report and its pins readable by run uuid for the run's owner
  (:php:`AgentRuntimeInterface::status()` and ``events()``), and
  nr_mcp_agent's reconcile step, finding the run settled, appends that answer
  to the conversation and stores the pins, so the tour shows the point as
  applied and continues from there.
- **Pure choices use a choice builtin.** A choice without a write — which page,
  which finding first, whether to continue — is a ``WAITING_FOR_INPUT``
  suspension of a generic builtin that implements
  :php:`RequiresInputInterface` with an enumerated schema built from its
  arguments (ADR-105). It declares no write effect, so the ADR-134 ban does
  not apply to it, and it never stands in for the approval of a write.
- **Open points persist in nr_mcp_agent.** A skipped or unfinished point is
  stored outside the conversation, keyed by process skill uid, subject record
  and finding, in a table nr_mcp_agent owns, through tools nr_mcp_agent
  registers. They are offered again when the process is started on that
  record. nr_llm stores no open points.

.. _adr-214-d10:

10. The public API
------------------

Working names; the shapes are the decision.

- **Start with a skill.** :php:`AgentRunRequest` gains an optional skill
  invocation: the skill uid and an optional subject record (table and uid).
  :php:`AgentRuntimeInterface::run()` and ``enqueue()`` (``@api``) accept it.
  At start the runtime checks that the skill is attached to the configuration
  and enabled, that the request's actor may use the configuration, that the
  subject record is readable by that actor, and, for a process, that the
  version is an instruction.
- **The checks are actor-scoped.** :php:`LlmConfigurationService::hasAccess()`
  reads the global backend user, which is wrong for a run the worker executes
  after ``enqueue()`` and for an ``@api`` caller acting for someone else. The
  access check takes the request's :php:`AiActorContext` (working name
  ``hasAccessFor(AiActorContext, LlmConfiguration)``), and the subject-record
  check runs against the backend user the run executes as
  (:php:`ExecutionIdentity`), at start and again at worker pickup.
- **Continue with the pins.** The run result reports the pins the run holds
  (skill uid, source uid, digest). A chat continues a conversation with a new
  request that carries the transcript, the invocation and every pin it holds.
  Carried pins follow the pin rules of :ref:`item 6 <adr-214-d6>`, not the
  start rules: ``enabled`` is not required for them, revocation, a failing
  snapshot, an orphaned record or a provenance drop stops the run with the
  pin's message. Inside one run, ``approve()`` and ``submitInput()``
  continue as today.
- **Catalogue for slash commands.** An ``@api`` service returns the skills an
  actor may invoke on a configuration — uid, identifier, name, description,
  load mode, whether it is a process and whether its current version is an
  instruction — filtered by attachment, ``enabled``, the actor's access to the
  configuration (the actor-scoped check above), and optionally to process
  skills only. A backend AJAX route serves the same list as JSON for the
  slash-command menu. Descriptions of non-instruction skills are flagged as
  untrusted text for the consumer to escape.
- **Approval decision.** :php:`ApprovalDecision` gains the optional
  :php:`ApprovalDenialReason` of :ref:`item 9 <adr-214-d9>`; the rendered
  tokens sit on the ``@api`` :php:`ToolLoopServiceInterface` with the existing
  ``decided_by`` constants, so the API-surface snapshot guards them.
- **Builtins.** nr_llm ships the load tool, the progress and highlight
  builtins and the choice builtin; nr_mcp_agent ships the open-point tools.

.. _adr-214-d11:

11. Tasks keep their one-shot contract
--------------------------------------

A task stays one prompt and one answer. Process skills run in agent runs. No
workflow engine, step table or state machine is added.

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
``varchar(64)``, and the signed manifest and the legacy check still need the
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
revoked or with a different version of it: every instruction section is
pinned, carried from turn to turn, and checked at start, pickup, resume and
continuation.

● A loaded skill cannot widen the tool allow-list, a backend author cannot
widen it without an approval, and a resumed run cannot gain tools through the
skill allow-list.

● Instruction sections are no longer cut from the tail and are admitted
against the smallest send ``fit()`` can reach, so a section that does not fit
is refused visibly and a long tour does not strand.

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
- A process run that requests two writes in one turn gets an error for the
  second; outside process runs nothing changes.

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

✕ **A signed manifest vouches for the body only.** The frontmatter of a
signed source is covered by the digest and the approval, not by upstream's
signature.

✕ The work spans nr_llm (digest column and migration, compose path, approval,
load tool, run-start allow-list, pins and continuation, budget charge,
snippet path, denial reason, one-write rule, progress, highlight and choice
builtins, catalogue API, actor-scoped access check) and nr_mcp_agent (slash
commands, the approval card's three answers, carrying pins, the reconcile
hand-back, progress, highlight, open-point table and tools). The process use
case works only when both ship.

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
  configuration opt-in, :php:`ApprovalDenialReason` and ``hasAccessFor`` are
  working names.
- **Catalogue size.** Whether the catalogue needs its own byte cap, or the
  admission rule of :ref:`item 7 <adr-214-d7>` covers it.
- **Headroom.** Whether 10 % of the budget is the right reserve, measured on
  the first processes that run.
- **Flipping existing attachments to on_demand.** Whether and when existing
  ``always`` attachments move to ``on_demand``, which changes what an existing
  configuration sends.
- **More than one process per run.** This decision assumes one process skill
  per run.

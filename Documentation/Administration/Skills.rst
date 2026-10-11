.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-skills:

===============
Managing skills
===============

*Skills* are GitHub-hosted ``SKILL.md`` files — a YAML front-matter block
with a ``name`` and ``description`` plus a markdown body — that nr-llm can
ingest, review, and (from Plan 1b) inject into prompts. You add a **skill
source** that points at GitHub, sync it, and then enable the individual
skills you want.

Skill management is **admin-only**. It lives in
:guilabel:`AI > Authoring > Skills` and is not delegated to other
backend groups: a skill body becomes prompt context, so the two skill
tables are treated as a privilege-escalation surface.

.. note::

   Ingest — adding sources, syncing and reviewing — is described by
   :ref:`ADR-035 <adr-035>`. Attaching enabled skills to tasks and
   configurations and injecting them into text-generation prompts is
   described by :ref:`ADR-036 <adr-036>` and the
   :ref:`Attaching skills <administration-skills-attach>` section below.

.. _administration-skills-source-types:

Source types
============

A source has one of three types:

``single_file``
   One ``SKILL.md`` at a fixed path in a repository. A single, explicit
   admin act — its skill may default to enabled.

``repo``
   A whole repository. Every ``SKILL.md`` under the repo root,
   ``skills/<name>/``, ``.claude/skills/<name>/`` or
   ``<plugin>/skills/<name>/`` is discovered. Discovered skills arrive
   **disabled** for review.

``marketplace``
   An Anthropic ``marketplace.json`` index that lists plugins pointing at
   further repositories. Each entry is expanded with the ``repo`` flow.
   All discovered skills arrive **disabled**.

   The parser accepts a ``plugins`` array. A plugin source can be an
   ``owner/repository`` string, an object with a string ``repo`` of that
   form, or an object with a GitHub ``url``. A string ``repo`` takes
   precedence over ``url``. GitHub URL host matching is case-insensitive;
   the first two path segments identify the repository and a trailing
   ``.git`` suffix is removed. Unsupported entries are skipped while
   later supported entries are still processed. A plugin's string ``ref``
   is passed to its repository sync; an absent or non-string ``ref`` uses
   ``HEAD``. An empty string is normalized to ``HEAD`` by that sync.

.. _administration-skills-add:

Adding a source
===============

1. Navigate to :guilabel:`AI > Authoring > Skills`.
2. Click :guilabel:`New Skill Source`.
3. Fill in the fields:

   :guilabel:`Title`
      Display name for the source list.

   :guilabel:`Type`
      ``single_file``, ``repo`` or ``marketplace`` (see above).

   :guilabel:`URL`
      The GitHub URL the type expects (the ``SKILL.md`` URL, the
      repository URL, or the ``marketplace.json`` URL). For a marketplace,
      a plain repository URL uses ``.claude-plugin/marketplace.json``
      at ``HEAD``; a raw or blob index URL specifies its own path and ref.

   :guilabel:`Ref`
      For ``single_file`` and ``repo``, a branch, tag or commit (for
      example ``main`` or ``v1.2.0``); an empty value uses ``HEAD``.
      It is resolved once to an immutable commit SHA, and the skill
      bodies are fetched by that SHA.

      A ``marketplace`` source does not use this field. A raw or blob
      index URL is fetched at the URL's ref. A plain repository URL
      instead resolves ``HEAD`` and fetches the conventional index path
      by that commit. Each plugin repository is resolved separately from
      that plugin's ``ref`` or ``HEAD``, and its skill bodies use the
      resulting commit.

4. Click :guilabel:`Save`.

The ``pinned_sha``, ``sync_status``, ``sync_error`` and ``last_synced``
fields are managed by the sync run and shown read-only.

.. _administration-skills-token:

GitHub token and rate limits
============================

Unauthenticated GitHub API access is limited to **60 requests per hour**,
which is quickly exhausted by a ``repo`` or ``marketplace`` sync. Add a
personal access token (a read-only, public-repo token is enough) to raise
the limit and to read private repositories.

- The token is set through the :guilabel:`Set token` action on a source,
  **not** typed into a FormEngine field. It is stored as an nr-vault UUID
  (envelope-encrypted), mirroring provider API-key storage — never as
  plaintext in TCA, YAML or the database.
- When a sync hits the rate limit (HTTP 403 with no remaining quota), the
  source is set to ``sync_status = error`` carrying the reset time; state
  is not partially corrupted. Add a token and re-sync.

.. _administration-skills-allowlist:

Host-allowlist prerequisite
===========================

nr-llm enforces an **app-level GitHub allowlist** on every skill request:
the scheme must be ``https`` and the host must be one of ``github.com``,
``raw.githubusercontent.com``, ``api.github.com`` or
``codeload.github.com``. This is separate from, and in
addition to, the nr-vault SSRF guard.

On hardened instances that restrict outbound HTTP through the global
``HTTP/allowed_hosts`` SSRF setting, those four GitHub hosts **must be on
that list**, otherwise every sync fails closed. This is a deliberate
prerequisite — nr-llm never silently bypasses the SSRF guard.

.. _administration-skills-review:

Syncing and the review flow
===========================

.. figure:: /Images/SkillsModule.png
   :alt: The Skills module showing a synced marketplace source and the
       discovered skills with their support badge and enabled state
   :class: with-border with-shadow
   :zoom: lightbox

   The Skills module — the :guilabel:`Sources` table (type, sync status,
   last synced, per-source actions) above the discovered :guilabel:`Skills`
   with their ``partial`` / ``full`` support badge and enabled state.

1. On a source, click :guilabel:`Sync`. The source moves through
   ``never_synced`` → ``syncing`` → ``ok`` / ``partial`` / ``error``.
   The ``syncing`` state also acts as a lock: a second concurrent sync on
   the same source is refused.
2. ``partial`` means the per-sync file-count or wall-time bound was
   reached (large marketplaces); the skills fetched so far are stored.
3. Discovered skills from ``repo`` and ``marketplace`` sources are
   created **disabled by default**. Review each one, then toggle it on
   with :guilabel:`Enable`.
4. **Re-sync never silently changes an enabled skill.** If a re-sync
   brings a different version of an enabled skill, nr-llm **auto-disables
   it** so you re-confirm before it is used again. A version is the body
   *and* the frontmatter fields the model reads — name, description,
   support status, ``allowed-tools`` and the ``process`` marker — so a
   frontmatter-only change upstream, such as a widened ``allowed-tools``,
   disables the skill as well (:ref:`ADR-214 <adr-214>`).
5. A skill that disappeared upstream is marked **orphaned and disabled**,
   never silently dropped, so attachments (Plan 1b) do not vanish.

Deleting a source cascade-deletes its skills.

.. _administration-skills-support-status:

The ``partial`` support badge
=============================

Each skill carries a support badge:

``full``
   The skill is plain front-matter and prose.

``partial``
   The body or front-matter references scripts, ``references/``,
   ``assets/`` or an ``allowed-tools`` declaration.

.. warning::

   ``partial`` is **not** a "safer content" badge. It only signals that
   the referenced scripts and assets are **not executed** by nr-llm
   (which is true for every skill in this release). The prose itself is
   fully untrusted regardless of the badge. Asset references are stripped
   from injected prose purely to avoid dangling instructions, not as a
   security control.

See :ref:`ADR-035 <adr-035>` for the full design and security rationale.

.. _administration-skills-attach:

Attaching skills and injecting them into prompts
================================================

Enabled, non-orphaned skills can be attached to a **Task** and/or an
**LLM configuration** via the :guilabel:`Skills` field on those records
(only enabled skills are offered). At execution time, for text-generation
operations only — completion, translation and task execution; **never**
embeddings, vision or speech — nr-llm composes the attached skills into a
delimited block and prepends it to the *user* prompt. The configuration
``system_prompt`` is never modified.

.. note::

   Injection is **eager and complete**, not on demand. The whole skill
   **body** — the entire ``SKILL.md`` prose after the front-matter, not just
   the ``name``/``description`` — is written into the prompt *before* the model
   runs. Unlike a :ref:`tool <administration-tools>`, a skill is **not**
   something the model calls or fetches when it decides it needs it: there is no
   runtime round-trip that loads a skill's body, and none that loads its
   ``references/`` / ``scripts/`` / ``assets/`` (those lines are stripped from
   ``partial`` skills, and the files are never executed). An attached skill
   therefore always costs its full body in tokens on every run (subject to the
   budget below).

   *Planned direction (not in this release):* a progressive-disclosure mode
   that injects only the ``description`` and lets the model pull the full body
   or a referenced file on demand — the same shape as the tool runtime.
   Executing a skill's bundled scripts or assets is a separate, harder step and
   is not on the near-term roadmap.

Composition rules:

- **Precedence.** Configuration skills are the baseline, task skills are
  additive; the set is the union deduped by source + identifier (the
  configuration wins on a duplicate). The configuration block renders
  first.
- **Budget.** The block is bounded by a conservative character budget;
  when it is exceeded, task-additive skills are dropped before
  configuration-baseline skills and each drop is logged.
- **Integrity.** Each skill's version digest — over the body and the
  frontmatter fields the model reads — is recomputed from the stored fields at
  injection time; a mismatch (tampering or a stale row) drops that skill — it
  is never injected. A row synced before the digest existed is checked
  against its body checksum until the upgrade wizard or a sync writes its
  digest. Name, description and body are therefore read-only in FormEngine.
- **Process skills.** A skill whose frontmatter sets ``process: true`` is
  never composed from an attachment; it is skipped with a warning.
- **Untrusted output.** Skill prose is third-party text; output produced
  under its influence is treated as untrusted and escaped/sanitized where
  it is stored or rendered. Message role is defense-in-depth, not a trust
  boundary.

See :ref:`ADR-036 <adr-036>` for the injection design.

.. _administration-skills-approval:

Approving a skill version as an instruction
===========================================

By default every skill is untrusted reference data: it is fenced in the user
message and the model is told not to follow it as instructions. An
administrator can approve one **version** of a skill so that it is composed
into the **system message** as an instruction of the installation, behind the
configuration's own prompt and snippets — or appended to the caller's system
message when the caller sends one (:ref:`ADR-214 <adr-214>`).

1. In the :guilabel:`Skills` list, click the check-mark action of a skill.
   The review page shows the current version, its version digest, the
   source's trust level and the instruction trust level, and — once a version
   was approved — the diff against the most recent approved version.
2. Click :guilabel:`Approve this version`. The form sends the digest it
   showed; if the skill changed in the meantime, the approval is refused and
   the page shows the new state.
3. To withdraw an approval, click :guilabel:`Revoke this version` in the
   approval history. A revoked version becomes an instruction again only
   through a new approval, never because a sync brought the same text back.

A version instructs only while **both** hold:

- an unrevoked approval names exactly this version (digest) of this skill
  from its current source — any change to the body or a covered frontmatter
  field, or moving the skill to another source, needs a new approval; and
- the source record's trust level is at or above
  ``skills.instructionTrustLevel`` (extension configuration, default
  ``verified``). A value below ``skills.minTrustLevel`` is read as that
  minimum; a value nobody can read is read as ``first_party``. The level is
  read from the source record at every composition, so re-classifying a
  source takes effect at once. A hidden or disabled source vouches for
  nothing.

A run that waits for a human — for a write approval or for typed input —
keeps the approved versions it was composed with. Before it continues, it
checks each of them again: if the approval was revoked, the skill was deleted,
disabled, hidden or orphaned, or the source fell below ``skills.instructionTrustLevel`` in the
meantime, the run stops with an error that names the skill and the reason, and
no pending call executes. Start a new run to continue without that
instruction.

The fields that decide this — on a skill its source, trust level, checksums,
version digest, enabled and hidden flags, allowed tools, support status,
orphan flag and stored frontmatter; on a source its type, trust level, hidden
and enabled flags, URL, ref, pinned SHA, expected fingerprint and access
token — are :guilabel:`exclude` fields. A non-admin group reaches them only
when an administrator grants them under :guilabel:`Allowed excludefields`.

A version that contains characters a browser renders as nothing but a model
reads — Unicode tag characters, zero-width and direction marks, no-break
spaces and similar — cannot be approved: the approval would bind to text the
approver did not see. The review page lists each such character with its field,
line and code point. Remove them at the source and sync again.

Approving and revoking is restricted to administrators. Every approval,
revocation and refused approval is written to the skill audit trail with the
version digest it concerns. A skill without a version digest yet (synced
before the upgrade, and not yet migrated) cannot be approved: run the upgrade
wizard :guilabel:`Write the version digest onto existing skills` or sync its
source first.

.. warning::

   An approved skill is an instruction with the acting user's reach: it can
   steer which tools the model calls, within the run's allow-list and the
   user's TYPO3 permissions. Write tools still need a human approval for every
   call; no skill can switch that off.

.. _administration-skills-isolation:

Isolation controls: trust, fingerprint, injection scan, audit
=============================================================

On top of the SHA-pin and checksum controls above, each source and skill
carries the isolation controls introduced in :ref:`ADR-061 <adr-061>`.

**Publisher trust level.** Every source is classified — ``untrusted`` (the
default, for anonymous public GitHub content), ``community``, ``verified`` or
``first_party`` (operator-controlled). This is *provenance*, independent of the
``partial`` support badge. Each synced skill denormalises its source's level, so
re-classifying a source takes effect on the next sync. The instance-wide floor
``skills.minTrustLevel`` (extension configuration, default ``untrusted``) gates
use: a skill below the floor is dropped from **both** prompt injection and the
allowed-tools union. Raising the floor to ``verified`` therefore hides every
community/untrusted skill without deleting it. Trust is *separate from* the
``enabled = false`` default — an ``untrusted`` skill still needs an explicit
enable. An unreadable or mistyped floor falls back to ``untrusted``; the value
actually in force is shown under :ref:`administration-governance`.

**Skill-block byte budget.** ``skills.maxBytes`` (extension configuration,
default ``24000``) caps the composed block that is prepended to the user
prompt. The measure is bytes, not tokens — a deliberate over-estimate, since no
tokenizer is available. When the block exceeds the budget, skills are dropped
from the tail first: task-additive skills go before the configuration baseline,
and every drop is logged as a warning. Lower the value to reserve more of the
model's context window for the conversation itself; raise it if a large
configuration baseline is being trimmed. An empty, non-numeric or zero value
falls back to ``24000`` — the cap cannot be switched off, so an emptied field
never puts an unbounded block on the wire. The budget is instance-wide and
independent of the model's context window; the per-request window bound is
handled separately by the context-window manager (:ref:`ADR-107 <adr-107>`).

The budget bounds the prompt block only, **not** the ``allowed-tools`` union
(:ref:`Gating tools <administration-tools-allowed>`). The union is computed
over the effective skills before the block is assembled, so a skill dropped
for the budget still grants its tools while its usage rules stay out of the
prompt. That is deliberate — a budget-aware union would *widen* the gate,
because dropping the last declaring skill removes the restriction entirely.
Watch the drop warnings when lowering the value: they name every skill whose
prose stopped shipping while its tools kept being offered.

**Manifest fingerprint (optional).** A source may declare an
``expected_fingerprint``: the sha256 its whole skill set must hash to. When set,
the digest is recomputed at sync and verified before anything is materialised; a
mismatch fails closed (no skill is imported, the source goes to ``error``) and
leaves the last known-good skills untouched. Leave it empty to rely on the
commit-SHA pin alone. This binds the reviewed bytes to a publisher-declared
identity beyond "these bytes from this URL"; it is a declared digest, not a
public-key signature.

**Prompt-injection scan.** Each body is scanned at ingest for known injection
signatures. A **high-confidence** jailbreak marker (e.g. "ignore all previous
instructions", role reset, chat-template control tokens) force-disables the
skill at import — even a single-file source that would otherwise default
enabled — and must be re-reviewed before enabling. Lower-confidence findings are
recorded on the skill (``Injection scan findings``) for review without blocking.

**Immutable audit trail.** Every ingest, enable, disable and fail-closed
rejection is written to ``tx_nrllm_skill_audit`` with who / when / source / SHA /
checksum / trust level / scan result. The trail is append-only — the application
never updates or deletes a row — so the provenance of any skill that can reach a
prompt is reconstructable after the fact.

.. include:: /Includes.rst.txt

.. _adr-035:

==================================================================
ADR-035: Skill ingest (GitHub-hosted SKILL.md sources)
==================================================================

:Status: Accepted
:Date: 2026-06-27
:Authors: Netresearch DTT GmbH

.. _adr-035-context:

Context
=======

Editors want to reuse the growing ecosystem of Claude Code *skills* —
``SKILL.md`` files with YAML front-matter (``name`` + ``description``)
and a markdown body — inside nr-llm. These live on GitHub as a single
file, as a whole repository (many ``SKILL.md`` under ``skills/``,
``.claude/skills/`` or ``<plugin>/skills/``), or behind an Anthropic
``marketplace.json`` index that points at further repositories.

Fetching attacker-influenced markdown from the public internet and later
feeding it into an LLM prompt raises two separate concerns that are easy
to conflate:

1. **Server-Side Request Forgery.** The existing nr-vault transport
   (``vault->http()``) already blocks internal/private/metadata targets.
   That guard is about *where* a request may go, not *who* owns it.
2. **Supply-chain origin and integrity.** Even a non-SSRF target must be
   a real GitHub host, and the bytes we store must be the bytes we
   reviewed — a moving branch ref can change content under us.

This ADR records the decisions for **Plan 1a — ingest only**. Skills are
parsed, materialized and reviewed, but **not yet injected** into prompts;
injection, the MM attach tables, and checksum-verify-on-injection are
deferred to Plan 1b.

.. _adr-035-decision:

Decision
========

1. **Dedicated entities, not extended snippets.** Two new Extbase
   entities — :php:`SkillSource` (table ``tx_nrllm_skill_source``) and
   :php:`Skill` (table ``tx_nrllm_skill``) — model the ingest domain.
   A skill is a materialized ``SKILL.md``; a source produces N skills.
   Reusing :php:`PromptSnippet` (:ref:`adr-031`) was rejected: snippets
   are editor-authored fragments, skills are synced remote artifacts with
   their own lifecycle (sync status, checksum, orphaning).

2. **Ingest / use split.** Unit 1 is split at the MM-table seam into
   Plan 1a (this ADR: sources, fetch, parse, review) and Plan 1b
   (attach + inject). Each ships fully implemented, no stubs.

3. **SSRF guard ≠ GitHub-origin guard.** On top of the nr-vault SSRF
   guard, :php:`GitHubClient` enforces an **app-level GitHub host
   allowlist**: ``scheme = https`` AND host ∈ ``{github.com,
   raw.githubusercontent.com, api.github.com, codeload.github.com}`` on
   the initial URL and every redirect target. A rejected URL raises a
   typed :php:`HostNotAllowedException` — never a silent skip.

4. **Fetch by immutable commit SHA + checksum.** A source ``ref``
   (branch/tag) is resolved once to a commit SHA via
   ``GET /repos/{o}/{r}/commits/{ref}``; the stored ``pinned_sha`` is the
   URL all bodies are fetched from (``raw.githubusercontent.com`` by
   SHA, never by branch). A ``body_checksum`` (sha256) is computed at
   materialization and re-verified on injection in Plan 1b (fail-closed).

5. **Disabled-by-default for multi-skill discovery.** Every ``repo`` and
   ``marketplace`` skill arrives ``enabled = false`` and must be reviewed
   before use. A ``single_file`` source — one explicit admin act — may
   default enabled. Re-syncing an **enabled** skill whose recomputed
   ``body_checksum`` changed **auto-reverts it to disabled** and surfaces
   the diff for re-confirmation.

6. **Namespaced upsert, orphan-disable.** ``identifier`` is namespaced
   ``"{source_uid}:{path}"`` so identical skill names across sources never
   collide. Re-sync is upsert-by-(source, identifier); a skill that
   disappeared upstream is marked **orphaned + disabled**, never silently
   dropped.

7. **Admin-only management.** Sources and skills live in a new
   ``nrllm_skills`` ``access = admin`` backend submodule. The two tables
   are an escalation surface (the body becomes prompt context in 1b) and
   must never be granted to non-admin backend groups; sync-managed TCA
   fields (``body_checksum``, ``source_sha``, ``raw_frontmatter``,
   ``support_status``, ``identifier``) are read-only and ``github_token``
   is never shown in a FormEngine form.

8. **String-backed enums + bounded JSON.** :php:`SkillSourceType`,
   :php:`SyncStatus` and :php:`SupportStatus` are string-backed with
   ``values()`` / ``isValid()`` / ``tryFromString()`` (the project's
   Defensive-Enum rule). ``raw_frontmatter`` and the reserved
   ``allowed_tools`` JSON are byte- and shape-bounded at parse time even
   though ``allowed_tools`` is ignored in 1a.

9. **Explicit ``symfony/yaml`` dependency.** Front-matter is parsed with
   ``Symfony\Component\Yaml\Yaml``; the package is added to
   ``composer.json`` ``require`` explicitly rather than relied on
   transitively.

.. _adr-035-consequences:

Consequences
============

- ● Admins reuse the GitHub skill ecosystem from inside the backend, with
  SHA-pinned, checksum-verified, host-allowlisted fetches.
- ● The SSRF guard and the GitHub-origin allowlist are independent
  controls, stated and tested separately — neither masks the other.
- ● Disabled-by-default plus auto-disable-on-change means no remote
  content silently enters a prompt: every enable is a deliberate admin
  review, and an upstream change re-opens that review.
- ● Orphan-disable (never drop) keeps attached skills (Plan 1b) from
  vanishing under an editor and makes upstream deletions visible.
- ◐ Two more domain entities and a new submodule increase surface area;
  the split from :php:`PromptSnippet` is intentional and documented here
  and in the administration guide.
- ◐ On hardened instances the global ``HTTP/allowed_hosts`` SSRF list
  must include the four GitHub hosts, or every sync fails closed — a
  deliberate, documented prerequisite.
- ✕ ``support_status = partial`` is **not** a safety signal. It only
  flags that referenced scripts/assets are not executed (always true in
  1a); the prose stays fully untrusted. The injection-time output
  integrity controls land in Plan 1b.

.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=====================================
Importing and reviewing skill sources
=====================================

.. _administration-skills-source-types:

Source types
============

Imported sources have three types; local backend authoring is the fourth
way to create a skill and does not require a source:

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

Sync errors shown by the module and stored on the source mask credential
query parameters and URL userinfo passwords. Fatal-error diagnostics retain
the exception class, source uid and sanitized message and source URL; they
do not retain the raw exception object.

.. _administration-skills-token:

GitHub token and rate limits
============================

Unauthenticated GitHub API access is limited to **60 requests per hour**,
which is quickly exhausted by a ``repo`` or ``marketplace`` sync. Add a
personal access token (a read-only, public-repo token is enough) to raise
the limit and to read private repositories.

- The token is set through the :guilabel:`Set token` action on a source,
  **not** typed into a FormEngine field. The source stores a vault reference;
  nr-vault stores the token with envelope encryption. The plaintext token
  is never stored in the source record.
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
   Synchronization requires a saved, non-deleted source. The database row
   carries an opaque lease owner, so a second caller is refused even when its
   loaded source still has an older status. A missing or soft-deleted source
   returns ``error`` before remote requests or skill writes.
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
   disables the skill as well (:ref:`ADR-214 <adr-214>`). The ``page`` key
   is not part of the version: a change of it alone is stored and disables
   nothing (:ref:`administration-skills-page`).
5. A skill that disappeared upstream is marked **orphaned and disabled**,
   never silently dropped, so attachments do not vanish.

The source, skill and skill-audit tables must use the same database connection.
Remote collection runs outside the publication transaction. A conditional
ownership check then fences skill changes, orphaning, audit writes and source
completion in one short transaction. Losing the lease during a slow remote
fetch returns ``error`` and publishes none of the collected changes; an older
worker cannot clear a replacement owner's lease. A publication failure rolls
back its changes and reports zero change counters.

A ``syncing`` row is interrupted when its heartbeat is zero or differs from
the current time by more than 180 seconds, including excessive future clock
skew. The exact 180-second boundary is still live. Opening the source list
rechecks the persisted row and changes an interrupted lease to retryable
``error``; a renewed or completed row is preserved. Trigger :guilabel:`Sync`
again to retry. Lease ownership fields stay internal and are not editable in
FormEngine (:ref:`ADR-221 <adr-221>`).

GitHub may mark an oversized recursive tree response as ``truncated``.
Such a listing is rejected before any skill file is fetched: it cannot prove
that omitted paths were removed. A ``repo`` source reports ``error`` and
preserves its existing skills and pinned SHA. For a ``marketplace`` source,
the affected child repository reports a partial sync; its existing skills
remain unchanged, and other reachable child repositories can still sync.

Malformed GitHub JSON responses are refused with the client's typed response
error. Diagnostic logging is best effort and cannot replace that refusal.

Deleting a source cascade-deletes its skills.

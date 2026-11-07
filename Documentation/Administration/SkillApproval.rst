.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=====================================
Approving and revoking skill versions
=====================================

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
approver did not see.
The review page lists the first 20 findings with their field, line and code point,
or identifies a field whose text is not valid UTF-8, and counts further findings.
Remove them at the source and sync again.

Approving and revoking is restricted to administrators. Every approval,
revocation and refused approval is written to the skill audit trail with the
version digest it concerns. A skill without a version digest yet (synced
before the upgrade, and not yet migrated) cannot be approved: run the upgrade
wizard :guilabel:`Write the version digest onto existing skills` or sync its
source first.

An approval snapshot keeps an omitted tool declaration distinct from a
declared empty list. A malformed or non-list JSON declaration in a stored
snapshot is read as an empty list, so object values cannot become tool names.

.. warning::

   An approved skill is an instruction with the acting user's reach: it can
   steer which tools the model calls, within the run's allow-list and the
   user's TYPO3 permissions. Write tools still need a human approval for every
   call; no skill can switch that off.

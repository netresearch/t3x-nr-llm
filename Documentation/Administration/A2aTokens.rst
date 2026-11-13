.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

..  include:: /Includes.rst.txt

..  _administration-a2a-tokens:

=================================
A2A tokens in the backend
=================================

The module :guilabel:`AI > A2A tokens` issues, shows and revokes the bearer
tokens of the :ref:`A2A server role <administration-a2a-server>`
(:ref:`ADR-294 <adr-294>`). Administrators use it for every client record.
Editors use it, when an administrator allows it, for tokens that act as
their own account. The command ``nrllm:a2a:client:token`` stays for
installations without a browser.

..  _administration-a2a-tokens-admin:

For administrators
==================

The module lists every client record: title, kind (technical user or an
editor's own account), owner, creation, expiry, status and the time of the
last task. Reaching it takes the module ticked in the group's module list;
an administrator needs no more.

Generate token, Rotate token
   Per row of a client record with a technical backend user. The token is
   shown once in a panel above the list, with a :guilabel:`Copy` button.
   It is not stored, not logged and not shown again. Rotating ends the
   previous token at once; the client keeps its tasks, which belong to the
   record. The route that does this checks the administrator flag on the
   server and carries the core's request token, so another site cannot
   trigger it.

Revoke
   Per row of any record. The record is disabled and its token hash is
   deleted: the token stops working at once and cannot come back. Use the
   :guilabel:`Enabled` field of the record to switch a client off
   temporarily instead.

An administrator cannot generate or rotate the token of an editor's own
record, and ``nrllm:a2a:client:token`` refuses it as well. Such a token acts
as the editor, and only the editor issues it. An editor holds at most ten
active tokens (enabled, with a token, not expired); a revoked or expired token
does not count.

Every issuing, rotation and revocation is logged at level ``info`` by the
channel of ``A2aTokenAudit`` with the actor, the record and the time. TYPO3
drops that level by default, so the extension writes the channel to its own
file, ``var/log/typo3_nrllm-a2a-token-audit_*.log``; an installation changes
that through ``$GLOBALS['TYPO3_CONF_VARS']['LOG']`` like any other channel. In
switch-user mode the administrator who switched is named as well. A token
and its hash are never part of a log line.

..  _administration-a2a-tokens-self-service:

Letting editors issue their own tokens
======================================

An editor may create A2A tokens that act as their own backend account, see
them, and revoke them. Nobody else's. This is off until an administrator
acts, and it is limited by default.

#.  On the editor's backend group, tick the permission
    :guilabel:`Issue A2A tokens for my own account`
    (``a2a_token_self_service``) under :guilabel:`AI (nr_llm) permissions`,
    and the module :guilabel:`A2A tokens` in the group's module list. The
    module switch alone grants nothing.
#.  On the same group, open the tab :guilabel:`A2A tokens` and set the
    limits below.

Without step 2 the permission allows a token that runs in a draft
workspace, expires within 90 days, and calls no profile. Such a token can
do nothing, so the editor sees that an administrator has to allow a profile
first.

..  _administration-a2a-tokens-limits:

The limits
----------

All four are fields of the backend group. A backend group is an
administrator-only record, so an editor cannot relax their own limits.
Every field starts at its strict value.

..  list-table::
    :header-rows: 1
    :widths: 28 22 50

    *   - Field
        - Default
        - Meaning
    *   - :guilabel:`Allow tokens in the live workspace`
        - Off
        - Off: the token must be pinned to a draft workspace the editor can
          work in, so what an agent changes is a draft the editor reviews.
          On: a token may be pinned to live. A token in live cannot call a
          writing profile.
    *   - :guilabel:`Maximum validity of a token (days)`
        - 0 (means 90)
        - How far ahead the expiry may lie. At most 730, whatever the
          database holds.
    *   - :guilabel:`Allow tokens that never expire`
        - Off
        - Off: every token needs an expiry within the maximum. On is an
          explicit choice: such a token is a permanent credential without a
          login.
    *   - :guilabel:`A2A profiles tokens may call`
        - Empty
        - The publication profiles a token may be allowed. Empty: none.

The draft workspace needs the system extension ``workspaces``. Without it
there is only live, and tokens exist only where a group allows live.

Why these are fields on the group, and not user TSconfig, is recorded in
:ref:`ADR-294 <adr-294>`: the form validates them, they sit next to the
permission, and a misspelled TSconfig key would read as "not set".

When a user has several groups
------------------------------

The limits are merged over the groups of the user that **hold the
permission**; TYPO3 resolves them, subgroups included. A group that sets a
relaxation but does not hold the permission contributes nothing.

- Live is allowed when any of these groups allows it.
- The maximum validity is the largest value any of them sets. A group that
  sets none does not widen what another sets; if none sets one, it is 90.
- No expiry is allowed when any of them allows it.
- The profiles are the union.

..  _administration-a2a-tokens-enforcement:

When a limit is checked
-----------------------

Twice, on the server, and never by the form alone.

When the token is created
   A request beyond a limit is refused and says which. The editor cannot
   pick another owner: the owner is the signed-in user. The workspace must
   be one the editor can work in.

On every request
   The limits are read from the owner's groups as they are now. A token
   whose owner lost the permission, became an administrator, cannot work in
   the pinned workspace any more, or whose expiry lies beyond the maximum,
   is refused like an unknown token. The same check runs when a worker
   starts a queued run of the token and on every resume of a suspended run.
   A run that is already executing is not interrupted; it stops at its next
   resume. A profile the groups no longer allow is taken out of the token,
   and the remaining profiles stay callable. When the groups allow it again,
   the token works again.

Lowering the maximum validity affects tokens that already exist: a token
whose expiry now lies further ahead than the maximum is refused until the
maximum is raised again. The editor creates a new token with a shorter
validity.

The module shows such a token as :guilabel:`Withdrawn`, with the reason.

Existing clients with a technical backend user are not affected by any of
this.

..  _administration-a2a-tokens-security:

What a token means for security
===============================

..  warning::
    A bearer token **bypasses the interactive login of the backend**,
    including multi-factor authentication and passkeys. Whoever holds the
    token acts as its owner, with everything the owner's permissions allow
    in the pinned workspace and the allowed profiles, until it expires or is
    revoked.

That is why the defaults are what they are:

- **Expiry is mandatory.** A token that is forgotten in a script or a chat
  stops working on its own, within 90 days unless an administrator raised
  the maximum.
- **A draft workspace is the default.** An agent that acts with the
  editor's rights writes drafts that the editor publishes, not live content.
- **No profile by default.** A token can call only what an administrator
  published for that group.
- **Revocation is immediate**, by the editor or by an administrator, and
  the token itself is never stored: the database holds its prefix and its
  SHA-256.

Treat a token like a password: paste it only where the agent reads it.

Not implemented: OAuth 2.0 Authorization Code
---------------------------------------------

The better long-term answer is the OAuth 2.0 Authorization Code flow with
PKCE. The editor would then authorise the agent interactively; the login,
multi-factor authentication included, happens at that step, and the access
the agent gets can be cancelled by the group. It needs an authorisation
endpoint that nr_llm does not have, and it is recorded in
:ref:`ADR-294 <adr-294>` as a possible later step.

..  _administration-a2a-tokens-editor:

For editors
===========

Open :guilabel:`AI > A2A tokens`. You see your own tokens and nobody
else's.

#.  Give the token a title, for example the agent that will use it.
#.  Choose the workspace, the validity in days and the profiles. Only what
    your groups allow is offered, and the limits are checked again when you
    create the token.
#.  Choose :guilabel:`Create token`. The token appears once. Copy it with
    the button or by hand, then choose :guilabel:`I have stored the token`.
    If you lose it, revoke it and create a new one.

The column :guilabel:`Last task` shows when the newest task of the token was
created. A token that only reads existing tasks does not show up there.

Choose :guilabel:`Revoke` to end a token at once.

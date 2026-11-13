.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-294:

====================================================================
ADR-294: An editor may issue A2A tokens that act as themselves
====================================================================

:Status: Accepted
:Date: 2026-11-13
:Amends: :ref:`ADR-280 <adr-280>`
:Authors: Netresearch DTT GmbH

Context
=======

:ref:`ADR-280 <adr-280>` made every inbound A2A client an administrator's
record: a technical backend user, a pinned workspace, a profile list, and a
token that only the CLI command could issue. Two things were missing in
practice.

An administrator who has no shell on the server cannot issue or rotate a token.
And a team that wants to let an editor connect an agent of their own, acting as
that editor, has to ask an administrator to create a technical user per
editor, with the editor's groups copied by hand, and to keep the copy in step.
ADR-280 considered that second case under "Map a client to an editor's
account" and rejected it because the client would inherit everything the
editor may do and the editor's own changes would change it.

Both objections stand. This record accepts the first one as the price of the
feature and answers the second one by making the change of rights the
intended behaviour: a self-service token holds exactly what its owner's groups
allow *now*, and loses it when they stop allowing it.

A bearer token also bypasses the interactive login of the backend, including
multi-factor and passkey authentication. That is inherent in a bearer token
and was already true for technical users; for a token that acts as a person
it is the main risk, and the defaults below are chosen against it.

Decision
========

**An administrator issues and rotates a token in the backend.** A module
``nrllm_a2atokens`` lists the client records. Its rows carry "Generate token"
and "Rotate token" for administrators. Both call
:php:`A2aInboundClientRepository::issueToken()`, as the CLI command still does,
through a backend AJAX route that checks the administrator flag itself. The
core's route token binds the POST to the route, so a forged request from
another site is refused before the controller runs. The token is returned once,
in the response, with ``Cache-Control: no-store``; it is neither a flash
message (those are kept in the session) nor part of a redirect. Every issuing
and rotation is logged with the actor, the original user in switch-user mode,
the record and the time, never with the token.

**A self-service token is a client record with an owner.** The record gets a
flag ``self_service``; its ``be_user`` is the editor who created it, taken from
the session and never from the request. The table stays ``adminOnly`` in TCA:
an editor cannot open it in the form engine, so ``be_user`` cannot be edited
by an editor. Editors reach their records only through the module, and
:php:`A2aSelfServiceTokens` is the one writer. ``A2aServerRecordSaveHook``
strips the flag from a DataHandler write, so an administrator's form cannot
turn a technical-user record into a self-service one or the reverse. The
owner of the tasks stays the record's uid, as in ADR-280.

**The permission is a custom permission nobody has.** A new
:php:`BackendUserGrant::A2A_TOKEN_SELF_SERVICE` case, assigned per backend
group through ``customPermOptions`` like the existing grants. No group holds
it after installation. Administrators are refused self-service explicitly:
ADR-280 forbids an administrator as the acting user, and an administrator
issues tokens for technical users instead.

**The limits are fields on the backend group.** ``be_groups`` gets
``tx_nrllm_a2a_allow_live``, ``tx_nrllm_a2a_max_expiry_days``,
``tx_nrllm_a2a_allow_no_expiry`` and ``tx_nrllm_a2a_profiles``. This is the
mechanism of :ref:`ADR-277 <adr-277>`: ``be_groups`` is ``adminOnly``, the
fields are typed and validated by the form engine, and an administrator finds
them next to the grant they qualify. Zero is always the strict value: no
live workspace, the default maximum of 90 days, no expiry-free token, no
profile. A group that holds the grant and has no setting therefore allows
a token that runs in a draft workspace, expires within 90 days and calls no
profile, which is a token that can do nothing until an administrator adds
a profile.

The limits of a user are merged over the groups that *hold the grant*,
among the groups TYPO3 resolves for the user (own groups and their subgroups,
as ``fetchGroupData()`` resolves them): live is allowed when any of them
allows it, the maximum expiry is the largest of their maxima, no expiry is
allowed when any of them allows it, and the profiles are the union. A group
that sets a relaxation but does not hold the grant contributes nothing.
The absolute upper bound of the maximum is 730 days, enforced when the value
is read, not only in the form.

**Each limit is a fixed rule at two points.**

1. *Workspace.* The pinned workspace must be above 0 unless a group allows
   live, and the owner must resolve in exactly that workspace with
   :php:`ActingBackendUserResolver`. That is the criterion of
   :php:`RequireDraftWorkspaceRule` (:ref:`ADR-293 <adr-293>`), which cannot
   be called here because it decides one tool invocation of a configuration.
2. *Expiry.* ``expires`` is mandatory (0 is refused unless a group allows no
   expiry) and may not lie further ahead than the maximum. Because the test is
   against *now*, lowering the maximum affects existing tokens at once.
3. *Profiles.* Only profiles in the union may be requested.

*At save time* :php:`A2aSelfServiceTokens::create()` refuses a request that
exceeds a limit and states which. *At request time* every consumer reads the
record through :php:`A2aInboundClientRepository`, which passes a self-service
record through :php:`A2aSelfServicePolicy::constrain()`: a record whose owner
lost the grant, is an administrator now, cannot work in the workspace,
or whose expiry exceeds the maximum is returned withdrawn (inactive), so the
authenticator, the send handler, task methods and
:php:`A2aResumeGuard` refuse it the way they refuse a disabled client. A
profile the owner's groups no longer allow is taken out of the list.
A queued or suspended run reads the record again before it starts or resumes,
so it fails when the owner's rights are gone. Existing technical-user records
are not touched.

**An editor sees and revokes their own tokens, and nobody else's.** The module
shows an editor the self-service records whose ``be_user`` is the session user.
Every read and write by an editor carries the owner in the ``WHERE`` clause, and
a record of someone else answers exactly like a missing record. An
administrator sees every record with owner, kind, creation, expiry, status and
the time of the last task, and revokes any of them at once. Revoking disables
the record and deletes the token hash; a new token needs a new record or a
rotation by an administrator.

**Scope that is not in this record.** OAuth 2.0 Authorization Code with PKCE
would let the editor authorise a client interactively, with the login
(including MFA) at the authorisation step and a refresh that the group can
cancel. It is the better long-term answer, needs an authorisation endpoint the
extension does not have, and is not implemented here.

Consequences
============

- ●● An editor connects an agent without an administrator creating a technical
  user, and the token follows the editor's group membership.
- ●● The defaults are strict at every zero: draft workspace, 90 days at most,
  no profile, no group holds the grant.
- ● One policy object is called from the writer and from the single repository
  that every request-time consumer reads, so a limit cannot be enforced at one
  of them and forgotten at another.
- ● An administrator can issue and rotate a token without shell access, and sees
  every token with its owner and last task.
- ◑ A self-service token bypasses MFA and passkey login; mandatory expiry and
  the draft workspace limit the damage and do not remove it.
- ◑ The repository does a user and group lookup per request for self-service
  records only.
- ◑ The acting actor of a run carries the owner's *direct* groups, as it does
  for technical users; configurations restricted to a group the owner has only
  through a subgroup are not offered to the token.
- ✕ The record of the last task is the only "last used" information; a token
  that only reads existing tasks is not noticed there.
- ✕ The token acts with everything its owner's permissions allow, inside the
  workspace and the profiles; the owner is responsible for where they paste it.

Net Score: +5

Alternatives considered
=======================

**Open the A2A client table to editors in TCA.** Rejected: ``be_user`` would be
an editable field and the hook would have to be the only barrier between an
editor and an arbitrary backend user.

**User TSconfig instead of group fields.** Rejected: free text is not validated
(a misspelled key reads as unset, which is "unlimited" for a naive consumer),
it is merged by TYPO3's own rules that differ between page and user TSconfig,
and an administrator would not find it next to the grant.

**Narrow a token to what is allowed instead of withdrawing it.** Done for
profiles, where "exactly the allowed profiles" is a well-defined subset. Not
possible for a workspace or an expiry, where a token either is within the
limit or is not.

**Show the token through a flash message or a redirect.** Rejected: flash
messages are stored in the session in clear, and a redirect puts the token
in a URL or a second request.

**Authorisation by OAuth 2.0 Authorization Code now.** Deferred, see above.

.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

This page summarizes the latest release. The development branch also includes
unreleased changes: inspect the `pending changelog fragments
<https://github.com/netresearch/t3x-nr-llm/tree/main/changelog.d>`__ before
assuming a behavior is available in a tagged package.

The `complete changelog
<https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__ is the
canonical release ledger. The manual's older highlights are selective and
may omit intermediate versions.

During 0.x, a minor release may introduce breaking changes; patch updates
remain within the minor series. Review migration notes before upgrading.

.. toctree::
   :maxdepth: 1

   Changelog/Index

.. _version-0-46-0:

Version 0.46.0 (2026-11-13)
===========================

An administrator can generate, rotate and revoke the A2A token of a client
record in the new backend module "A2A tokens", and an editor whose backend
group holds the new permission can issue tokens that act as their own account.
Run the TYPO3 database schema update after updating.

- **A2A tokens from the backend (ADR-294):** the module lists every client
  record with owner, expiry and the time of the last task. The token is shown
  once; every issuing, rotation and revocation is logged with the actor and the
  record, never the token. ``nrllm:a2a:client:token`` stays.
- **Editor self-service:** the permission ``a2a_token_self_service`` ("Issue A2A
  tokens for my own account") is held by no group after installation. An editor
  with it sees and revokes only their own tokens, at most ten active ones. Four
  fields on the backend group set the limits: draft workspace only unless the
  group allows live, expiry within 90 days by default, and only the A2A profiles
  the group lists. The limits are checked when the token is created and again on
  every request and every start or resume of a run. A token bypasses MFA and
  passkey login.
- **Schema:** ``tx_nrllm_a2a_client`` gains ``self_service`` and ``be_groups``
  gains four columns.


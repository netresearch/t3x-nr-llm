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

.. _version-0-51-0:

Version 0.51.0 (2026-11-18)
===========================

Agent runs record the skill versions their model calls carried and the test
evidence of those versions, PostgreSQL is a supported database, and an A2A
page metadata write to a translated page is stored and reported. Run the
TYPO3 database schema update after updating.

- **Skill versions in runs:** a run records the skill versions each model
  call carried for the first time (``skill_usage``, ADR-321) and the
  evaluations that measured exactly those versions (``skill_evidence``,
  ADR-322); both show in the run timeline.
- **Input digests:** with ``privacy.inputDigests`` (off by default) a step
  stored at the ``metadata`` level keeps keyed digests of the content it
  drops, so two runs show which input differed (ADR-323).
- **PostgreSQL:** supported beside MariaDB/MySQL and SQLite; CI runs the
  functional tests on PostgreSQL 17. Six hash columns are now
  ``varchar(64)``.
- **A2A:** a page metadata write to a page with a translation is stored and
  reported as written, and the ``writes`` artifact also lists records a
  failed call wrote. The A2A tokens module offers
  :guilabel:`Enable and generate token` for a disabled client.
- **Breaking for implementers:** ``PrivacyPolicyInterface`` gains
  ``inputDigests(): bool``.

Details in the repository :file:`CHANGELOG.md`.

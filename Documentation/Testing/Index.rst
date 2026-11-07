.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _testing:

=============
Testing guide
=============

Comprehensive testing guide for the TYPO3 LLM extension.

..  figure:: /Images/diagram-ci-pipeline.svg
    :alt: A push or pull request fans out into static analysis, the test matrix,
        security checks and documentation rendering; a subset of the resulting
        contexts is required by the branch ruleset, and the merge queue re-runs
        them before the merge lands.
    :class: with-border

    The jobs run in parallel, not in sequence. Which of them can actually block
    a merge is a property of the branch ruleset, not of the workflow.

.. _testing-overview:

Overview
========

Use the repository test runner documented in :ref:`testing-ci-configuration`.
Test counts and measured coverage belong to a dated CI run, not a permanent
manual table.

- Unit and fuzzy suites check isolated behavior and generated edge cases.
- Integration and functional suites check collaborators and TYPO3 behavior.
- Mocked HTTP end-to-end tests exercise provider workflows without live keys.
- Browser end-to-end tests exercise backend UI interactions.
- Explicit live smoke tests are separate, require credentials and can spend
  provider quota. A mocked suite passing is not evidence of a live API call.

.. toctree::
   :maxdepth: 2

   UnitTesting
   FunctionalTesting
   EndToEndTesting
   CiConfiguration

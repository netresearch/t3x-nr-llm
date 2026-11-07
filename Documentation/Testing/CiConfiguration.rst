.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _testing-ci-configuration:
.. _testing-ci:
.. _testing-ci-github:

================
CI configuration
================

The actual workflow is `.github/workflows/ci.yml
<https://github.com/netresearch/t3x-nr-llm/blob/main/.github/workflows/ci.yml>`__,
which calls the shared Netresearch TYPO3 workflow. PHP 8.2–8.5 and TYPO3
``^13.4`` / ``^14.3`` are the pull-request matrix. The merge queue uses the
workflow's compact matrix rather than repeating every PR cell.
The shared workflow resolves the TYPO3 constraint per cell; a plain
``composer install`` under an unused matrix variable would not do that.

Local verification
==================

Use the repository's Docker runner; it selects compatible PHP and dependency
versions and avoids relying on a workstation's PHP runtime:

.. code-block:: bash
   :caption: Run relevant suites locally

   ./Build/Scripts/runTests.sh -s unit
   ./Build/Scripts/runTests.sh -s functional -d sqlite
   make gate

``make gate`` runs the repository's pre-push checks. A local run covers one
environment, not every CI matrix cell. See the workflow and repository
``AGENTS.md`` for the current commands and required merge checks.

Mutation quality
================

.. _testing-mutation:
.. _testing-mutation-running:
.. _testing-mutation-results:

Infection is configured with minimum MSI 70 and covered MSI 74. The mutation
job runs on the weekly schedule or manual dispatch and is report-only, not
the routine merge gate. A configured target is not a measured score. Link a
dated CI artifact when reporting actual test counts or mutation results.

.. code-block:: bash
   :caption: Run mutation tests explicitly

   ./Build/Scripts/runTests.sh -s mutation

.. _testing-ci-gitlab:

Other CI services
=================

A consumer can call the same runner from another CI service with Docker
available. Pin its PHP/TYPO3 matrix and actually resolve the corresponding
constraints in each job. The GitHub workflow above is this repository's
maintained CI definition; this manual does not ship a second parallel
GitLab pipeline template.

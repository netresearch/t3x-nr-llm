.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _responsible-ai:

==============
Responsible AI
==============

nr-llm supplies controls for AI applications in TYPO3. Whether an application
is appropriate, useful and safe depends on its purpose, affected people,
configuration, providers and operation. This section records the controls,
their limits and the evidence still needed. It is not a certification or a
legal assessment of an installation.

.. _responsible-ai-scope:

Scope and evidence
==================

The initial source assessment uses version 0.43.20, commit
``ac59b8de5226ad7e941a587ed31b10690919277f``, reviewed on 7 November 2026.
Changes introduced with this section are identified separately in the
:ref:`gap register <responsible-ai-gaps>` and their ADRs. Source inspection
does not demonstrate production configuration, successful scheduled purges,
provider contracts, representative model quality or accessibility in a
consuming website.

We distinguish four statements:

* **Available mechanism:** the code offers a control, with stated scope.
* **Verified behavior:** a named test or experiment exercised that scope.
* **Deployment evidence:** an operator demonstrated it in a named installation.
* **External recognition:** the responsible body assessed the named subject.

None implies the next. Missing evidence is recorded as unknown, not as a
passing result or proof that the capability does not exist. See
:ref:`ADR-269 <adr-269>` for the maintenance rule.

.. _responsible-ai-owners:

Who supplies which evidence
===========================

.. list-table::
    :header-rows: 1

    * - Owner
      - Responsibility
    * - nr-llm maintainers
      - Shared controls, regression tests, documented contracts and limitations,
        supported versions and security fixes.
    * - Integrating extension or site team
      - Purpose, permitted inputs and actions, source presentation, meaningful
        review, accessible interaction, representative evaluation and notices.
    * - Operator
      - Enabled configuration, data recipients, retention schedules, backups,
        permissions, incident response, correction and complaint handling.
    * - Model or service provider
      - Contractual processing terms, subprocessors, model information and
        provider-side retention. The operator verifies their suitability.

Each deployment must name actual people or teams for these roles. The library
cannot appoint them. Affected people need an accessible correction or complaint
route and, where appropriate, a way to complete the task without AI.

.. _responsible-ai-freedom:

Responsibility and software freedom
===================================

The software remains GPL-2.0-or-later. These engineering decisions and
recommendations add no restrictions on who may use, modify or redistribute
it, or on fields of use. Unsupported or unevaluated uses describe the
project's support and evidence boundaries, not additional licence conditions.
Models, datasets and external services have their own terms; the extension's
licence does not turn them into open-source components.

.. _responsible-ai-navigation:

Assessment and practical work
============================

.. toctree::
    :maxdepth: 1

    CurrentState
    Gaps
    FrameworkMapping
    OperationalEvidence
    ApplicationEvaluation

Report defects through the
`issue tracker <https://github.com/netresearch/t3x-nr-llm/issues>`__;
report vulnerabilities through
`private security reporting
<https://github.com/netresearch/t3x-nr-llm/security/advisories/new>`__.
Do not post personal data, credentials or confidential prompts in public issues.

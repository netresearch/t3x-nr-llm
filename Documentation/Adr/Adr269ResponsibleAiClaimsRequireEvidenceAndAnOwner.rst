.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-269:

==================================================================
ADR-269: Responsible AI claims require evidence and an owner
==================================================================

:Status: Accepted
:Date: 2026-11-07

Context
=======

nr-llm already has privacy, approval, routing and evaluation mechanisms. A
capability inventory alone cannot show that a particular AI application is
appropriate or that an operator enabled and checked those mechanisms. A
governance profile matches settings; TYPO3's DPG recognition concerns the
assessed core repository. Neither is recognition of this extension or of a
deployment. These distinctions must survive product and documentation changes.

Decision
========

Publish a :ref:`responsible-ai` section with a current capability inventory,
an actionable gap register and a bounded comparison with external frameworks.
Each material claim identifies the behavior, scope, prerequisites, evidence,
limits and responsible role. Missing evidence stays unknown. A passing test,
an available feature, a deployment record and external recognition remain
different statements. Do not aggregate the comparison into a compliance score.

Maintain the assessment alongside changes to provider selection, fallback,
data egress, persistence/retention, approval, writing, evaluation or public
claims. The change updates the relevant rows and points to its tests or
operating evidence. A release that changes one of those boundaries reviews
its system card and known limitations. Report facts for the reviewed revision;
do not roll the review date forward without reviewing it.

Keep responsibilities explicit: nr-llm maintains shared controls; consumers
define and assess their applications; operators verify configuration,
recipients and operating procedures; providers supply service/model evidence.
An application assessment names the people responsible for incidents,
corrections and complaints, and describes meaningful human review and an
appropriate non-AI alternative. A library cannot appoint those people.

Existing runtime resolvers remain authoritative. Profiles do not become a
second policy engine. The backend explains what a match does and does not
establish. Evaluation evidence records its benchmark and generator separately;
retention of that evidence must itself respect the stated data policy.

GPL-2.0-or-later remains unchanged. This decision governs project engineering
and claims; it creates no additional conditions on use, modification or
redistribution. Unsupported and unevaluated applications are support and
evidence boundaries. Models, datasets and service contracts retain their own
terms. No external programme membership or certification is asserted.

Consequences
============

* Readers can distinguish controls they can use from results someone has
  actually demonstrated. The comparison provides questions and evidence
  pointers, not a certificate.
* Every relevant change carries documentation maintenance. Source review
  cannot substitute for representative evaluation or installation evidence.
* Integration and operating work may remain open after a library defect is
  fixed. Its owner and acceptance condition remain in the gap register.

Verification
============

The documentation build resolves the section and its references. ADR lifecycle
checks validate the record. Review checks the inventory against the named
code and the framework comparison against its primary sources. Runtime
assertions require behavior tests in the owning suite; this ADR alone proves
none. Deployment checks are recorded using
:ref:`responsible-ai-operational-evidence`.

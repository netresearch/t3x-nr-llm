.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-271:

================================================
ADR-271: Completion benchmarks have a revision
================================================

:Status: Accepted
:Date: 2026-11-07
:Amends: :ref:`ADR-060 <adr-060>` (revision-specific baselines and one
    explicitly selected routing benchmark replace unversioned comparisons
    and averages across sets)
:Authors: Netresearch DTT GmbH

Context
=======

A golden set identifier can survive changes to its prompts and assertions.
The serving evidence of :ref:`ADR-220 <adr-220>` identifies the generator,
but does not identify the task it answered. Comparing those different tasks
can report a regression that actually changed the measurement itself.

Decision
========

The completion evaluator hashes its ordered prompt IDs and bytes, effective
caller system prompts, assertions, references, explicit generation settings
and versioned grading protocol. Display labels and generator selection are
excluded. The decision grader's threshold and returned grading series are
included in the declared protocol. A scoring change must advance its version.
Only deterministic grading currently has fully identified effective grading
inputs. Decision grading retains scores and reported series in history,
but its effective judge request is unobserved; its comparable revision is
unknown. A model/profile-version label alone does not attest mutable judge
configuration. Custom graders likewise need explicit execution evidence.

The revision also binds the ordered digests of the final adapter-bound
messages and options. The configuration-backed completion terminal computes
these after local default/site configuration, skills, context fitting and
input screening. Provider/model selection is excluded; maps are sorted,
while list order and content bytes are preserved. The same final messages
are passed to the adapter and hashed. No raw messages or options are stored.

An unobserved effective request means unknown benchmark identity. This
includes custom completion services that supply no evidence and old cached
responses. Cache replay retains the original evidence.
Unsupported payloads (objects, resources, recursive/deep arrays and typed
tool/native messages) retain successful responses with unknown identity,
without invoking serialization hooks. Built-in output
redaction invalidates comparability because the request alone cannot
identify a changed output policy. Extensions that transform responses must
likewise clear this internal evidence unless they identify their policy.
The digest does not claim to observe provider-internal defaults, model
weights or adapter-internal transformations. Operators must control and
record those factors when interpreting a comparison.

Persist that content-free fingerprint separately from generator evidence.
Select a regression baseline only within the same known fingerprint, set,
grader and verified generator. Apply all dimensions before choosing the
latest row. Old rows remain readable; missing identity is never backfilled.

Core quality routing uses one explicitly selected set and fingerprint.
Absent or invalid selection means unknown quality. Scores from unrelated
sets or revisions are not averaged. Custom repositories opt into a new
internal capability; their existing interfaces remain unchanged.

Consequences
============

After upgrading, evaluate the chosen set and copy its printed fingerprint
into the routing settings. Until then measured quality is unknown; the
other routing signals remain available. Changing the benchmark requires a
new deliberate selection. A first run of a revision starts its own baseline.
A selected revision may still become stale; selection is not proof of
representativeness, fairness or fitness for a deployed application.

Unit and functional tests cover content and grading changes, actual
execution after local default configuration changes, response screening,
cache replay, missing effective-request evidence, persistence, legacy
records, exact baseline selection, configured ranking and measured zero.
Retrieval benchmarks keep their existing identity scheme.

.. _adr-127:

===========================================
ADR-127: A marked, versioned API surface
===========================================

:Status: Accepted
:Date: 2026-08-05

Context
=======

The roadmap asks for a "public versioned API surface". The extension already
has most of the ingredients: ADR-028/065/101 govern which services are
container-public, the ``Documentation/Api/`` pages describe the consumer
services, and semantic versioning is practised in releases. What is missing is
the *identity* of the API: nothing in the code says which classes the semver
promise covers. A downstream developer whose autocompletion offers
:php:`AgentRunPersister` next to :php:`CompletionServiceInterface` has no
signal that one is a contract and the other an implementation detail that may
vanish in a minor release.

Decision
========

Every class-level docblock carries one of three markers, and the marker — not
the container visibility, not the documentation — is the authority on what
semver covers:

1. **``@api``** — the consumer surface. Calling these is covered by semver:
   no removal, no signature break, no behavioural contract break within a
   major version. Membership is the **signature-transitive closure** of the
   entry points: every type that appears in an ``@api`` method signature is
   itself ``@api`` (the response objects, option classes, value objects and
   typed exceptions a caller necessarily touches). A hand-curated list
   inevitably drifts; the closure rule is checkable.
2. **``@api`` extension point** — interfaces and attributes third parties
   *implement* rather than call (tool, guardrail, provider, translator,
   search-backend, preset, evaluation and middleware contracts). These carry
   the stricter promise the direction of implementation forces: **no new
   abstract member within a major version**, because adding one breaks every
   existing implementor, not just callers.
3. **``@internal``** — everything else, explicitly. Controllers, widgets,
   hooks, upgrade wizards, commands, DI passes, form elements, repositories
   and the setup wizard may change without notice in any release.

What this deliberately is not
=============================

- **Not a phpat rule.** phpat selects by namespace and inheritance, not by
  docblock tag, and cannot assert "signature types of ``@api`` methods are
  ``@api``". The closure property is instead asserted by the API snapshot
  test (follow-up to this ADR): the snapshot renders every ``@api`` signature,
  so an out-of-closure type surfaces as an unmarked name in a rendered
  signature.
- **Not a change to container visibility.** ``public: true`` in
  ``Services.yaml`` remains governed by ADR-028/065; :ref:`ADR-101 <adr-101>`
  remains the count authority. The two sets overlap but are not equal: a
  service can be container-public for a TCA ``itemsProcFunc`` (Category E)
  and still ``@internal``, and a value object can be ``@api`` without being a
  service at all.
- **Not a compatibility promise for protected members.** The promise covers
  what a consumer calls and what an implementor must provide. Subclassing
  internals of ``@api`` classes is out of contract.

Consequences
============

- 128 classes are ``@api`` (85 entry points and hand-verified members plus
  43 added by running the closure to its fixpoint), 20 are extension points,
  and every previously unmarked class in the internal directories carries
  ``@internal`` (82 new markers; the rest existed). IDEs and PHPStan surface ``@internal`` usage from
  outside the package.
- ``Documentation/Api/Stability.rst`` states the promise in consumer terms
  and is the first page of the API reference.
- A follow-up PR adds the snapshot test that freezes the rendered ``@api``
  signatures in-repo, so an unintended break fails CI before review.
- New code must pick a marker at creation time; the snapshot test's file list
  makes an unmarked new public-namespace class visible in review.

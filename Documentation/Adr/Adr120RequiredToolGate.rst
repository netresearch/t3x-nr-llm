.. include:: /Includes.rst.txt

.. _adr-120:

============================================================================
ADR-120: The agent loop's tool gate is a required collaborator
============================================================================

:Status: Accepted
:Date: 2026-07-29
:Authors: Netresearch DTT GmbH

.. _adr-120-context:

Context
=======

:php:`ToolLoopService` took three security collaborators as optional
constructor arguments: the composite tool-call policy (:ref:`adr-094`), the
per-configuration allow-list resolver, and the input schema validator
(:ref:`adr-105`). Each defaulted to ``null``, and each ``null`` changed what the
loop enforced without changing anything a test or a linter could see.

The roadmap named the fix as "make them required, and give tests an explicit
lean wiring (``Null*`` implementations plus a ``Testing`` builder) instead of
implicit absence". Working it through, two of the three premises turned out to
be wrong, both in the unsafe direction. They are recorded here because the
change deliberately does something other than what the roadmap said.

**The allow-list resolver was already dead code in production.**
:php:`resolveOfferedNames()` returns the policy's verdict as soon as a policy is
wired. Everything after that return — the enabled-set intersection, the
admin filter, the allow-list intersection — is unreachable, and the resolver was
read at exactly one line inside it. Production always wires a policy, so the
argument enforced nothing there. Making it *required* would have added a
mandatory argument that no production code path consults, while
:php:`ToolCallPolicy` performs the identical check itself.

**A ``Null`` policy would have been weaker than the status quo.**
Today a ``null`` policy falls through to that legacy chain, which — narrower
than the composite gate, but real — still intersects with the enabled set, still
drops admin-only tools for a non-admin, still applies the configuration's
grant. An allow-all :php:`NullToolCallPolicy` returns the caller's requested
list unfiltered and disables all three at once. It would have been the exact
failure mode the item exists to remove, shipped as its remedy. A deny-all
``Null`` is unusable by the tests that motivated it.

.. _adr-120-decision:

Decision
========

The composite policy becomes a **required** constructor argument. The allow-list
resolver is **deleted** along with the now-unreachable legacy chain. The schema
validator becomes non-nullable with a ``new JsonSchemaValidator()`` default — it
is stateless and has no constructor, so there is no wiring under which the
defence-in-depth re-validation can be absent.

**No ``Null`` implementation of any gate is written**, in ``Classes/``,
``Classes/Testing/`` or ``Tests/``. A gate that does not exist cannot be wired
by accident. Tests that need a loop construct the real
:php:`ToolCallPolicy` — it needs only a registry, an availability service and
three stateless resolvers, so this costs a helper rather than a fixture.

The availability service is no longer a constructor argument either. Deleting
the legacy chain left nothing in the loop reading it; the policy owns that check
now.

.. _adr-120-consequences:

Consequences
============

The loop can no longer be constructed in a state where it enforces less than
production does. Every test that exercises it exercises the real gate, including
the trust-zone axis that the legacy chain never had.

That axis was immediately load-bearing. Tests that passed a bare
:php:`LlmConfiguration` were relying on a configuration with no provider, which
fails closed to the ``EXTERNAL_GLOBAL`` zone and a ``EDITOR_CONTENT`` ceiling —
so the real gate withholds every tool above that class. Those configurations now
declare a ``LOCAL``-zone provider. This is a finding about the fixtures, not a
concession: a run with a provider-less configuration really does get
``fetch_logs`` withheld in production today, and no functional test noticed
because no functional test wired the gate.

A new container test pins the production wiring: that the loop resolves at all
proves the required argument is autowirable, and that the bound policy is the
composite :php:`ToolCallPolicy` proves the container does not reach a narrower
implementation that would satisfy the type while deciding less.

**What this does not close.** :php:`ToolCallPolicyInterface` is a public alias, so
an install may bind its own implementation. That is an extension point, not a
hole, and a test in this package cannot observe a downstream container. Required
arguments turn "absent wiring silently weakens the gate" into "substituted
wiring deliberately replaces it" — a smaller and much more visible surface, not
an eliminated one.

.. _adr-120-alternatives:

Alternatives considered
=======================

**Follow the roadmap literally** — ``Null*`` implementations plus a ``Testing``
builder. Rejected: see the context above. The ``Testing`` builder would also
have had to construct the ``Null`` gates, which merely moves the choice of a
weakened gate from the constructor into the builder.

**Keep the allow-list resolver required rather than deleting it.** Rejected: a
required argument that no code path reads is worse than an optional one, because
it reads as enforcement.

**Give tests a permissive double instead of the real policy.** Rejected. The one
functional test that wires real collaborators would then assert a double's
behaviour — reproducing, inside the test suite, the substitution this change
removes from the constructor.

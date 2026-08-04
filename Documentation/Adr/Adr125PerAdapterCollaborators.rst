.. _adr-125:

=========================================
ADR-125: Per-adapter collaborator classes
=========================================

:Status: Accepted
:Date: 2026-08-04

Context
=======

Every provider adapter keeps its private helpers inline; shared behaviour has
so far moved *up* into :php:`AbstractProvider` or *sideways* into traits
(:php:`ResponseParserTrait`, :php:`ErrorMessageSanitizerTrait`). There was no
precedent for a class that belongs to exactly one adapter.

:php:`OpenRouterProvider` carried one cluster that neither direction fits: the
model routing — six methods, 163 contiguous lines, pure decision logic with no
HTTP, no PSR-7 and no response objects. It is also the part most likely to
grow, because routing strategies are the product's premise. Pulled up it would
burden six adapters that route nothing; as a trait it would stay untestable in
isolation and keep the adapter's line count.

Decision
========

Adapter-specific logic that is pure — no transport, no credential path, no
response parsing — MAY be extracted into a collaborator class in a
sub-namespace named after the adapter (here
:php:`Netresearch\NrLlm\Provider\OpenRouter\ModelRouter`). Rules:

1. **The provider constructs it inline.** The adapter constructor signature is
   owned by :php:`AbstractProvider`, shared by all seven adapters and wired by
   the compiler pass; a collaborator is an implementation detail and never a
   DI service of its own.
2. **The collaborator is stateless.** Configuration the adapter's
   :php:`configure()` owns and validates (here the routing strategy) stays on
   the adapter and arrives per call. Runtime caches (here the fetched model
   catalogue) stay on the adapter and arrive as an argument — as a closure
   where the inline code fetched lazily, so extraction cannot introduce a
   network call that was not there before.
3. **The architecture guard covers the sub-namespace.** The PHPat rule that
   keeps :php:`Service\*` off concrete adapters matches classes ending in
   ``Provider``; a collaborator named anything else would quietly escape it.
   Each new adapter sub-namespace is added to the deny set in the same change
   that creates it.
4. **Transport is out of scope.** Anything touching the HTTP client, auth
   headers, retries or error mapping stays on the adapter, where
   :php:`AbstractProvider`'s vault client and SSRF gating are unavoidable.

Consequences
============

- :php:`OpenRouterProvider` drops from 983 to 829 lines and the routing
  becomes directly unit-testable; the existing ~22 routing tests keep passing
  unchanged because they assert through the captured request body.
- The first collaborator sets the naming and placement pattern for any future
  one (e.g. a Gemini message converter), each requiring the same guard
  extension.
- The golden-sample note in :file:`AGENTS.md` still points at
  :php:`OpenAiProvider` — the inline shape remains the default; extraction is
  for clusters that meet the purity bar above, not a target size.

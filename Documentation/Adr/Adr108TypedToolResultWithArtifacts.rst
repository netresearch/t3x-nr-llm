.. include:: /Includes.rst.txt

.. _adr-108:

============================================================================
ADR-108: Typed ToolResult with run-only artifacts
============================================================================

:Status: Accepted
:Date: 2026-07-22
:Authors: Netresearch DTT GmbH

.. _adr-108-context:

Context
=======

``ToolInterface::execute()`` returned a plain ``string`` that
:ref:`ToolLoopService <adr-010>` fed straight back to the provider AND rendered
into the backend Tool Playground inspector. A tool that computes something
inherently structured — a set of records, a schema, a file listing — could only
flatten it to text, so the inspector had nothing richer to show than the same
line-based blob the model receives.

Adding structured output naively is a security problem: a tool result already
egresses to an external LLM provider, so any structured payload bolted onto the
return value would ride the wire too, widening the egress surface for
attacker-influenceable tool bytes (the model is steerable by injected skill
prose, see :ref:`ADR-064 <adr-064>`).

:ref:`ADR-094 <adr-094>` introduced ``ToolDataClassInterface`` as an *opt-in
marker* deliberately kept OFF ``ToolInterface`` to avoid editing all 41
builtins at once. That precedent does not apply here: egress separation must BE
the return value (a structured channel that is unreachable from the wire), not
an annotation a tool may forget to add.

.. _adr-108-decision:

Decision
========

Replace ``execute(array $arguments): string`` with
``execute(array $arguments): ToolResult`` — a ``final readonly`` value object
carrying exactly one provider-facing ``string $content``, a ``bool $isError``,
and a ``list<ToolArtifact> $artifacts`` that is **run-scoped**: it flows only to
the trace, the inspector event stream and the persisted audit copy, and has NO
code path to the provider wire.

Egress separation is enforced *by construction*:

- ``ToolResult`` has no ``__toString()`` and no accessor that merges an artifact
  into a wire string; ``->content`` is the single path to a wire string.
- The sole wire sink, ``ChatMessage::toolResult()``, is only ever handed
  ``$result->content``.
- ``ToolLoopService::invoke()`` — the single seam every executed call passes
  through — UTF-8-coerces and byte-bounds BOTH channels before any
  ``ToolResult`` leaves the process. ``content`` keeps its existing 50 000-byte
  cap (``capResult()``); ``artifacts`` get an independent 50 000-byte serialised
  budget (``boundArtifacts()``).

The private constructor forces the ``ToolResult::text()`` / ``ToolResult::error()``
factories; ``error()`` carries no artifacts, so a failing tool can never leak a
half-built structure.

Artifact type model
--------------------

``ArtifactType`` is the smallest closed set whose every case has a v1 emitter
plus a fallback::

   enum ArtifactType: string {
       case TABLE = 'table';   // {columns: list<string>, rows: list<list<string>>}
       case TEXT  = 'text';    // {text: string} — fallback + "artifacts omitted" marker
   }

This is a *rendering* shape, NOT a semantic taxonomy. ``TREE``, ``LIST``,
``KEY_VALUE``, ``LINK`` and ``CODE`` are additive follow-ups — each lands later
as one new enum case plus one JS branch, with no consumer or persisted-data
migration. No case ships without a committed producer: ``TREE`` (a page-tree
emitter) is intentionally deferred rather than shipped empty.

The sole v1 emitter is ``ReadRecordsTool``, which builds its ``TABLE`` rows from
the SAME already-redacted ``formatValue()`` cells its text lines use, in one
pass — the artifact can never drift from, or re-expose more than, the text
egress. The other 40 builtins ship text-parity via a mechanical
``ToolResult::text($string)`` wrap.

Fail-closed bounding
--------------------

``boundArtifacts()`` UTF-8-coerces every string leaf, then validates the whole
list with the EXACT flags the downstream sinks use
(``JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE``) plus a depth-64 cap.
Anything that survives therefore cannot throw at
``ToolPlaygroundController`` (``streamLine()`` / ``respondJson()``) or
``AgentRunPersister::recordStep()`` — crash-safety by construction, not by a
lenient superset. On a ``JsonException`` (non-finite float, unencodable type,
over-depth) or an over-budget encode, the WHOLE list is replaced by a single
``TEXT`` "Artifacts omitted" marker — never a mid-structure truncation.

Privacy
-------

``toolArtifacts`` is added to ``RunStepPrivacyFilter``'s ``CONTENT_KEYS``.
Consequences flow from the existing machinery:

- At the default METADATA/NONE level the artifact data is ``unset()``; a
  summary (``toolArtifactsCount`` + ``toolArtifactTypes``) records shape and
  count but never bytes — mirroring ``toolResultLength``.
- At REDACTED the normalised ``list<{type,label,data}>`` is masked by the
  existing recursive redactor with no new code (the ``type`` discriminator and
  ``label`` are masked too; the JS renderer then falls to its unknown-shape
  fallback — fail-safe).
- At FULL it is verbatim (deliberate).

As with ``toolResult`` (:ref:`ADR-081 <adr-081>`), the LIVE NDJSON stream
renders unfiltered from memory (``RunStep::toArray()``), so an admin's browser
sees full artifacts even at METADATA while the *persisted* copy is summarised.
This is intentional for the admin-only module, not a bypass.

.. _adr-108-consequences:

Consequences
============

- ``ToolInterface`` is a breaking change across all 41 builtins. Pre-1.0
  (:ref:`ADR-090 <adr-090>`) this is acceptable and announced; third-party tools
  discovered via the ``nr_llm.tool`` tag must return a ``ToolResult`` (the
  ``ToolResult::text()`` factory keeps the trivial case a one-line change).
- The provider wire is unchanged: ``ChatMessage::toolResult()`` still receives a
  string, so no provider adapter changes.
- ``ToolInvocation`` and ``RunStep`` gain a typed artifact field (appended with a
  default, positions stable). ``ChatMessage``, ``ToolLoopResult`` and
  ``SuspendedRunState`` are untouched — artifacts are audit/display state, never
  resumable functional state, so they must not ride the suspend payload.
- The inspector gains a conditional "Artifacts" tab rendering ``TABLE`` / ``TEXT``
  and an unknown-type JSON fallback, all via ``textContent`` (never
  ``innerHTML``) because artifacts are attacker-influenceable.

See also :ref:`ADR-010 <adr-010>` (tool function-calling design),
:ref:`ADR-094 <adr-094>` (tool data-class trust zones) and
:ref:`ADR-064 <adr-064>` (event privacy).

.. _adr-126:

====================================================
ADR-126: A named JSON-Schema subset, enforced strict
====================================================

:Status: Accepted
:Date: 2026-08-05

Context
=======

ADR-082 shipped structured completions with a three-keyword structural matcher
— ``type``, ``required``, ``properties`` — and deliberately excluded ``enum``,
``pattern``, ``oneOf`` and the rest: a full validator would add a runtime
dependency. The matcher is fail-open: unknown keywords are silently ignored,
an empty schema accepts everything.

That posture is correct for the paths the matcher also guards — the ADR-105
tool-input gate and the resume paths depend on its exact semantics — but wrong
for structured completions, where a caller who writes ``enum`` deserves either
enforcement or an error, never silent acceptance. The roadmap asked for
"enum, pattern, ``oneOf``, full draft support".

Decision
========

The validator gains a second, STRICT mode next to the untouched lenient one.
Its contract is a **named subset**, owned by :php:`StrictSchemaSubset`:

- Enforced: ``type`` (incl. union arrays), ``enum``, ``const``, ``pattern``,
  string lengths, numeric bounds (incl. numeric ``exclusive*``), integer
  ``multipleOf``, ``items``/``prefixItems`` and the array assertions,
  ``properties``/``required``/``additionalProperties`` (bool and schema
  forms), ``oneOf``/``anyOf``/``allOf``/``not``.
- Annotations accepted and ignored: ``description``, ``title``, ``default``,
  ``examples``, ``format`` (annotation-only in 2020-12), ``$schema``, ``$id``.
- Everything else — notably ``$ref``/``$defs`` — is **out of subset**, and out
  of subset is **fail-closed**: the schema is rejected as a whole, so the
  subset is enforceable rather than aspirational. "Full draft support" is
  deliberately not built; reference resolution is the point at which a subset
  becomes a JSON-Schema implementation, and ADR-082's no-runtime-dependency
  decision stands.

:php:`completeStructured()` **pre-flights** the schema against the subset and
throws (code ``1784500003``) before the first provider call: an out-of-subset
schema fails for every possible response, and discovering that after the
repair round-trip would cost two paid requests.

The lenient mode is byte-identical to before. The two modes are tied together
by an invariant a fuzzy test pins: anything strict accepts, lenient accepts.
Strict only ever rejects more, which is what makes it safe to introduce next
to a mode that guards a security boundary.

Named deviations from JSON Schema 2020-12
=========================================

Each deliberate, none silent:

1. ``pattern`` is compiled as PCRE (delimiters added, ``u`` modifier), not
   ECMA-262. A non-compiling pattern is out of subset; a match aborted by the
   backtracking limit rejects — fail-closed either way.
2. ``multipleOf`` is honoured for positive integers only. Float steps need
   epsilon arithmetic that quietly lies about conformance
   (``fmod(0.3, 0.1)`` ≠ 0).
3. ``5.0`` is **not** an integer. Primitive matching byte-mirrors the lenient
   matcher's PHP-native checks — the price of the strict-implies-lenient
   invariant, and cheaper than two subtly different type systems.
4. String lengths count code points (``mb_strlen``).
5. ``enum``/``const``/``uniqueItems`` compare JSON values: maps key-order-
   insensitive, lists order-sensitive, scalars with ``===`` — so ``1`` and
   ``"1"`` differ, and ``1`` vs ``1.0`` differ although JSON has one number
   type. ``{}`` and ``[]`` decode identically in PHP and compare equal; the
   ambiguity is the same one the lenient matcher documents.

Consequences
============

- External callers of :php:`CompletionServiceInterface::completeStructured()`
  whose schemas carry out-of-subset keywords (``$schema`` and friends are
  fine — they are annotations) now get an immediate typed error instead of
  silent partial validation. Breaking, recorded in the CHANGELOG; there are
  no in-repo callers.
- The ADR-105 input gate, the resume paths and the evaluation grader keep the
  lenient mode untouched. Migrating any of them to strict is a separate,
  deliberate decision with its own compatibility analysis — suspended runs
  persisted under lenient semantics must never start failing on resume.
- ADR-082's "no enum/pattern/oneOf" consequence is superseded by this ADR;
  its no-runtime-dependency decision and the repair round-trip stand.
- Provider-native structured output (OpenAI ``json_schema``, Gemini
  ``responseSchema``, Ollama ``format``) was the separate follow-up ADR-082
  named; :ref:`ADR-128 <adr-128>` delivered it, with the subset walker as
  its pre-flight gate.

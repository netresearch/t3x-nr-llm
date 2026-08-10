.. include:: /Includes.rst.txt

.. _adr-147:

============================================================================
ADR-147: No Symfony AI bridge while it is below 1.0
============================================================================

:Status: Accepted
:Date: 2026-08-10
:Authors: Netresearch DTT GmbH

.. _adr-147-context:

Context
=======

An adapter that delegates to Symfony's AI components was proposed as the last,
explicitly optional item of the post-0.27 programme. This record decides against
building it now, and — more usefully — writes down what was measured, so the
decision can be re-made against facts rather than re-argued from memory.

The reasoning that first recommended against it was **wrong on its main
premise**. It assumed that seven first-class adapters plus OpenRouter plus "any
OpenAI-compatible endpoint" left no meaningful coverage gap. That is not what
the code shows.

.. _adr-147-gap:

The gap is real
---------------

``symfony/ai-platform`` ships **37 bridges** (``src/platform/src/Bridge`` at
v0.12.0). nr-llm ships **7** provider adapters — OpenAI, Anthropic Claude,
Google Gemini, Groq, Mistral, Ollama, OpenRouter — plus Azure OpenAI and any
OpenAI-compatible endpoint.

Most of the difference is reachable today through the OpenAI-compatible route or
through OpenRouter. Two are not, and they are the two that matter commercially:

- **AWS Bedrock** — SigV4-signed, its own request shape. Not OpenAI-compatible.
- **Google Vertex AI** — Google-Cloud authentication and its own endpoint layout.

Both are what regulated and public-sector installations ask for by name, and
neither can be reached by configuring a custom endpoint. Cohere, DeepSeek,
Perplexity, HuggingFace, Replicate, Voyage and the speech bridges are further
gaps of smaller weight.

.. _adr-147-fit:

The technical fit is plausible
------------------------------

This is not a case of an abstraction that cannot carry what the extension
needs. ``symfony/ai-platform`` has ``TokenUsage`` (with a streaming listener and
an aggregation), ``StreamResult``, ``ToolCall`` and ``ToolCallResult``, and a
per-bridge result contract. The pieces the middleware pipeline needs — usage for
``UsageMiddleware``, a stream the redaction window can wrap, tool calls in a
shape ``ToolLoopService`` can read — all exist.

.. _adr-147-decision:

Decision
========

**Do not add a Symfony AI adapter while ``symfony/ai-platform`` is below 1.0 —
not as a hard dependency, and not as an optional one.**

The blocker is the version, and only the version. ``symfony/ai`` does not exist
as an installable package; the real packages are ``symfony/ai-platform`` and
``symfony/ai-agent``, both at **v0.12.0**. Below 1.0 there is no backward
compatibility promise, and every 0.x minor may change the contract an adapter is
written against.

That matters more here than it would in an application:

- nr-llm has to keep working on **TYPO3 13.4 LTS** for years. An installation
  that took the adapter would be pinned to whatever 0.x resolved at install
  time, and a security update to an unrelated part of the extension could drag
  a breaking platform minor in with it.
- :ref:`ADR-090 <adr-090>` keeps nr-llm a **single extension until 1.0**. There
  is no separate package a volatile dependency could be quarantined in, so a
  0.x requirement would sit in the same ``composer.json`` as the LTS promise.
- Every nr-llm adapter declares its capabilities per model and is covered by
  unit and integration tests. One adapter fronting 37 bridges cannot make that
  declaration honestly: the capability set would differ per bridge, and no test
  in this repository can exercise bridges it has no credentials for.

**Not even as an optional dependency.** ``suggest`` plus a guard would remove
the risk for installations that do not opt in, and that shape is the right one
to build eventually. It is still wrong to build *now*: it would ship a
capability surface that changes under the installation whenever the upstream
0.x moves, documented as if it were supported. A declaration the repository
cannot stand behind is worse than none.

.. _adr-147-consequences:

Consequences
============

✓ No pre-1.0 dependency enters an extension that carries an LTS promise.

✓ The decision is now attached to a measurement rather than to an assumption.
The next person to ask "why is there no Symfony AI provider?" gets the real
answer — the version — rather than the wrong one that the coverage is already
complete.

✕ **AWS Bedrock and Google Vertex AI remain unreachable.** This is a real
product limitation, not a technicality, and it is the price of this decision.
An installation that needs either today has to run a proxy that exposes an
OpenAI-compatible endpoint in front of them.

✕ The gap widens while Symfony AI adds bridges and nr-llm does not.

.. _adr-147-revisit:

Revisit when
============

Either of these, whichever comes first:

#. **``symfony/ai-platform`` reaches 1.0.** At that point build it in the
   optional shape: ``suggest`` plus ``require-dev``, one adapter that reports
   itself unavailable when the package is absent, and a capability declaration
   derived per configured bridge rather than claimed for all of them.
#. **A named requirement for Bedrock or Vertex AI arrives.** Then the decision
   is a trade rather than a default, and the honest options are the 0.x
   dependency, a dedicated first-class adapter for that one platform, or a
   proxy in front of it. A dedicated adapter is the cheaper answer for a single
   platform — the bridge only pays off across many.

Do not revisit merely because the bridge count grew. The count was never the
argument.

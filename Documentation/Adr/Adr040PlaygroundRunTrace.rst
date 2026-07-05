.. include:: /Includes.rst.txt

.. _adr-040:

==================================================================
ADR-040: Playground run trace and tool-path prompt augmentation
==================================================================

:Status: Accepted
:Date: 2026-07-05
:Authors: Netresearch DTT GmbH

.. _adr-040-context:

Context
=======

The admin playground (:ref:`ADR-038 <adr-038>`) ran the bounded agent loop
and returned only the final answer plus a flat list of executed tool calls
and a single total-token count. For an admin whose job is to understand *how*
a configuration behaves — and to debug a task, prompt or extension that
misbehaves — that is a black box. The information needed to reason about a run
was either discarded or never captured:

- the exact messages sent to the provider each round (system prompt, injected
  skills, snippets, the growing tool dialog) were assembled and thrown away;
- the raw provider response was parsed into :php:`CompletionResponse` and
  discarded in every adapter except OpenRouter;
- per-call latency was never measured, and the prompt/completion token split
  was summed away into one total;
- the model's intermediate turns and ``thinking`` never surfaced.

Two capability gaps compounded this. Skill prose was injected for the
text-generation paths but **not** in the tool-loop path — the loop was the
sole caller of
:php:`LlmServiceManager::chatWithToolsForConfiguration()` and applied no
skills. Prompt snippets had a composer but **no runtime caller at all**.

.. _adr-040-decision:

Decision
========

Add an opt-in **run trace** and one-time **prompt assembly** to
:php:`ToolLoopService`, consumed by the playground; production callers are
unaffected.

Run trace
---------

:php:`Netresearch\\NrLlm\\Service\\Tool\\RunTrace` is a mutable recorder passed
into :php:`runLoop()` as an optional, nullable argument. When present the loop
records a readonly :php:`RunStep` per model round-trip (messages sent, tools
offered, content, thinking, finish reason, the prompt/completion/total token
split, estimated cost and the requested tool calls) and per executed tool
call (name, arguments, result, error flag). Timing is measured with
``hrtime()`` around each provider call and each tool invocation — no new
middleware. When no :php:`RunTrace` is passed — **every production caller** —
the loop records nothing and behaves exactly as before.

One-time prompt assembly
------------------------

The loop assembles the outgoing prompt once, before the first round:

- **Configuration skills now inject into the tool path.** Because the loop is
  the sole caller of ``chatWithToolsForConfiguration()`` and re-sends its own
  accumulating message array (``augmentMessages()`` returns a new list and
  never mutates the input), injecting once before the loop closes the gap
  without double-injecting. This is a behaviour change for the tool loop:
  configurations with attached skills now carry that prose into tool runs, as
  the text-generation paths already did.
- A :php:`RunAugmentation` adds the playground-only extras: **forced skills**
  (injected as additional task skills), **forced snippets** (added as separate
  leading system messages, one per snippet), and a **dry-run** mode that
  assembles and records the messages without calling the provider. When an
  augmentation is present the effective system prompt (a per-run override wins
  over the configuration's) is baked as the first message, so the snippet
  system messages cannot suppress it.

Gated raw-response capture
--------------------------

:php:`ToolOptions::withCaptureRaw()` sets a private ``_capture_raw`` directive
that flows through the call options to the adapters. When set, each adapter
stores the decoded provider body under ``metadata['_raw']`` via
:php:`AbstractProvider::rawResponseMetadata()`. It is **off by default**, so
production calls never retain raw payloads — only the admin playground opts
in, and the module is admin-only.

.. _adr-040-consequences:

Consequences
============

- The playground surfaces the full nr_llm ↔ LLM dialog: assembled prompt,
  per-round timing and token split, requested tool calls, thinking, the raw
  response (on demand) and the tool executions.
- Skills attached to a configuration now influence tool runs, matching the
  text-generation paths; snippets have a first runtime consumer.
- :php:`RunTrace` and the augmentation collaborators are optional and
  autowired, mirroring the optional :php:`SkillInjectionService` on
  :php:`LlmServiceManager`; the production tool path and existing lean test
  wiring are unchanged.
- Raw capture touches all seven adapters, but only along the gated path; the
  non-capture response is byte-for-byte identical.

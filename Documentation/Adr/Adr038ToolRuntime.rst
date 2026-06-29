.. include:: /Includes.rst.txt

.. _adr-038:

==================================================================
ADR-038: Tool runtime (function-calling agent loop)
==================================================================

:Status: Accepted
:Date: 2026-06-29
:Authors: Netresearch DTT GmbH

.. _adr-038-context:

Context
=======

nr-llm completion has been **single-shot**: one request, one answer. The
tool *protocol* value objects already existed — :php:`ToolSpec` and
:php:`ToolCall` (:ref:`ADR-010 <adr-010>`), OpenAI-wire-aligned — and
:php:`LlmServiceManager::chatWithTools()` could send tool declarations and
read the model's tool calls back. But there was **no registry of executable
tools, no PHP that runs a tool, and no loop** that feeds a tool result back
into the conversation. A model could *ask* to call a tool; nothing answered.

Worse, ``chatWithTools()`` cannot be the loop's engine. It resolves its
provider from the ``ExtensionConfiguration['nr_llm']['providers']`` keyed
registry and runs against a **model-less transient configuration**. That
registry is not populated for chat (providers, models and configurations are
DB-backed). The consequences are concrete:

- For keyed providers (Claude, Gemini, Groq, Mistral, OpenRouter) there is
  no registered API key, so the call is **unauthenticated** (401).
- Every provider runs on its **hardcoded default model**, never the model
  the admin selected on the :php:`LlmConfiguration`.
- Cost is computed downstream by :php:`UsageMiddleware` from the priced
  :php:`Model`; a model-less transient config records **zero-cost** usage,
  so the budget cost bucket never sees the spend.

So the agent loop cannot reach a selected configuration's vault key, model,
temperature, system prompt or pricing through the provider-key path. A
config-aware entry point is required before a loop is safe to run.

.. _adr-038-decision:

Decision
========

1. **A DI-tagged tool registry.** :php:`ToolInterface`
   (``Classes/Service/Tool/``) declares ``getSpec(): ToolSpec`` and
   ``execute(array $arguments): string`` and carries
   ``#[AutoconfigureTag('nr_llm.tool')]``. :php:`ToolRegistry` collects every
   tagged tool through an autowired iterator and indexes it by spec name (a
   duplicate name is a developer error → :php:`LogicException` at
   construction). **An extension adds a tool simply by tagging a class** — no
   central registration edit. The registry is the **authoritative allow-set**:
   ``specs($allowedNames)`` intersects the declared names against what is
   actually registered and drops the rest.

2. **A config-aware tool entry point.**
   :php:`LlmServiceManager::chatWithToolsForConfiguration()` mirrors
   ``chatWithConfiguration()`` — it resolves the adapter from the
   :php:`LlmConfiguration` (vault key + real :php:`Model` + params), guards
   ``instanceof ToolCapableInterface`` and runs through the middleware
   pipeline, so :php:`UsageMiddleware` sees the priced model and records real
   cost. It is additive on :php:`LlmServiceManagerInterface` (no consumer
   break) and is the only call the loop makes per round.

3. **A bounded agent loop.** :php:`ToolLoopService::runLoop()` calls
   ``chatWithToolsForConfiguration()`` each iteration; while the model returns
   tool calls it executes them and re-sends, **bounded by a configurable
   max-iteration cap (constructor default 5)**. Three fail-soft rules keep the
   admin informed instead of aborting:

   - An **empty offered set** (no tools, or an empty allow-list) is a single
     plain ``chatWithConfiguration()`` completion — an empty ``tools`` array
     makes some providers (OpenAI) 400.
   - **Hitting the cap** with tools still pending triggers one final plain
     ``chatWithConfiguration()`` (no ``tools`` field at all) to synthesise a
     closing answer and sets ``truncated = true``. A no-tools completion
     yields a real ``finalContent`` uniformly across OpenAI, Claude and
     Ollama — unlike ``toolChoice='none'`` or an empty tools array.
   - A mid-loop :php:`BudgetExceededException` returns the **partial**
     :php:`ToolLoopResult` (trace + usage so far, ``truncated = true``); the
     budget fires pre-flight and tools are read-only, so the state is
     consistent.

4. **Raw-array message turns; ChatMessage unchanged.** The loop appends the
   assistant ``tool_calls`` turn and one ``tool`` result turn per call as raw
   arrays. :php:`LlmServiceManager::normaliseMessages()` routes only exact
   2-key ``{role,content}`` arrays through :php:`ChatMessage`; the 3-key tool
   turns pass through unchanged to OpenAI and Claude. Empty arguments
   serialise to ``{}`` (an object), never ``[]``. :php:`OllamaProvider`
   translates the replayed OpenAI-shape turns into Ollama's native
   ``/api/chat`` shape (object arguments, ``tool_call_id`` dropped) and
   **synthesises a call id** (``call_<index>``) on the way out, because Ollama
   returns none and :php:`ToolCall` rejects an empty id.

5. **Skill.allowed_tools is a fail-closed-on-declaration allow-list.**
   :php:`AllowedToolsResolver` reads the *effective* skills (enabled,
   non-orphaned, deduped — exactly what :php:`SkillComposer` injects) of the
   configuration and task. If **no** skill declares ``allowed-tools`` it
   returns ``null`` (no skill-imposed restriction → all registered tools).
   If **any** declares, the result is the **union** of the declared lists — a
   lone declared empty list yields ``[]`` (no tools). The allow-list is
   enforced **twice**: when computing the offered ``specs()`` *and* again at
   execution time, so a model steered by injected skill prose cannot call a
   registered-but-not-offered tool.

6. **The admin-only playground is the v1 consumer.** The only caller is the
   ``nrllm_tools`` backend submodule (``access => admin``); its AJAX
   ``runAction`` calls ``denyNonAdmin()`` first (:ref:`ADR-037 <adr-037>`).
   Tools have **no per-record authorization** and run with full TYPO3
   privileges — safe only because the caller is an authenticated admin.

7. **Generic error egress, detail logged server-side.** A thrown tool, an
   unknown or disallowed tool name, and any unexpected provider failure
   become a **generic** error string. The exception body may carry DBAL/PDO
   credentials that URL-sanitising would not strip, so it never reaches the
   provider or the DOM; the full detail is logged through the injected
   logger.

.. _adr-038-consequences:

Consequences
============

- ●● nr-llm gains a real agent loop: admin-curated PHP tools run
  mid-generation on the selected configuration's vault key and model, and the
  result is fed back until the model answers or the cap is reached.
- ●● Cost is **recorded** via the config-aware path and bounded by the
  iteration cap **plus** the per-iteration budget pre-flight (request-count /
  token / cost buckets, given the BE-user uid is set). Without
  ``chatWithToolsForConfiguration()`` only the cap and token/request counts
  would bound spend, and keyed providers would 401.
- ● Extensions extend the tool set by tagging a class; no edit to nr-llm and
  no architecture exception (tools live under ``Service\Tool`` and inherit the
  existing service-layer guard).
- ● The allow-list re-validation at both offer and execution time means a
  declared-but-unknown tool name is dropped and an injected prompt cannot
  reach a tool the skills did not grant.
- ◐ The two shipped example tools (``fetch_logs``, ``read_fal_asset_meta``)
  are admin-curated, **read-only**, input-bounded and scoped (limit cap + PII
  redaction; storage-scoped lookup). They are reference implementations of the
  security contract, not a general capability.
- ✕ This is an **authorization (admin-only)** control, not per-record access
  control: a tool can read what the admin can read. ``allowed_tools``
  defaulting to "all tools when no skill declares" is acceptable **only** while
  the consumer is admin-only — **before any non-admin consumer the default
  must flip to fail-closed and per-tool authorization must be added** (see
  §6 of the design and :ref:`ADR-035 <adr-035>` for the escalation surface).
- ✕ Message role is not a trust boundary: a prompt injection in skill prose
  can still steer a tool's arguments. The mitigation is input validation +
  scoping in each tool, the offered allow-list, and the XSS-safe render of
  every tool-derived string in the playground.

See :ref:`ADR-010 <adr-010>` for the tool/function-calling abstraction,
:ref:`ADR-013 <adr-013>` for the configuration hierarchy the loop runs on,
:ref:`ADR-026 <adr-026>` for the middleware pipeline that records cost,
:ref:`ADR-036 <adr-036>` for skill injection (which steers tool arguments),
and :ref:`the administration guide <administration-tools>` for operation.

.. include:: /Includes.rst.txt

.. _administration-tools:

=============
Running tools
=============

*Tools* are small, admin-curated PHP functions the model may call
mid-generation. Where a normal completion answers in one shot, a **tool run**
is a bounded *agent loop*: the model may ask to call a tool, nr-llm executes
it, feeds the result back, and re-asks — until the model answers or an
iteration cap is reached. The v1 consumer is the interactive
:ref:`Tool Playground <administration-tools-playground>`.

Tool execution is **admin-only**. A tool runs with full TYPO3 privileges,
has no per-record authorization, and its return value egresses both to the
configured LLM provider **and** to the rendered backend output. It is safe
only because the caller is an authenticated backend administrator.

.. note::

   The runtime design and its security and cost rationale are recorded in
   :ref:`ADR-038 <adr-038>`. Skill ingest and injection — which can steer
   *which* tools a run may use and *what arguments* the model chooses — are
   :ref:`ADR-035 <adr-035>` / :ref:`ADR-036 <adr-036>` and the
   :ref:`Managing skills <administration-skills>` guide.

.. _administration-tools-builtin:

The built-in example tools
==========================

Two read-only example tools ship enabled. They are reference implementations
of the security contract, not a general capability:

``fetch_logs``
   Returns the most recent ``sys_log`` entries, newest first, with an
   optional PSR ``level`` filter and a ``limit`` (default 20, **hard-capped
   at 50**). Personally-identifying fields — the client IP, the backend user
   id and the serialized payload — are **redacted by omission**, because the
   result egresses to the external provider.

``read_fal_asset_meta``
   Returns read-only metadata (file name, MIME type, size, title, alternative
   text) for a single managed file (``sys_file``) by its ``uid``. The uid is
   model-chosen and therefore injection-steerable, so the lookup is
   **storage-scoped** (default: the default storage). A uid in a non-permitted
   storage returns the same neutral "not found or not permitted" string as a
   missing uid — the model cannot enumerate arbitrary files.

.. _administration-tools-register:

Registering a tool
==================

A tool is a PHP class that implements
:php:`Netresearch\\NrLlm\\Service\\Tool\\ToolInterface`:

``getSpec(): ToolSpec``
   Returns the declaration the model receives — a name, a description, and a
   JSON-Schema ``parameters`` block. Build it with
   ``ToolSpec::function($name, $description, $parameters)``.

``execute(array $arguments): string``
   Runs the tool with the model-provided arguments and returns a plain
   string that is fed back into the conversation as a tool turn.

The interface carries ``#[AutoconfigureTag('nr_llm.tool')]``, so a class is
**auto-registered simply by implementing it** — no central registration file
to edit. :php:`ToolRegistry` collects every tagged tool through a DI iterator
and indexes it by spec name; two tools with the **same** ``name`` is a
developer error and fails fast at container build.

When you write a tool, honour the security contract: treat ``$arguments`` as
attacker-influenced (the model is steerable by injected skill prose),
**validate and scope** every input (cap volumes, scope identifier lookups),
and never return secrets — the result leaves the instance.

.. _administration-tools-playground:

Using the Tool Playground
=========================

The playground lives in :guilabel:`Admin Tools > LLM > Tool Playground` and
is admin-only.

1. Pick an **LLM configuration** from the dropdown. Its vault-stored API key,
   model, temperature and system prompt are what the loop actually runs on —
   the playground never falls back to a default model.
2. Type a **prompt** and click :guilabel:`Run`.
3. Read the **trace**. Each tool the model called is shown in order with its
   name, the arguments the model chose, and the tool's result (errors are
   badged). The model's **final answer** follows the trace.

The :guilabel:`Available tools` panel lists every registered tool. Every
displayed string — tool arguments, tool results (which may include
``sys_log`` content), and the final answer — is rendered escaped; HTML is
only ever shown inside a sandboxed preview, never injected into the page.

Each run is bounded by the iteration cap (default 5) and, when the
configuration's backend user has a budget, by the per-iteration budget
pre-flight. If the cap is hit with tools still pending, a final tool-free
completion synthesises a closing answer and the run is marked *truncated*.
The aggregated **token** usage is reported; the monetary cost is recorded in
the usage table by the middleware pipeline.

.. _administration-tools-ollama:

Ollama model-capability dependency
==================================

Tool calling depends on the **model**, not just the provider. For Ollama,
only function-calling-capable models — for example ``llama3.1``,
``mistral``, ``qwen2.5`` — return tool calls. A model without function-calling
support simply **answers the prompt directly and never calls a tool**; the
loop ends gracefully on the first plain answer. If a configured Ollama model
never seems to use the available tools, verify it is one of the
function-calling models for your Ollama version.

.. _administration-tools-allowed:

Gating tools with ``allowed-tools`` in a skill
==============================================

A skill's ``SKILL.md`` front-matter may carry an ``allowed-tools`` key that
gates which tools the skills attached to a configuration (or task) grant for a
run. The resolution is **fail-closed on declaration**, computed over the
configuration's *effective* skills (enabled, non-orphaned — exactly the set
that is injected into the prompt):

- **Absent** (no skill declares ``allowed-tools``) — no opinion; all
  registered tools are offered.
- **Declared list** — the **union** of the declared lists across the effective
  skills; only those tools are offered (intersected with what is actually
  registered, so an unknown name is dropped).
- **Declared empty** (``allowed-tools: []``) — declares **zero** tools; if no
  other effective skill widens the set, the run gets no tools and is a single
  plain completion.

A disabled or orphaned skill never grants tools. The allow-list is enforced
both when the tools are offered to the model and again when a tool call is
executed, so a prompt injection cannot reach a tool the skills did not grant.

See :ref:`ADR-038 <adr-038>` for the runtime design and security rationale.

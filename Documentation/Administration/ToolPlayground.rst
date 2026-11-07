.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

=========================
Using the Tool Playground
=========================

.. _administration-tools-playground:

Using the Tool Playground
=========================

The playground lives in :guilabel:`AI > Operation > Playground` and is
admin-only. It is a sibling of the :ref:`Tools
<administration-tools-manage>` management module: the playground *runs* the
loop, while the Tools module governs *which* tools exist and are enabled.

.. figure:: /Images/ToolPlaygroundShell.png
   :alt: The Tool Playground module with the LLM configuration picker, an
       empty prompt box, the Run button, and the Available tools panel
   :class: with-border with-shadow
   :zoom: lightbox

   The playground shell — the configuration picker, prompt box and the
   :guilabel:`Tools available to this run` panel, which lists every
   registered tool with the default-enabled ones pre-checked and the
   disabled ``_raw`` variants unchecked.

.. tip::

   **Small local models work best with a narrow tool set.** With every
   group enabled, the model is offered every enabled tool declaration at
   once (several dozen). Small models such as the seeded ``qwen3:4b``
   often fail to pick the right tool from a set that large, or reason
   past the token budget without calling any. Untick the groups that are
   irrelevant to the question — restricted to one or two groups, the same
   model picks the right tool. Larger hosted models cope with the full
   set.

1. Pick an **LLM configuration** from the dropdown. Its vault-stored API key,
   model, temperature and system prompt are what the loop actually runs on —
   the playground never falls back to a default model.
2. Type a **prompt**. Optionally open the override panels to **force-inject
   skills** (added on top of the configuration's own), **force-add snippets**
   (inserted as leading system messages), override the **system prompt**, cap
   the **max rounds**, or tick **capture raw provider response**.
3. Click :guilabel:`Run` — or :guilabel:`Dry run` to assemble the prompt and
   inspect exactly what *would* be sent without calling the model.
4. Read the **inspector** — live from the moment you click Run. A summary
   strip reports rounds, tool calls, the prompt/completion token split,
   estimated cost, wall time and status. The step list is the nr_llm ↔ LLM
   dialog in order: each round opens with a :guilabel:`Context budget` step,
   then its outbound **request** (the messages sent and the tools offered)
   appears the instant it goes out, a waiting indicator shows while the model
   works, then the **response** and each tool execution stream in. Select a
   step to open its detail — requests carry :guilabel:`Messages sent` and
   :guilabel:`Tools offered`; responses carry :guilabel:`Structured`,
   :guilabel:`Raw JSON` and :guilabel:`Thinking`. The model's **final answer**
   closes the run.
5. The :guilabel:`Context budget` step says where the window went for the round
   that follows it, so you can act on the component that is yours to change
   rather than only learn that history was dropped. It has two tabs:

   :guilabel:`Where the window went`
     The window, the output reserve, the safety margin and the resulting
     budget, then four component lines — transcript, tool schema, system
     prompt (incl. snippets) and skills — that **sum to the estimated total**,
     plus what is left. On this surface the system-prompt line always reads
     *counted in the transcript* and the skills line always reads 0: the agent
     loop builds both into the transcript before the fit measures it, so their
     content is already on the transcript line. The table says so under the
     figures. A send whose reserve exceeds the whole window is handed to the
     provider unmeasured, and the step reports *no accounting* instead of a
     window of zero.

   :guilabel:`Injected context`
     Every snippet and skill this run injects, by **name only**, with the data
     class it declared (:ref:`ADR-144 <adr-144>`) or *not classified*, and the
     strictest class across all of them. The list covers the snippets and
     skills you force-injected for this one run as well as the
     configuration's own, and the input-context gate reads the same list
     (:ref:`ADR-164 <adr-164>`): a source shown here is a source that is
     gated. Forcing a snippet the configuration's trust zone would refuse
     therefore refuses the run, naming that snippet.

.. figure:: /Images/ToolPlaygroundRun.png
   :alt: A completed tool run — the summary strip, the ordered step list and
       the selected step's detail tabs for a two-iteration agent loop
   :class: with-border with-shadow
   :zoom: lightbox

   A completed run — the summary strip (rounds, tool calls, token split, wall
   time, status), the ordered step list of the nr_llm ↔ LLM dialog, and the
   selected step's detail: here round 1 requested the ``list_be_users`` tool,
   whose result is fed back so round 2 can answer.

The :guilabel:`Tools available to this run` list lets you narrow a single run
to a subset of the globally-enabled tools. Unticking every tool sends a plain
completion with no tools offered (the full list and the global
enable/disable controls live in the :ref:`Tools
<administration-tools-manage>` module). Raw-response capture is off unless you
tick it, so ordinary runs never retain the provider's raw payload. Every
displayed string — tool arguments, tool results (which may include
``sys_log`` content), and the final answer — is rendered escaped; HTML is
only ever shown inside a sandboxed preview, never injected into the page.

The inspector distinguishes a completed run from a run waiting for approval
or input, a guardrail outcome, or cancellation. Waiting runs show their run
reference and direct you to :guilabel:`AI > Operation > Agent Runs` for the existing
approval or input workflow. The playground does not add a second decision
form. A stream that ends without a settled result keeps its received steps
and is marked *incomplete*, rather than completed.

Each run is bounded by the round cap (the playground's *Max rounds*, else
``agent.defaultMaxIterations``, never above ``agent.maxIterations``) and, when the
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

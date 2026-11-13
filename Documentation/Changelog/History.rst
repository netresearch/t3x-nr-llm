.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _changelog-history:

==========================
Earlier release highlights
==========================

These are selected historical highlights, not a complete release ledger.

.. _version-0-45-0:

Version 0.45.0 (2026-11-13)
===========================

nr-llm can now act as an A2A server: registered Agent2Agent 1.0 clients send
tasks, answer a question the run asks, and, for a profile marked *May write*,
have drafts written into a pinned draft workspace. A configuration can require
a draft workspace for changes, and a run records which records a tool call
changed. Secret-shaped strings, including TYPO3 preview tokens, are now masked
on the paths that skipped the shared catalogue. nr-llm requires nr-vault 1.5.1.
Run the TYPO3 database schema update after updating.

- **A2A server (spec 051, stages 3 and 4):** with ``a2a.server.enabled`` set and
  a site that names ``nrllm.a2a.baseUrl``, registered clients reach an agent
  card and the JSON-RPC methods ``SendMessage``, ``GetTask``, ``ListTasks`` and
  ``CancelTask``. A client is a record with a bearer token (stored as a hash)
  and runs its tasks as a technical backend user in its pinned workspace. A task
  whose run asks a question is ``INPUT_REQUIRED``; the client answers with a
  ``SendMessage`` that names the task. A profile marked *May write* may use the
  profile's writing tools, which write drafts into the client's pinned draft
  workspace only. The server role is off by default and accepts a task only
  while a worker can be shown to run. New tables ``tx_nrllm_a2a_client``,
  ``tx_nrllm_a2a_profile``, ``tx_nrllm_a2a_dedup``, ``tx_nrllm_a2a_wait_slot``
  and ``tx_nrllm_a2a_write``.
- **Durable decisions (ADR-281):** a decision on a waiting run can be stored and
  carried out by the queue worker; the two paths cannot both win one pause.
- **Draft workspace for changes (ADR-293):** a configuration can require a draft
  workspace (``require_draft_workspace``, off by default). With it on, a write
  by a run outside a draft workspace is refused before an approval card is
  shown and again right before the call runs, administrators included.
- **Changes of a tool call (ADR-292):** a run records a ``tool_changes`` step
  that lists the records the DataHandler runs of the call reported as written,
  also when the call then fails; the list is not necessarily complete. The
  approval card states workspace, workspace title and language of the call.
- **Secret shapes masked on more paths (ADR-123):** the ``probe_url`` and
  ``get_last_exception`` reports, MCP tool results, A2A delegation, the
  Responses API failure message, specialized-service errors and log lines, the
  provider connection test, the stored conversation transcript, the Tool
  Playground guardrail reason and embedding input now pass through the shared
  catalogue. The approval card of ``fetch_external_url`` still shows the real
  URL to the approver.
- **nr-vault 1.5.1** is required: its catalogue masks TYPO3 workspace preview
  tokens (``ADMCMD_prev=<token>``).
- **Fixes:** the queue worker refreshes the TYPO3 clock before every queued run,
  a failed or cancelled run reaches the A2A task view, and the A2A task store
  keeps a string under a structural member only in the member's protocol form.

.. _version-0-44-0:

Version 0.44.0 (2026-11-12)
===========================

A configuration can have its texts checked against a rule set by a second
model, a table task can send its rows as TOON, EXT:autotranslate can translate
through nr-llm, and an agent run stores what its model calls cost. nr-llm
now requires nr-vault 1.5. Run the TYPO3 database schema update after
updating.

- **Rule-set judge (ADR-288):** a configuration can have its texts checked
  against a rule set by a second, small model. The rule set is the snippets
  carrying the selected tags; mode ``report`` delivers the text with a hint
  that quotes the broken rule and the passage, mode ``block`` stops it. Off by
  default. ``tx_nrllm_configuration`` gains ``rule_judge_mode``,
  ``rule_judge_tags`` and ``rule_judge_configuration``.
- **Guardrail configurations (ADR-290):** a caller can name further
  configurations with ``withGuardrailConfigurations()``; their term lists
  screen the call as well, and the rule-set judge of each one applies, the
  strictest mode deciding. The backend AI chat uses it so that a site with its
  own configuration keeps the chat's bans.
- **TOON table input (ADR-286):** with ``"format":"toon"`` in a table task's
  ``input_source`` the rows go to the model in TOON instead of JSON; on the
  first 50 rows of five demo tables this needed 45 to 71 % fewer tokens.
- **EXT:autotranslate (ADR-289):** its ``translatorClass`` can name
  ``Netresearch\NrLlm\Integration\Autotranslate\AutotranslateTranslator``,
  which translates through the nr-llm translator in the setting
  ``autotranslate.translator`` and refuses a blank, cut-off or markup-only
  answer instead of storing it.
- **A2A client (spec 051, stages 1 and 2):** an administrator can register a remote
  agent that speaks Agent2Agent 1.0, import its card with
  ``nrllm:a2a:import``, and send it a message with ``nrllm:a2a:send``. Nothing
  is sent unless an agent is registered. Requests go through the address
  guard and are pinned to the checked addresses, which needs
  ``netresearch/nr-vault`` 1.5. Stage 2 lets an agent run delegate a step to
  a skill of an enabled remote agent and wait in the new status
  ``waiting_for_dependency`` until ``nrllm:a2a:poll`` continues it; while
  nr_mcp_agent is installed no delegation tool is offered. New tables
  ``tx_nrllm_a2a_task`` and ``tx_nrllm_a2a_event``.
- **Run cost:** an agent run stores what its model calls cost, also across an
  approval or input pause. Before, every run stored a cost of 0; a run whose
  cost is unknown now shows "Unknown".
- **Failed approved writes:** a background batch offers a record again when its
  card was approved but the write failed, and the Agent Runs module marks a
  finished run whose tool call failed.
- **Head editors (ADR-277):** the new grant ``usage_view_groups`` and the group
  field *AI usage visible for groups* open the module *AI usage of my groups*.
  ``be_groups`` gains ``tx_nrllm_usage_visible_groups``.
- An approval or input in the Agent Runs inbox streams a line per step, so a
  reverse proxy's read timeout counts one step instead of the whole
  continuation (ADR-276).
- Workspace writers ask an inline owner as the run's workspace shows it, and
  approval cards name a content element without a header by its type and UID.

.. _version-0-43-23:

Version 0.43.23 (2026-11-10)
============================

Installations on TYPO3 14.3.11 should update now: with nr-llm 0.43.18 to
0.43.22, a failed tool write in a draft workspace can make publishing that
workspace delete live content. The release also lets a model record carry its
reasoning profile and fixes two OpenAI reasoning-model failures.

- **TYPO3 14.3.11: a failed write in a draft workspace no longer marks the
  live record for deletion.** When ``attach_file_to_content_element`` or
  ``attach_file_to_record`` (since 0.43.18), ``replace_file_reference`` or
  ``set_page_social_image`` (since 0.43.22) failed in a draft workspace, the
  take-back sent ``delete`` for the version the call had made. TYPO3 14.3.11
  treats that as deleting the live record, so the version became a delete
  placeholder, and publishing the workspace deletes the live record. On TYPO3
  14.3 the take-back now sends core's ``discard`` command. TYPO3 13.4 is not
  affected.
- Updating does not remove a delete placeholder an earlier failed write left.
  Before publishing a draft workspace on TYPO3 14.3.11, check it in the
  Workspaces module for records marked for deletion that no editor deleted,
  and discard those changes.
- A model record can carry its reasoning profile: whether it is a reasoning
  model, which ``reasoning_effort`` values it accepts and whether tool calls go
  through Chat Completions or Responses. The fields win over the bundled table,
  so a new OpenAI model works without a release (ADR-275). Run the TYPO3
  database schema update after updating: the model record has seven new
  columns.
- GPT-6: ``gpt-6.1-terra`` is listed, any other ``gpt-6`` id gets the GPT-6
  family default instead of the unknown profile, and ``max`` effort is clamped
  to ``xhigh``. Stop sequences are no longer sent to OpenAI reasoning models,
  which refuse them with HTTP 400.

.. _version-0-43-22:

Version 0.43.22 (2026-11-09)
============================

The social image and file reference writers work in the editor's draft
workspace, AI-written pages and content elements can carry the b13/ai-label
marker, and the task endpoints refuse inactive tasks and, for editors, tasks
with table input.

- ``set_page_social_image`` and ``replace_file_reference`` work in the
  editor's TYPO3 workspace: in a run started in a draft workspace they give
  the page or content element its version, add the new file reference in that
  workspace and mark the replaced one for deletion there, translations
  included. Live stays as it is until the workspace is published; the
  approval card names the workspace and the answer to the model says the
  change is a draft. ``replace_file_reference`` refuses an element moved in
  the draft workspace until the move is published or discarded (ADR-274).
- The take-back of a failed write in a draft workspace discards only the rows
  the call made and keeps the editor's earlier drafts, also further down the
  record's inline children; the answer names each row that is not as it was
  before (ADR-274). One exception is named rather than undone: a failed
  ``set_page_social_image`` replacement can leave a translation's earlier
  image draft marked for deletion, and the answer says so.
- Optional b13/ai-label integration for AI-written pages and content
  elements, with acting-user and workspace attribution, origin classification
  and review resets. The nr-llm marker remains available independently.
- The task execute form and the input-refresh endpoint refuse an inactive
  task with "Task is not active". A backend user with the ``tasks_use`` grant
  who is no administrator can no longer run a task with the ``table`` input
  type or refresh its input; both endpoints answer "Task not found".
- GPT-6 model ids get the reasoning profile only as the exact family name or
  with a ``-YYYY-MM-DD`` snapshot suffix. Other ``gpt-6`` ids, such as
  ``gpt-6-sol-latest``, get the conservative unknown profile; configure the
  exact family name or a dated snapshot to keep the reasoning profile.
- Usage statistics distinguish unreported from zero token counts
  (``isPromptTokensReported()``, ``isCompletionTokensReported()``), cached
  answers are screened against the current output policy, and boolean tool
  arguments must be native JSON booleans: string and numeric spellings
  trigger argument correction.

.. _version-0-43-21:

Version 0.43.21 (2026-11-08)
============================

The file list offers the AI actions on files and folders, a new tool sets an
image's categories from a configured category tree, and agent runs apply the
retention of the provider that actually answered.

- The file list offers the editor actions: **AI actions** in the context menu
  of a file opens the Editor Action Center for that file, and on a folder it
  lists the actions on files, each leading to the batch page with that folder
  as its scope. A file or folder outside the storage allow-list or the
  editor's file mounts gets no entry (ADR-267).
- The writing tool ``set_file_categories`` and its editor action **Set
  categories from the image** choose only existing categories below the root
  named in the extension setting ``vision.categories.rootUid`` and inside the
  editor's category mounts. Without a root the action is not offered and the
  tool refuses every call (ADR-268).
- Agent runs apply the retention ceilings of the provider actually used,
  including fallback and failed attempts, and persisted agent calls bypass
  provider-response idempotency caching (ADR-270). Run the TYPO3 database
  schema update before starting workers.
- Measured quality routing now requires ``routing.qualitySet`` and
  ``routing.qualityFingerprint``; re-evaluate the intended set and copy the
  fingerprint printed by ``nrllm:eval:run``. Without them, quality is unknown
  and the other routing signals remain available.
- The documentation resolves 115 audit findings and documents the Responsible
  AI capabilities, limitations and evidence procedures.

.. _version-0-43-20:

Version 0.43.20 (2026-11-06)
============================

The setup wizard comes first under AI > Setup, the error hints module asks
the configuration the administrator chose, in the backend language, and the
assistant no longer reports a write as refused because TYPO3 complained about
another record of the same save.

- The setup wizard is the first entry under AI > Setup, followed by providers,
  models and configurations; use-case packs come last, because a pack's
  configuration needs a provider and a model. On TYPO3 14.3, while no
  provider exists, the AI > Setup overview opens with a notice and a button
  to the setup wizard. TYPO3 13.4 has no such overview: AI > Setup opens the
  last used entry, or the setup wizard on the first visit.
- Error hints module: each administrator chooses which active LLM
  configuration is asked, from a list that marks what each configuration may
  receive by its trust zone. The choice is kept in the backend user's module
  data and falls back to the default configuration when the chosen one is
  deleted or deactivated. A refusal for the trust zone says what to do and
  links to Configurations and Providers.
- Error hints module: refusals and the model's answer follow the
  administrator's backend language instead of always being English; the
  console command stays English.
- A write the assistant made is no longer reported as refused because TYPO3
  logged an error about another record of the same save (for example from a
  DataHandler hook that writes another page). Every writer reads the record
  back first: what took is reported as written, with TYPO3's messages
  attached; a write of which nothing is left is reported as failed; a partial
  result is reported as written in part, to be checked. The attaching writers
  also check that the new reference points at the file asked for.

.. _version-0-43-19:

Version 0.43.19 (2026-11-06)
============================

The Analytics module's daily chart adds up to its cost, request and token
tiles again, and large counts fit their tiles.

- The daily trend kept only the last bucket of a day that holds rows under
  more than one ``request_date`` (local midnight next to a seed written at the
  database server's midnight, or rows from before a timezone change). Every
  bucket now counts towards its day, for every range of the module.
- The request and token tiles of the Analytics module and of the overview
  shorten counts from one million on in the backend user's locale
  ("17,5 Mio.", "17.5M"). The full number stays on hover and for screen
  readers.
- The charts take their legend, axis titles and screen-reader table headers
  from the translations, and format their numbers in the backend language.

.. _version-0-43-18:

Version 0.43.18 (2026-11-06)
============================

The assistant's writers work in the editor's TYPO3 workspace. In a run started
in a draft workspace they store their change there, and live stays as it is
until the workspace is published; the approval card names the workspace.

- The field writers ``update_page_metadata``,
  ``set_file_reference_alternative_text``, ``set_file_alternative_text``,
  ``update_fal_asset_meta`` and ``publish_record`` store a workspace version
  (:ref:`ADR-262 <adr-262>`). The redirect writers, which cannot keep a
  draft, are refused before the approval card in a draft workspace.
- The creating writers ``create_content_element_draft``,
  ``create_page_draft``, ``create_record_draft``,
  ``attach_file_to_content_element`` and ``attach_file_to_record`` create
  records new in the workspace, also on pages and records that exist there
  only (:ref:`ADR-264 <adr-264>`). ``set_page_social_image`` and
  ``replace_file_reference`` still write in live only and are refused before
  the card in a draft workspace.
- The structural writers ``move_content_element``, ``move_page``,
  ``copy_record``, ``delete_record`` and ``create_translation_draft`` move,
  copy, mark for deletion and translate in the workspace
  (:ref:`ADR-266 <adr-266>`). Commands TYPO3 would carry out differently there
  are refused before the card.
- The fill of an empty field after a save and background batches started in
  a draft workspace record the workspace and write there
  (:ref:`ADR-265 <adr-265>`); a fill whose draft was discarded, published or
  sent to review before the worker ran leaves the field empty.
  ``nrllm:editor-action:batch`` runs in live.

.. _version-0-43-17:

Version 0.43.17 (2026-11-05)
============================

A configuration can exclude single tools (:ref:`ADR-261 <adr-261>`): the new
field :guilabel:`Excluded tools` withholds the selected tools from agent runs
with that configuration, on top of the allowed tool groups and every other
gate, when the tools are offered and again when a call is executed. It never
enables a tool, and a tool registered later is not excluded until it is
selected. A run waiting for an approval or input keeps the exclusions it
started under. The list of tools a chat is not offered reports such a tool with
the reason ``configurationExcluded``; the Tools module and the
:guilabel:`Governance` tab show which configuration excludes which tool.

The read tools read records as the run's draft workspace shows them
(:ref:`ADR-263 <adr-263>`): ``read_records``, ``search_records``,
``get_pagetree``, ``read_fal_asset_meta``, ``search_fal_files``,
``get_fal_references`` and ``highlight_record`` return each record under its
live uid with filters, limits and offsets applied to what that workspace shows,
and ``get_record_history`` and ``get_tsconfig`` read in that workspace. In live,
and in a run that did not record its workspace, they read live rows.
``get_record_history`` and ``get_tsconfig`` no longer read another workspace's
draft when the model names a version row's uid or a record new in another
workspace.

Choosing a model in the model form no longer unticks capabilities nobody
denied. Where discovery cannot say what a model lacks — an OpenAI or Gemini
chat model the built-in catalogue does not list yet, every Groq model, an
Ollama model whose capabilities the host did not report — the list is marked
as inferred, and a record that already ticks that kind of model keeps its other
capabilities, such as ``tools`` and ``vision``.

.. _version-0-43-16:

Version 0.43.16 (2026-11-05)
============================

An MCP server can be connected through OAuth (:ref:`ADR-259 <adr-259>`): the
new authentication mode *OAuth connection* discovers the server's
authorization server, uses a pre-registered client or registers one through
Dynamic Client Registration, and runs the authorization code flow with PKCE
from :guilabel:`Connect` in the MCP module. Access and refresh tokens are
Vault secrets and are renewed before they expire; :guilabel:`Disconnect`
deletes them.

The chat changes a content element in the editor's TYPO3 workspace
(:ref:`ADR-260 <adr-260>`): a run started in a draft workspace stores a
workspace version of the element through the DataHandler, live stays as it is
until the workspace is published, and the approval card names the workspace.
``get_page_content`` reads the page as that workspace shows it. A run is pinned
to the workspace it was started in and writes nowhere else; a run that does not
record it keeps writing in live only. The other writers still refuse a draft
workspace. :php:`AiActorContext` carries ``workspaceId`` and
:php:`AfterAiRecordWrittenEvent` carries ``workspace``.

.. _version-0-43-15:

Version 0.43.15 (2026-11-05)
============================

The model rounds of an agent run are settings (:ref:`ADR-101 <adr-101>`):
``agent.defaultMaxIterations`` (default 5) for a run that asks for no number,
and ``agent.maxIterations`` (default 20), the most any run may take, also after
an approval or an input, with the value in force when the run resumes. Invalid
values fall back to 5 and 20, and a default above the maximum is the maximum.
The Governance tab and the playground show the values that apply, and the new
``AgentRoundLimitsInterface`` lets consuming extensions read them.
``AgentRuntime::MAX_ITERATIONS`` is now the fallback of the maximum.

.. _version-0-43-14:

Version 0.43.14 (2026-11-04)
============================

Error hints for administrators (:ref:`ADR-258 <adr-258>`). The backend module
*AI > Operation > Error hints* lists the ten newest errors from the TYPO3 log
files and asks a model, per entry and on request, for the likely cause and the
next step; ``nrllm:exception:hint`` does the same on the command line. What is
sent depends on the provider's trust zone, argument values and source code are
never sent, and a hint is labelled a guess unless it points at a frame in the
project's own code. ``get_last_exception`` and ``probe_url`` now name the class
and throw site of entries TYPO3's handlers log without a trace.

.. _version-0-43-13:

Version 0.43.13 (2026-11-04)
============================

Five administrator tools for site chores (:ref:`ADR-257 <adr-257>`).
``list_redirects`` lists the redirects of EXT:redirects; ``create_redirect``,
``update_redirect`` and ``disable_redirect`` create, change and switch off one
redirect under the redirects module's rules, and the approval card shows each
field before and after and when visitors get the change. ``clear_cache`` clears
one cache group or the page caches by tag; its card names a full flush as one.
The four writers ship disabled and pause for approval.

.. _version-0-43-12:

Version 0.43.12 (2026-11-04)
============================

Routing can rank by what calls actually took and cost. With the new setting
``routing.callHistory`` (off by default), the Balanced, Quality and Economy
modes add each model's mean latency over its last seven days of calls of the
same operation, and rank cost by the measured cost per call instead of the list
price wherever every priced candidate has one (:ref:`ADR-256 <adr-256>`). A call
through a criteria-mode configuration now records its cost and model, so cost
budgets count those calls from this version on. Provider health is looked up by
adapter type, which can change decisions in a measured mode. Database change:
two columns on ``tx_nrllm_telemetry``.

.. _version-0-43-11:

Version 0.43.11 (2026-11-04)
============================

A configuration can answer a repeated plain chat or completion request from a
cache: the new field ``answer_cache_ttl`` (seconds, 0 = off by default, at most
one day) serves the same answer again without a provider call. Agent runs, tool
calls, schema-bound calls and streamed answers are never cached, and an answer
is kept only where the content privacy level is ``full`` for the provider that
served it (:ref:`ADR-249 <adr-249>`). Evaluation runs always ask the model.
Database change: one column on ``tx_nrllm_configuration``.

.. _version-0-43-10:

Version 0.43.10 (2026-11-04)
============================

The text under a record select says what the chosen model, configuration,
task, provider or skill is for, and changes with the choice, also for keyboard
and screen reader users. Next to the select, a button creates a new record and
another opens the module that lists them; each is shown only to users who may
use it (:ref:`ADR-255 <adr-255>`, :ref:`administration-record-selects`).

.. _version-0-43-9:

Version 0.43.9 (2026-11-03)
===========================

A consuming extension reads what it spent and how often each of its operations
ran and failed through the public ``ExtensionUsageReaderInterface``, which also
says how far back the per-call counts reach (:ref:`ADR-248 <adr-248>`). A
streamed call records its caller on the usage aggregate as a non-streamed call
does, so its cost is no longer filed as unattributed.

.. _version-0-43-8:

Version 0.43.8 (2026-11-03)
===========================

A tool call whose arguments do not fit the schema its tool was offered with is
answered with what does not fit, and nothing of that turn runs: no tool, no
approval card, no question, no external call budget (:ref:`ADR-246 <adr-246>`).
Azure OpenAI provider records send the key in the ``api-key`` header and reach
the deployment with an ``api-version``, or the v1 API when the endpoint ends in
``/openai/v1`` (:ref:`ADR-247 <adr-247>`). Telemetry rows of a configuration in
automatic model selection name the model the selection chose instead of an
empty one, and such a configuration passes that model to the adapter, so its
embeddings no longer fall back to the adapter's default model.

.. _version-0-43-7:

Version 0.43.7 (2026-11-03)
===========================

The skill select of a configuration and a task filters and labels its skills
on TYPO3 13.4 and 14.3: an orphaned or disabled skill is offered only where it
is already attached, with its state in the label, and a new ``always``
attachment no longer offers a process skill. Before, the select offered every
skill unfiltered.

.. _version-0-43-6:

Version 0.43.6 (2026-11-03)
===========================

The page for running an editor action on several records no longer asks for
typed record numbers or a typed page number or folder identifier: the records,
and the page or folder a background batch starts from, are chosen in TYPO3's
element browser, on TYPO3 13.4 and 14.3. The server checks the choice as
before.

.. _version-0-43-5:

Version 0.43.5 (2026-11-02)
===========================

A task can be marked for the places that offer it, in the new field
:guilabel:`Offered first in`. The editors' module *AI tasks* lists the tasks
marked for it first, under *Prepared for this module*, and labels the other
groups by category; a task marked only for another extension's place is left
out there. Another extension orders its own task select by a placement with
``TaskPlacementItems::groupByPlacement()`` (ADR-245,
:ref:`configuration-tasks-placements`). Run the database schema update for the
new column ``tx_nrllm_task.placements``.

.. _version-0-43-4:

Version 0.43.4 (2026-11-02)
===========================

A site can name the LLM configuration its AI calls use and the DeepL key its
translations use, as identifiers in two site settings of the new set
``netresearch/nr-llm``; a site that names neither keeps the installation's
(ADR-244, :ref:`administration-site-settings`). A caller states its site with
``withSite()``, which also scopes the term lists bound to a site.

.. _version-0-43-3:

Version 0.43.3 (2026-11-02)
===========================

A provider and a task can tighten the privacy level for what is stored of their
runs, with a :sql:`privacy_level` field. It cannot loosen the global level
(ADR-242). The rule set is a tagged snippet plus a term list, with no new record
(ADR-243). Run the database schema update for the new columns.

.. _version-0-43-2:

Version 0.43.2 (2026-11-02)
===========================

In an agent run that offers tools, as the AI chat does, a term list checks only
what a writing tool would store. Chat text streams and stays free, and a search
for a forbidden term runs (ADR-238). A reasoning model of OpenAI gets an output
limit of at least 16,000 tokens unless the call switches thinking off, and an
answer that ends without text at the limit raises :php:`OutputBudgetExhaustedException` (ADR-241).

.. _version-0-43-1:

Version 0.43.1 (2026-11-01)
===========================

The text of a tool round reaches an observer while the model writes it, on OpenAI's
Responses transport (ADR-240). A chat can show an answer before the round has ended.
A run of text without whitespace now waits in the live redaction until it ends, up
to 4 KB, so a JWT is masked whole (ADR-088).

.. _version-0-43-0:

Version 0.43.0 (2026-11-01)
===========================

Term lists, a mark on what an AI wrote, and where the time of a model call goes.
Update the database schema: ``pages`` and ``tt_content`` gain
``tx_nrllm_ai_written``, and the table ``tx_nrllm_term_list`` is new. Until the
update ran, new pages and content elements cannot be saved. A consumer pinned to
``^0.42`` needs ``^0.43``. Details in the repository :file:`CHANGELOG.md`.

*  Term lists: forbidden and required terms in a record stop generated text, and
   the editor reads which term and which list did it (ADR-238).
*  A page or content element an agent run or a fill run wrote carries
   ``tx_nrllm_ai_written`` (ADR-239).
*  The step of a model call in the Verlauf shows the cached part of the prompt, the
   wall time of the HTTP call and the time the provider reports.

.. _version-0-42-0:

Version 0.42.0 (2026-11-01)
===========================

Background work. Update the database schema: ``tx_nrllm_agentrun`` gains
``origin``, ``answer`` and ``outcome``. A consumer pinned to ``^0.41`` needs
``^0.42``. Details in the repository :file:`CHANGELOG.md`.

*  ``nrllm:task:enqueue`` queues an AI Task, ``nrllm:agent:work`` works the queue
   from cron or the scheduler without a Messenger consumer, and the Agent Runs
   module lists these runs under *Background runs* (ADR-234).
*  :php:`AgentRuntimeInterface::defer()` stores a queued run and never executes it
   in the caller. A class outside nr-llm that implements the interface has to add
   it.
*  ``worker.technicalBeUserUid`` names the backend user whose grants on a vault
   secret count on the command line. With ``allowCliAccess`` off and without this
   setting, every queued run fails on its first model call.
*  The editor actions for the alternative text and the file metadata send the
   image to the model (ADR-235). They are no longer offered on a model that
   declares capabilities without ``vision``.
*  An editor action runs over a folder or a page tree in the background, from the
   batch page and from ``nrllm:editor-action:batch``. Each record becomes a run
   that waits for its own approval (ADR-236).
*  An empty field can be filled by AI after a save, from ``fieldFill.fields``
   (ADR-237).

.. _version-0-41-18:

Version 0.41.18 (2026-10-31)
============================

Behaviour change in guided processes; no database schema or extension
setting change. Details in the repository :file:`CHANGELOG.md`.

*  In a run holding a process pin, a turn that pauses for input runs the
   reads beside the question before the pause, as a turn that pauses on a
   proposal already does (ADR-214, amendment of 2026-10-31). A process step
   can report its progress and highlight in the same response as its
   question, without a model round of their own. A remote tool, a write and
   a second input call beside the question do not run. Outside process runs
   nothing changes.

.. _version-0-41-17:

Version 0.41.17 (2026-10-30)
============================

Bug fix; no database schema or extension setting change. Details in the
repository :file:`CHANGELOG.md`.

*  A write proposal shows a length only against a configured range
   (ADR-214): the approval card of ``update_page_metadata`` and
   ``update_content_element`` no longer shows a bare character count, such
   as "Länge 23 Zeichen" for a page title, for a field without a range in
   ``tools.structuredPreview.ranges``. With the shipped default only
   ``pages.description`` carries one.
*  The fourth parameter of :php:`FieldMeasurer::measure()`,
   ``$countWithoutRange``, is optional, deprecated and ignored. It stays in
   place so that an existing call keeps passing ``$richText`` fifth; new
   calls omit it and pass ``richText:`` by name.

.. _version-0-41-16:

Version 0.41.16 (2026-10-30)
============================

Bug fix; no database schema change. Details in the repository
:file:`CHANGELOG.md`.

*  Gemini structured output keeps properties whose type is one type plus
   ``null``, such as ``["string", "null"]``: they are sent as that type
   with ``nullable: true``. An object whose properties were all nullable
   previously reached Gemini without properties, and Gemini rejected the
   request with HTTP 400.
*  An object without any expressible property, and an array without
   expressible items, are left out of the response schema instead of
   being sent empty.

.. _version-0-41-15:

Version 0.41.15 (2026-10-29)
============================

Bug fix; no database schema change. The unused extension settings
``image.dalle.defaultModel`` and ``image.dalle.defaultSize`` are removed.
Details in the repository :file:`CHANGELOG.md`.

*  Image generation without a model no longer calls the retired
   ``dall-e-3``: :php:`DallEImageService::generate()` and
   :php:`generateMultiple()` take the model registry's default image model
   and fall back to ``gpt-image-2``. An explicit model still wins.
   :php:`ImageGenerationOptions` defaults ``model`` to ``null``.
*  :php:`ImageGenerationOptions::hd()` is sent as quality ``high`` to
   gpt-image models; ``standard`` is not sent, so the API default
   ``auto`` applies.
*  The image test page reports a request the provider refuses as a
   refused request with its status code (502) instead of "not
   configured". The exception carries the status through
   :php:`getStatusCode()`.

.. _version-0-41-14:

Version 0.41.14 (2026-10-29)
============================

No database schema or extension setting change. Details in the
repository :file:`CHANGELOG.md`.

*  Optional revision of a DeepL translation by a language model
   (ADR-233): :php:`TranslationOptions::withPolish()` lets
   :php:`translateWithTranslator()` and its batch variant revise the result
   for fluency, terminology and tone. The revision is an ordinary request
   (a polished translation uses two); when it changes tags, placeholders
   or glossary terms, adds text, fails or is refused by the budget, the
   first translation is returned. Off by default.

.. _version-0-41-13:

Version 0.41.13 (2026-10-28)
============================

No database schema or extension setting change. Details in the
repository ``CHANGELOG.md``.

*  A streamed answer reaches the caller while the provider is still
   writing it. The streaming adapters send with nr-vault's
   ``sendStreaming()`` (nr-vault 1.1) instead of the blocking
   ``sendRequest()``, which delivered every chunk at the end. A provider
   without an API key, such as a local Ollama, streams too, after the same
   host check, and each such call writes an nr-vault audit row.
*  A dropped connection before the first chunk still lets the streaming
   fallback try the next configuration. A 401 or 429 whose error body is
   cut off keeps its exception class.
*  With nr-vault 0.16 or without ``curl_multi_*`` the request is sent as
   before.

.. _version-0-41-12:

Version 0.41.12 (2026-10-28)
============================

No database schema or extension setting change. Details in the
repository ``CHANGELOG.md``.

*  New developer page :ref:`developer-own-provider-usage`: how an extension
   that calls a provider with its own code checks the editor's budget and
   reports its usage, so its calls count against the budget and appear in
   Analytics and the billing report under its extension key.
*  Translations through ``translateWithTranslator()`` and
   ``translateBatchWithTranslator()`` keep the caller named with
   ``withCallerSource()``; before, DeepL and the LLM translator recorded
   them unattributed.
*  ``nrllm:demo:seed`` records seeded completion usage as ``complete``.

.. _version-0-41-11:

Version 0.41.11 (2026-10-28)
============================

No database schema or extension setting change. Details in the
repository ``CHANGELOG.md``.

*  An editor's own budget status is public API: ``UserBudgetStatusService``,
   the value objects ``UserBudgetStatus``, ``BudgetWindowStatus`` and
   ``BudgetMeter``, and ``LocalizedNumberFormatter`` are now ``@api``, so
   another extension can show the usage and limits the AI Tasks module
   shows (ADR-232).
*  The billing report names its entitlement count "Entitled to AI Tasks".
   Users whose access comes only from an extension with its own permission
   checks, such as an editor assistant or a chat, are not part of it.

.. _version-0-41-10:

Version 0.41.10 (2026-10-28)
============================

No database schema change. A new extension setting
``billing.markupPercent`` (whole percent, default 0) sets the markup of
the billing report. Details in the repository ``CHANGELOG.md``.

*  A monthly billing report in the Analytics module: for a calendar month,
   the estimated cost with the markup, the active editors with their cost,
   the editors entitled to the AI features as of today, and the results by
   service type, calling extension and task, with a CSV export of the same
   month.

.. _version-0-41-9:

Version 0.41.9 (2026-10-28)
===========================

Bug fix; no database schema or extension setting change. Details in the
repository ``CHANGELOG.md``.

*  Backend views print token counts and USD amounts in the backend user's
   language: a German reader sees "5.138.706" and "11,40 $" instead of
   "5,138,706" and "$11.40". This covers the AI Tasks budget panel, the
   run estimate, the Analytics and overview modules, the usage columns of
   the list views, the AI actions batch estimate and the agent runs.
   Amounts stay in USD.

.. _version-0-41-8:

Version 0.41.8 (2026-10-28)
===========================

No database schema or extension setting change. A new AJAX route
``nrllm_aitask_budget`` serves the AI Tasks budget panel. Details in the
repository ``CHANGELOG.md``.

*  The AI Tasks module shows editors their own usage today and this month
   against their personal budget, with a progress bar per limit, and says
   when no personal limit is set. The run form adds a cost estimate for
   one run of the task and states whether the budget lets the next run
   start, naming the limit that would refuse it.
*  Landing pages: canonical and language alternate metadata outside the
   document head is rejected, and the bilingual title panels follow the
   shared page family.

.. _version-0-41-7:

Version 0.41.7 (2026-10-25)
===========================

Bug fix; no database schema or extension setting change.
``ReplaceFileReferenceTool`` takes a fourth constructor argument, which
the container wires. Details in the repository ``CHANGELOG.md``.

*  The writing tools ``set_file_reference_alternative_text``,
   ``attach_file_to_record`` and ``replace_file_reference`` check every
   record of another table that holds an inline child, up the chain,
   before they write. An editor can no longer change a file reference on
   an inline child whose outer record is locked, outside their grant or on
   a page they may not edit. A relation of a table to itself, such as a
   grid container holding content elements, is not followed, because the
   page module edits those children on their own (ADR-230).

.. _version-0-41-6:

Version 0.41.6 (2026-10-25)
===========================

New feature; no database schema or extension setting change. Details in
the repository ``CHANGELOG.md``.

*  The new writing tool ``set_file_reference_alternative_text`` sets the
   alternative text of one use of an image (``sys_file_reference``), in
   place and in the reference's language. It ships disabled, works in the
   live workspace only and asks for approval (ADR-230).

.. _version-0-41-5:

Version 0.41.5 (2026-10-25)
===========================

Database schema change: run the database analyzer, because
``tx_nrllm_skill`` gains the column ``page``. Existing rows keep today's
behaviour, and no skill approval is invalidated. Two additive public API
changes. Details in the repository ``CHANGELOG.md``.

*  A skill can declare in its frontmatter whether it works on a page:
   ``page: required``, ``optional`` or ``none``. ``Skill::getPageNeed()``
   returns it as ``SkillPageNeed``. The field is not part of the version
   digest.
*  ``ApproverNotPermittedException`` and
   ``SubmitterNotPermittedException`` say why they refused:
   ``getCause()`` and ``getDenialReason()``. A surface can tell a
   switched-off tool from missing permissions.
*  The decision and reranker sidecar images install filelock 4.2.4 and
   setuptools 84.2.1 for open advisories.
*  The public product site: mobile layout, language switch and a header
   shared with the other Netresearch pages.

.. _version-0-41-4:

Version 0.41.4 (2026-10-24)
===========================

Bug fix; no database schema or extension setting change. One additive
public API change: ``ApprovalDenialReason`` gains the case ``KEEP``.
Details in the repository ``CHANGELOG.md``.

*  A guided process can tell "keep the current content" from "skip this
   point". The denial result for ``KEEP`` says the point is settled and
   must not be called skipped.

.. _version-0-41-3:

Version 0.41.3 (2026-10-22)
===========================

Bug fixes; no database schema or extension setting change. One additive
public API change: ``SuspendedRunState`` takes an optional
``maxIterations`` as its last constructor parameter. Details in the
repository ``CHANGELOG.md``.

*  A resumed agent run keeps the round cap it was started with. Before,
   every segment after an approval or an answered input ran on the loop
   default of 5. A run suspended before this release resumes on the
   default as before.
*  ``set_page_social_image`` with ``replace`` removes a copy of the old
   reference that a translation left on the default-language page, also
   on TYPO3 14.3.10.

.. _version-0-41-2:

Version 0.41.2 (2026-10-22)
===========================

Bug fixes; no public API, database schema or extension setting change.
Details in the repository ``CHANGELOG.md``.

*  An invoked process skill is composed as its own section, "Active
   process", ahead of the approved skills, and governs the order of steps
   and the structure of answers; approved skills keep applying to wording,
   terminology and safety. Before, an approved skill attached always could
   override the process, so a guided tour ended its first turn without the
   tool calls it prescribes.
*  Skill import refuses a GitHub repository tree that GitHub reports as
   truncated and keeps the last known-good skills, and a malformed GitHub
   response keeps its typed failure when the diagnostic logger throws.
*  The decision and reranker sidecar images build again on Python 3.14,
   lock their dependencies with uv and install certifi 2026.10.19.

.. _version-0-41-1:

Version 0.41.1 (2026-10-21)
===========================

Bug fixes and test coverage; no public API or extension setting change. Update
the database schema: skill sources gain two columns for the synchronization
lease. Skill sources, skills and the skill audit table must share one database
connection. Details in the repository ``CHANGELOG.md``.

*  A process skill attached in the field "Skills loaded on demand" is listed,
   can be started and continues; before, only skills loaded always counted
   (ADR-214).
*  Gemini tool conversations keep native thought signatures and echo native
   function-call IDs through parallel calls and stored approval resumes
   (ADR-222, ADR-224).
*  MCP imports skip tools whose schema they cannot keep intact instead of
   importing a weakened schema.
*  A failing preview read check no longer blanks the approval inbox, and
   optional diagnostic loggers that throw no longer replace the original
   outcome in agent recovery, governance, MCP import, skill tokens and the
   Ollama model picker.
*  Skill metadata closes only at a standalone ``---`` delimiter.
*  Skill synchronization holds a persisted lease and publishes skill, orphan
   and audit changes in one transaction (ADR-221).
*  Gemini responses keep every visible text part and separate native thinking
   (ADR-223); MCP imports report tool names another server already holds.

.. _version-0-41-0:

Version 0.41.0 (2026-10-20)
===========================

Approved skill versions, guided processes and skills loaded on demand
(ADR-214, ADR-228), with a security fix for the read tools. Callers of the
PHP API are unaffected; a class that implements ``AgentRuntimeInterface``,
``ToolCallPolicyInterface`` or ``ToolLoopServiceInterface`` has to follow
their new members. Update the database schema and run the upgrade wizard
"Write the version digest onto existing skills". Details in the repository
``CHANGELOG.md``.

*  An administrator approves one version of a skill; an approved version from
   a trusted source is composed into the system message as an instruction,
   every other skill stays fenced. Skills can be written in the backend and
   loaded on demand through the new ``load_skill`` builtin.
*  A guided process is started, continued and ended through a skill
   invocation and decided in the chat, one proposal per turn. New builtins
   ``ask_choice`` and ``highlight_record`` ship disabled.
*  Approval cards carry a write's record, fields and proposed values as
   structured data, and a write states whether it did everything it planned.
*  Invocation rules, optional actor-bound MCP authentication, retrieval
   experiment provenance, verified evaluation generators and worker
   operations status (ADR-215, ADR-216, ADR-217, ADR-219, ADR-220).
*  Security: the read tools check web mounts against the run's acting user,
   not the backend user of the request (#1022).

.. _version-0-40-0:

Version 0.40.0 (2026-10-08)
===========================

Every built-in approval preview is in the acting user's language. No breaking
change to the PHP API; details in the repository ``CHANGELOG.md``.

*  The approval cards of the remaining fifteen previewing tools and the lines
   the tool loop puts on a card use the acting user's language (ADR-213,
   #1016). Runs suspended before the upgrade bounce once at resume.
*  New public class ``ApprovalPreviewHeadings`` names the first line of every
   built-in preview for approval cards in other extensions.
*  TCA labels on approval cards resolve TYPO3 14 translation domain
   references.
*  ``attach_file_to_content_element`` refuses a translated content element.

.. _version-0-39-0:

Version 0.39.0 (2026-10-07)
===========================

Typed decisions (ADR-211), hidden-record reading, a tool to attach a file to
any record's file field, and localised approval previews. Breaking changes to
the PHP API; full details and the migration in the repository
``CHANGELOG.md``.

*  **Breaking:** ``completeStructured()`` and
   ``completeStructuredForConfiguration()`` return a
   ``StructuredCompletionResponse``; read ``->data`` where the array was read.
   Implementations of ``CompletionServiceInterface`` and
   ``LlmServiceManagerInterface`` gain the optional ``?ModelResolution``
   parameter, the latter also ``decideForConfiguration()``.
*  **Breaking:** model prices are ``float`` and stored as ``decimal(12,2)``
   (database compare).
*  **Breaking:** the ``llm_judge`` evaluation grader is replaced by
   ``--grader decision``.
*  ``DecisionServiceInterface``, the TypeSafe provider and a local decision
   sidecar answer typed yes/no, choice and score questions.
*  ``read_records`` and ``search_records`` take ``include_hidden`` (#1009).
*  New writing tool ``attach_file_to_record`` (ADR-212, #1011).
*  Approval previews of ``create_page_draft``, ``move_page`` and
   ``delete_record`` use the acting user's language (ADR-213, #1012).

Versions 0.21.0 to 0.38.2 are listed in the repository ``CHANGELOG.md``.

.. _version-0-20-0:

Version 0.20.0 (2026-07-16)
===========================

Closes out the operator-config audit: every backend-visible provider, model
and configuration setting now either works or is gone. Three breaking
changes; full details in the repository ``CHANGELOG.md``.

*  **Breaking:** the provider *API timeout* is now applied as a
   total-response timeout (previously write-only — requests ran unbounded).
   Default moves 30 → 120 seconds; the ``nrLlm_providerApiTimeout120``
   upgrade wizard migrates rows persisted at the old default; timed-out
   requests are not retried (#384).
*  **Breaking:** *Max retries* counts retries after the initial attempt —
   ``0`` sends exactly one request instead of none (#387).
*  **Breaking:** the never-functional PromptTemplate stack is removed
   (ADR-069); drop the orphaned ``tx_nrllm_prompttemplate`` table via the
   database analyzer (#399). ``BudgetServiceInterface::check()`` gained an
   optional ``?LlmConfiguration`` parameter (#389).
*  Per-configuration daily limits (requests / tokens / cost) are enforced
   alongside per-user budgets — most restrictive wins (#389).
*  Model *Max output tokens* and *Dimensions* act as call defaults when the
   caller and configuration leave them unset (#390).
*  Provider *Organization ID* is sent as ``OpenAI-Organization``;
   ``options.customHeaders`` is applied on all request paths (#388).

.. _version-0-19-1:

Version 0.19.1 (2026-07-15, first shipped with 0.20.0)
======================================================

Prepared but never tagged; its fixes first ship in 0.20.0.

*  Criteria-mode configurations work with every ``*ForConfiguration()``
   call — the model is resolved through ``ModelSelectionService`` instead of
   the (null) direct relation (#372).
*  Embedding cache tags built from dotted preset identifiers are sanitized
   (#372).

.. _version-0-19-0:

Version 0.19.0 (2026-07-14)
===========================

*  Per-user usage attribution for the specialized speech and image services
   (ADR-057): transcription, synthesis and image generation record the
   calling backend user instead of the ambient bucket (#362).
*  Configuration identifiers with dots no longer crash cached calls — cache
   keys and tags are sanitized (#365); ``LlmTranslator`` chat rows carry the
   per-user attribution (#361); backend model-test button fixed (#363).

.. _version-0-18-0:

Version 0.18.0 (2026-07-14)
===========================

*  Configuration presets module UI (ADR-056 follow-up): the Configurations
   backend module surfaces pending presets with one-click apply/update.

.. _version-0-17-0:

Version 0.17.0 (2026-07-13)
===========================

*  Configuration presets: consuming extensions declare the
   ``LlmConfiguration`` records they need via the
   ``nr_llm.configuration_preset`` registration (ADR-056).

.. _version-0-16-1:

Version 0.16.1 (2026-07-10)
===========================

Compatibility fixes for strict-mode MySQL/MariaDB, surfaced by the new
MariaDB CI leg: the setup wizard's AJAX persist path now enforces the
column length limits and the TCA identifier contract (overlong values
previously produced a 500 instead of being truncated), generated
identifier suffixes are collision-free within a batch, and
decimal-backed values such as ``temperature`` are rounded to their
column scale so every DBMS round-trips identical values.

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

.. _version-0-16-0:

Version 0.16.0 (2026-07-10)
===========================

This release adds **RAG site-search tools**: the new ``rag`` tool group
lets agent runs answer questions about the website's own public
content with cited evidence. ``site_rag_query`` returns curated sources
(``source_id``, title, URL, match excerpt) from a priority cascade over
whichever search index is installed — EXT:solr (via its HTTP select
API), ke_search, indexed_search — with an always-available
pages/tt_content database fallback that matches natural-language
questions word-wise; ``site_fetch_source`` reads a source's full
indexed text. Index-level filtering is strictly public-only (what the
anonymous visitor could read), and every evidence package names the
answering backend. See :ref:`ADR-049 <adr-049>`.

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

.. _version-0-15-0:

Version 0.15.0 (2026-07-09)
===========================

This release turns the Tool Playground into a **glass-box run inspector** —
every request, response, tool execution and the final answer stream live
into an ordered step list with token, cost and timing detail, plus a
dry-run mode that shows the exact prompt without calling the model — and
grows the built-in tool set to **39 tools in 7 groups**: record history
("who changed this?"), URL resolution, TCA/TypoScript validation,
error-analysis (last exception, source read/search, URL probe), FAL file
tools and system diagnostics. Groups can be toggled centrally, restricted
per configuration and selected per run, cascading fail-closed.

**Breaking:** ``ToolInterface`` gained ``getGroup(): string`` — every tool
implementer must declare its group (third-party tools: use the extension
key).

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

.. _version-0-14-1:

Version 0.14.1 (2026-07-05)
===========================

A patch release fixing tool calling with parameterless tools. A tool that
takes no arguments emitted its (empty) JSON-Schema ``properties`` — and its
empty replayed call ``arguments`` — as a JSON array ``[]`` instead of an
object ``{}``, which strict providers such as Ollama reject with an HTTP 400.
The bounded agent loop and the Tool Playground now work when a parameterless
tool (environment, PHP info, or backend user/group introspection) is offered.

The Skills, Tools and Playground admin documentation was also refreshed to
match the shipped backend (module section count, the full built-in tool
catalogue, two-tier tool authorization, and updated screenshots).

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

.. _version-0-14-0:

Version 0.14.0 (2026-07-04)
===========================

This release adds a **Skills and Tools** system: extensions and editors can
ingest ``SKILL.md`` files from GitHub (SHA-pinned, admin-reviewed) and attach
them to tasks and configurations, and a function-calling **tool runtime** lets a
model run an agent loop over an admin-curated, permission-gated set of tools —
with an interactive tool playground in the backend. It also lands a broad
security and accessibility hardening pass (SSRF/CSRF fixes, API keys moved out of
URLs, RBAC on tool execution, EN/DE translations, WCAG text alternatives), and
the CI now actually runs the functional and backend end-to-end suites so they
gate merges.

**Breaking:** custom ``ToolInterface`` implementations must now declare a
``requiresAdmin(): bool`` method — ``true`` for tools that expose system,
host, or cross-user data, ``false`` for tools that self-enforce the acting
user's TYPO3 permissions. Without it the tool fails at runtime (ADR-038).

Together AI, Fireworks AI and Perplexity are now first-class OpenAI-compatible
providers, and provider endpoints entered in the wizard or the record editor are
canonicalized on save so they no longer break when saved without an API version
path.

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

.. _version-0-13-0:

Version 0.13.0 (2026-06-26)
===========================

Provider selection is now database-driven end to end. **Breaking:** the
extension-configuration ``defaultProvider`` fallback is removed — select a
provider per call (the options object's ``provider`` field) or mark a
Configuration active and default in the backend module; otherwise
``getProvider(null)`` throws (ADR-034). The dead ``plugin.tx_nrllm`` TypoScript
constants/setup were removed and the "no provider specified" error now carries
actionable backend-module guidance (#254, #255).

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

.. _version-0-12-0:

Version 0.12.0 (2026-06-11)
===========================

Specialized services (image, text-to-speech, transcription) gain full usage and
cost tracking, join the model registry with ``image``/``text_to_speech``/
``transcription`` capabilities, and resolve their model and system prompt from
Configuration records (ADR-032, ADR-033). Adds a prompt-snippet library
(``tx_nrllm_promptsnippet``, ADR-031), per-request timeouts on the secure HTTP
client, and arbitrary gpt-image sizes. **Requires nr-vault ^0.10.0.**

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

.. _version-0-11-1:

Version 0.11.1 (2026-06-10)
===========================

Security and robustness fixes from the extension-wide code review: the setup
wizard dispatches through nr-vault's SSRF-guarded secure HTTP client, the
provider adapters surface streaming errors as typed, credential-sanitized
exceptions, TTS text splitting is multibyte-safe, and FAL/Whisper
configuration parsing is hardened.

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

.. _version-0-11-0:

Version 0.11.0 (2026-06-10)
===========================

The backend module's default Configuration is now the single source of truth
for generic completion: ``chat()``, ``complete()`` and ``streamChat()`` resolve
the active default database-backed configuration (provider adapter, model and
vault-backed credentials) when no provider is pinned, with per-call options
overriding the stored defaults. The extension-configuration ``defaultProvider``
becomes a fallback for installations without a usable default configuration.

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

Added
-----

-   **Default-configuration routing for generic completion.** Calls without a
    pinned provider route through the module-managed default
    ``LlmConfiguration``; per-call ``ChatOptions`` override its stored
    defaults. ``chatWithConfiguration()`` / ``completeWithConfiguration()`` /
    ``streamChatWithConfiguration()`` accept an ``$optionOverrides`` array.

Changed
-------

-   The extension-configuration ``defaultProvider`` is consulted only when no
    usable default configuration exists. Defaults without a model, or with
    backend-group access restrictions, are skipped — group-restricted
    configurations are never auto-applied without a backend-user context.

.. _version-0-10-0:

Version 0.10.0 (2026-06-09)
===========================

The specialized AI services (DALL-E, FAL, Whisper, TTS, DeepL) now authenticate
through nr-vault's audited secure HTTP client instead of plaintext API keys,
bringing them in line with the database-backed providers (ADR-012, ADR-030).

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

Changed
-------

-   **Specialized services authenticate through nr-vault.** Each service stores
    an nr-vault secret *identifier* and authenticates via
    ``$vault->http()->withAuthentication(...)``; the secret is resolved,
    injected, audited, and memory-scrubbed inside the vault and never surfaces
    in this extension. FAL (``Authorization: Key …``) and DeepL
    (``Authorization: DeepL-Auth-Key …``) use the nr-vault 0.8.0 ``prefix``
    option. DeepL's Free/Pro routing stays automatic via a one-time, scrubbed
    ``:fx`` suffix check.

Removed
-------

-   **Plaintext API keys for the specialized services.** Configuration keys are
    now nr-vault identifiers (``providers.openai.apiKeyIdentifier``,
    ``image.fal.apiKeyIdentifier``, ``translators.deepl.apiKeyIdentifier``).
    Requires ``netresearch/nr-vault ^0.8.0``.

.. _version-0-9-0:

Version 0.9.0 (2026-06-08)
==========================

This release migrates image generation to OpenAI's **gpt-image-\*** model
family (DALL·E-3 was retired by OpenAI), makes chat JSON mode actually request
JSON, and corrects the empty base-URL handling of the specialized services.

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

Added
-----

-   **gpt-image-\* image generation.** ``ImageGenerationOptions`` accepts the
    ``gpt-image-*`` family by prefix and validates its size set
    (``1024x1024`` / ``1536x1024`` / ``1024x1536`` / ``auto``);
    ``DallEImageService`` maps the family to a shared capability profile and
    sends a minimal payload (gpt-image rejects ``response_format`` /
    ``style`` / ``quality``), reading the returned ``b64_json``.

Fixed
-----

-   **Chat JSON mode.** ``OpenAiProvider`` now maps ``response_format=json`` to
    OpenAI's ``{"type":"json_object"}`` so ``CompletionService::completeJson()``
    receives valid JSON instead of prose.

-   **Empty base URL.** An empty ext_conf ``baseUrl`` for the DALL·E, FAL and
    TTS services now falls back to the provider default instead of being used as
    a scheme-less request URL.

.. _version-0-8-0:

Version 0.8.0 (2026-06-02)
==========================

This release adds usage analytics and turns on real cost tracking, and
completes the provider **middleware pipeline** that now powers fallback,
pre-flight budget enforcement, usage accounting, and response caching
around every provider call. It also migrates the domain API to typed
value objects.

For the complete, itemised list see the canonical
`CHANGELOG.md <https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__.

Added
-----

-   **Usage Analytics dashboard.** A new *Admin Tools → LLM → Analytics*
    submodule with cost and request trends, breakdowns by provider, model,
    and service, KPI tiles, and per-user usage against each user's monthly
    budget. The Providers, Models, Configurations, and Tasks list views also
    gained per-row *Cost / Requests / Tokens (last 30 days)* columns. See
    :ref:`administration-analytics`.

-   **Real cost tracking.** Usage is now priced from the configured model
    rates (prompt/completion token split), so the *AI cost this month* widget
    and the dashboard show real figures instead of ``0``. The
    :sql:`tx_nrllm_service_usage` table gained model and token-split columns
    plus per-task attribution.

-   **Automatic budget pre-flight.** Completion, embedding, translation, and
    vision requests are checked against the configured budget before the call
    is made.

Changed
-------

-   The domain API moved to typed value objects (chat messages, tool specs,
    vision content, capability sets, provider options). The legacy
    string/array options accessors are deprecated in favour of the typed
    equivalents.

-   Requires ``netresearch/nr-vault`` ``^0.6.0``.

Breaking
--------

-   The legacy :php:`Model::CAPABILITY_*` class constants have been **removed**
    in favour of the :php:`ModelCapability` backed enum (for example
    ``ModelCapability::CHAT->value``). They had been deprecated since the enum
    was introduced.

.. _version-0-7-0:

Version 0.7.0 (2026-04-22)
==========================

Added
-----

-   **Provider fallback chain.** :php:`LlmConfiguration` can now list
    other configuration identifiers to retry against when the primary
    fails with a retryable error (connection / HTTP 5xx / 429 rate-
    limit). Non-retryable errors (4xx other than 429, configuration
    problems, unsupported feature) bubble up unchanged. Streaming is
    intentionally excluded from fallback because chunks cannot be
    replayed against a different provider. See :ref:`adr-021` and
    :ref:`developer-fallback-chain`.

-   **Attribute-based provider registration.** New
    :php:`#[AsLlmProvider(priority: N)]` attribute. Providers bearing
    the attribute are automatically tagged and made public by
    :php:`ProviderCompilerPass` at container compile time; no
    ``services.yaml`` edit required. Legacy yaml tagging still works
    for third-party providers and takes precedence when both
    mechanisms are present. See :ref:`adr-022` and
    :ref:`developer-provider-registration`.

-   **Per-capability BE group permissions.** Every
    :php:`ModelCapability` enum value is now a native TYPO3
    ``customPermOptions`` entry under the ``nrllm`` namespace. BE
    group editors see a checkbox per capability (chat, completion,
    embeddings, vision, streaming, tools, json_mode, audio). New
    :php:`CapabilityPermissionService` resolves checks against the
    current BE user with admin short-circuit and CLI / frontend
    bypass. See :ref:`adr-023`. These registrations and the check service
    were later removed (:ref:`adr-117`); current backend access uses
    :ref:`administration-permissions`.

-   **Dashboard widgets.** Two TYPO3 dashboard widgets sourced from
    :sql:`tx_nrllm_service_usage`: *AI cost this month*
    (:php:`NumberWithIconWidget`) and *AI requests by provider (7d)*
    (:php:`BarChartWidget`). Loaded conditionally from
    :file:`Configuration/Services.php` only when
    :composer:`typo3/cms-dashboard` is installed. See :ref:`adr-024`.

-   **Per-user AI budgets.** New :sql:`tx_nrllm_user_budget` table
    with six independent ceilings (requests / tokens / cost × daily /
    monthly). New :php:`BudgetService::check()` aggregates usage on
    demand from :sql:`tx_nrllm_service_usage` — one DB roundtrip for
    both windows via conditional ``SUM()``. Orthogonal to the
    existing per-configuration daily limits: both checks must pass.
    See :ref:`adr-025` and :ref:`administration-user-budgets`.

Changed
-------

-   CI: mutation testing runs only on ``push``, ``merge_group`` and
    ``schedule`` events. PR CI gets the fuzz suite + unit / functional
    / PHPStan / rector / code style; the ~15 min mutation job is
    deferred because its per-PR signal is hard for authors to action
    locally.
-   CI: :file:`.semgrepignore` added to exclude :path:`Tests/`,
    :path:`Build/Scripts/` and vendor directories from Opengrep SAST.
    Previously failing on legitimate ``unlink()`` fixture cleanup.
-   CI: fuzz workflow now invoked with
    ``fuzz-testsuite: fuzzy`` matching the phpunit.xml suite name.

.. _version-0-6-0:

Version 0.6.0 (2026-03-24)
==========================

Added
-----

- ``DocumentCapableInterface``: providers can now advertise
  PDF/document support; ``ChatCapabilitiesInterface`` exposes
  this via ``getProviderCapabilities()``.
- Multimodal content arrays in ``chatCompletion``: pass images,
  PDFs, and text blocks as structured content arrays alongside
  regular string messages.
- Tool message conversion: ``tool_result`` blocks are now mapped
  correctly when assembling provider payloads.

Changed
-------

- Migrated CI infrastructure to ``netresearch/typo3-ci-workflows``
  shared workflows (PHP tests, docs, E2E).
- Replaced GrumPHP with CaptainHook for pre-commit hooks.

Fixed
-----

- PHPStan baseline regenerated; ``ignoreErrors`` patterns
  broadened for deprecation and array function rules to handle
  phpstan-typo3 v2/v3 parameter name differences.
- E2E tests stabilised: heading verification added, module
  overview landing page assertions updated.

.. _version-0-5-0:

Version 0.5.0 (2026-03-09)
==========================

Added
-----

- AI-powered full-chain task wizard: describe a task in
  plain language, AI generates task + configuration +
  model recommendation in one step.
- AI-powered configuration wizard: generate
  configurations with system prompts, parameters,
  and model selection.
- Custom TCA ``ModelIdElement``: input field with
  "Fetch Models" button that populates from provider
  API, auto-fills capabilities and pricing.
- ``ModelConstraintsWizard``: field wizard that loads
  parameter constraint bounds per model.
- Dashboard improvements: side-by-side wizard
  callouts, fixed headline from "LLM Providers"
  to "LLM Integration".
- Task execution UI: collapsible prompt details, improved result display.
- Enhanced model discovery: better Anthropic, Google, DeepSeek,
  Mistral support.
- TER publish workflow.
- Documentation: wizards guide with screenshots, tasks section,
  updated configuration reference.

Changed
-------

- Renamed ``SafeCastTrait`` extracted from duplicated helpers
  in ``TaskController`` and ``WizardGeneratorService``.
- SQL injection defense: regex whitelist validation for
  table/column names in ``FetchRecordsRequest``
  and ``LoadRecordDataRequest``.

Fixed
-----

- Restored ``method_exists()`` guards for
  ``setShortcutContext()`` (TYPO3 v13 compatibility).
- PHPUnit 12: replaced ``createStub`` with ``createMock``
  to fix deprecation warnings.

.. _version-0-4-8:

Version 0.4.8 (2026-03-07)
==========================

Changed
-------

- Rewritten introduction with value-oriented positioning.
- Restructured README around value proposition and audience segments.
- Updated package metadata with value-oriented descriptions.
- Added integration guide for extension developers.

.. _version-0-4-7:

Version 0.4.7 (2026-03-07)
==========================

Added
-----

- Help page in the LLM backend module.
- Setup wizard links on empty-state list pages.

Fixed
-----

- Use canonical endpoint URLs for known providers in setup wizard.
- Remove container class from backend module templates.

.. _version-0-4-6:

Version 0.4.6 (2026-03-06)
==========================

Fixed
-----

- Add Fluid-compatible ``getHasApiKey()`` getter for
  ``{provider.hasApiKey}`` in templates.

.. _version-0-4-5:

Version 0.4.5 (2026-03-06)
==========================

Fixed
-----

- Use ``GET /v1/models`` for Anthropic connection test.

.. _version-0-4-4:

Version 0.4.4 (2026-03-06)
==========================

Fixed
-----

- Use table-specific connection and simplify column checks.
- Wrap test cleanup in try/finally and assert labelField.

.. _version-0-4-3:

Version 0.4.3 (2026-03-06)
==========================

Fixed
-----

- Handle tables without ``uid`` column in TCA utilities.
- Remove hardcoded temperature from chat completions.

.. _version-0-4-2:

Version 0.4.2 (2026-03-06)
==========================

Fixed
-----

- Add ``rootLevel`` to provider, configuration, and model TCA definitions.

.. _version-0-4-1:

Version 0.4.1 (2026-03-06)
==========================

Fixed
-----

- Use ``max_completion_tokens`` instead of ``max_tokens``
  for OpenAI chat completions.

.. _version-0-4-0:

Version 0.4.0 (2026-03-06)
==========================

Breaking
--------

- Prevent plaintext API key storage via setup wizard;
  keys now require vault encryption.

Fixed
-----

- Cast ExtensionConfiguration timeout values to integer.

Changed
-------

- Use Symfony ``Uuid::v7()`` instead of manual UUID generation.

.. _version-0-3-2:

Version 0.3.2 (2026-03-04)
==========================

Added
-----

- Extract thinking blocks from LLM responses (``<think>`` tag support).

Fixed
-----

- Preserve newlines in ``extractThinkingBlocks``.
- Restrict CI push trigger to main branch only.
- Add ``merge_group`` trigger to CI workflow.

.. _version-0-3-1:

Version 0.3.1 (2026-03-02)
==========================

Fixed
-----

- Add Overview submodule for TYPO3 v13 module overview compatibility.

.. _version-0-3-0:

Version 0.3.0 (2026-03-01)
==========================

Added
-----

- Expose ``chatWithConfiguration`` and
  ``streamChatWithConfiguration`` on
  ``LlmServiceManagerInterface``.

Fixed
-----

- Use integer values for ``f:be.infobox`` state attribute
  for TYPO3 v13 compatibility.
- Explicitly enable fuzz and mutation tests.

.. _version-0-2-2:

Version 0.2.2 (2026-03-01)
==========================

Fixed
-----

- Use ``tools`` parent for TYPO3 v13 module compatibility.

Changed
-------

- Consolidate caller workflows into 4 grouped files.
- Fix documentation issues found by analysis.

.. _version-0-2-1:

Version 0.2.1 (2026-02-28)
==========================

Changed
-------

- Require ``netresearch/nr-vault`` ^0.4.0 for API key encryption.

.. _version-0-2-0:

Version 0.2.0 (2026-02-28)
==========================

Added
-----

- PHP 8.2+ and TYPO3 v13.4+ compatibility.
- TYPO3 v13.4 ddev install command.
- Coverage uploads and fuzz/mutation CI workflow.
- Unit tests for enums, WizardResult DTO, providers,
  services, and specialized classes.
- Coverage tests for PromptTemplateService and TranslationService.

Changed
-------

- Moved ``phpunit.xml`` and ``phpstan-baseline.neon`` into ``Build/`` directory.
- Expanded CI matrix to PHP 8.2-8.5 and TYPO3 v13.4/v14.
- Replaced TYPO3 v14-only APIs with v13-compatible equivalents.
- Narrowed testing-framework to ^9.0 for PHPUnit 12 compatibility.
- Removed dead ProviderRegistry class and orphaned phpstan baseline file.
- Removed 55 dead translation keys.
- Harmonized composer script naming to ``ci:test:php:*`` convention.
- Migrated CI to centralized workflows.
- Added SPDX copyright and license headers.
- Replaced generic emails with GitHub references.

Fixed
-----

- Resolved CI failures for PHP 8.2 and TYPO3 v13 compatibility.
- Resolved PHPStan failures for dual TYPO3 v13/v14 support.
- Fixed PHPUnit deprecation warnings.
- Used ``CoversNothing`` for excluded exception and enum test classes.
- Localized user-facing hardcoded strings in controllers.
- Disabled functional tests in CI (environment-specific).
- Fixed direct ``php-cs-fixer`` call in ``ci:test:php:cgl`` script.

.. _version-0-1-2:

Version 0.1.2 (2026-01-11)
==========================

Fixed
-----

- Fixed CI: use correct org secret name for TER token.
- Simplified TER upload workflow.

.. _version-0-1-1:

Version 0.1.1 (2026-01-11)
==========================

Fixed
-----

- Fixed CI: create zip archive for TER upload.

.. _version-0-1-0:

Version 0.1.0 (2026-01-11)
==========================

Initial release of the TYPO3 LLM Extension.

Added
-----

**Core Features**

- Multi-provider support (OpenAI, Anthropic Claude,
  Google Gemini, Ollama, OpenRouter, Mistral, Groq).
- Unified API via :php:`LlmServiceManager`.
- Provider abstraction layer with capability interfaces.
- Typed response objects (:php:`CompletionResponse`, :php:`EmbeddingResponse`).
- Three-tier configuration architecture (Providers, Models, Configurations).
- Encrypted API key storage using sodium_crypto_secretbox.

**Feature Services**

- :php:`CompletionService`: Text completion with format
  control (JSON, Markdown).
- :php:`EmbeddingService`: Vector generation with caching
  and similarity calculations.
- :php:`VisionService`: Image analysis with alt-text,
  title, description generation.
- :php:`TranslationService`: Translation with formality
  control and glossary support.
- :php:`PromptTemplateService`: Centralized prompt
  management with database-driven templates.

**Specialized Services**

- Image generation (DALL-E).
- Text-to-speech (TTS) and speech transcription (Whisper).
- DeepL translation integration.

**Provider Capabilities**

- Chat completions across all providers.
- Embeddings (OpenAI, Gemini).
- Vision/image analysis (all providers).
- Streaming responses (all providers).
- Tool/function calling (all providers).

**Infrastructure**

- TYPO3 caching framework integration.
- Backend module for provider management and testing.
- Prompt template management with versioning and performance tracking.
- Comprehensive exception hierarchy.
- Type-safe enums and DTOs for domain constants.

**Developer Experience**

- Option objects with factory presets (:php:`ChatOptions`).
- Full backwards compatibility with array options.
- Extensive PHPDoc documentation.
- Type-safe method signatures.

**Security**

- Enterprise readiness security workflows and supply chain controls.
- SLSA Level 3 provenance, Cosign signatures, and SBOM generation.
- OpenSSF Scorecard and Best Practices compliance.

**Testing**

- Comprehensive unit and integration tests.
- E2E testing with Playwright.
- Property-based (fuzz) testing support.

.. _upgrade-guides:

Upgrade Guides
==============

Upgrading a pre-1.0 installation
--------------------------------

Do not remove and reinstall the package as a normal upgrade. Back up the
installation, review the releases between the installed and target version,
and update its Composer constraint deliberately. See
:ref:`installation-upgrading-previous` for package setup, schema checks and
upgrade wizards. Review configuration and integrations before reopening
editor access.

The current package line is 0.43. For that line, for example:

.. code-block:: bash
   :caption: Select the 0.43 release line

   composer require netresearch/nr-llm:^0.43 --with-all-dependencies

This does not select a future 0.44 release. Choose its constraint explicitly
when following that release's migration notes.

.. _breaking-changes:

Breaking changes policy
=======================

During 0.x, minor releases may contain incompatible changes. Read the
breaking-change and migration notes for every crossed minor line. Patch
releases are intended for compatible fixes; the public ``@api`` contract is
narrower than every class present in the package.

The notice period that starts at 1.0 and the treatment of consumer interfaces
are described in :ref:`api-stability` and :ref:`api-deprecation`. Those pages
are the policy reference; this release overview adds no broader guarantee.

.. _deprecation-policy:

Deprecation policy
==================

See :ref:`api-deprecation` for the current notice period, replacement inventory
and the distinction between 0.x and the policy from 1.0.

.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-settings:

========
Settings
========

.. _configuration-typoscript:
.. _configuration-providers:

Provider configuration
======================

Providers, models and configurations are **database-backed** and managed
in the LLM backend module — not via TypoScript. nr-llm does **not** read
``plugin.tx_nrllm`` TypoScript settings; any such constants/setup have no
effect (this is true for both classic TypoScript templates and site sets).

To make the generic ``chat()`` / ``complete()`` entry points work without
pinning a provider per call, set up a default configuration:

#. Open the **LLM** backend module.
#. Create a **Provider** (e.g. OpenAI) and store its API key as an
   nr-vault identifier — see :ref:`configuration-security-api-keys`.
#. Create a **Model** for that provider.
#. Create a **Configuration** bundling the model, then mark it
   **active** and **default**.

The :guilabel:`Setup Wizard` in the module walks through these steps.

Without an active default configuration, generic calls throw
*"No provider specified and no default provider configured"*.

.. _configuration-quality-benchmark:

Quality benchmark for routing
=============================

Measured quality uses one explicitly selected completion benchmark
(:ref:`developer-quality-evaluation-completion-revision`). These extension
settings are read on every score lookup, including in long-running workers.

.. confval:: routing.qualitySet
   :name: ext-nr-llm-routing-quality-set
   :type: string
   :default: empty

   Exact golden prompt set identifier to use for measured quality. Choose
   a benchmark relevant to the application and evaluate its candidates.

.. confval:: routing.qualityFingerprint
   :name: ext-nr-llm-routing-quality-fingerprint
   :type: string
   :default: empty

   Copy the ``Benchmark`` value printed by ``nrllm:eval:run`` for that set.
   Only results for this exact revision with a verified serving generator
   and deterministic grading can supply a score. Empty or invalid settings,
   or no matching measurement, mean unknown quality. A measured zero remains
   zero. Other routing signals remain available.

After upgrading from an unversioned benchmark, re-evaluate and configure both
values. Changing prompts or grading rules produces a new fingerprint; switch
the selected revision deliberately after reviewing the measurements.

.. _configuration-agent-rounds:

Model rounds of an agent run
============================

An agent run sends the model a request, runs the tools it asks for and sends
the result back; each such exchange is a round. The settings are in the extension
configuration of ``nr_llm``, section *agent* (:ref:`ADR-101 <adr-101>`). The
:guilabel:`Governance` tab of the :guilabel:`LLM` overview module shows the values
that apply.

.. confval:: agent.defaultMaxIterations
   :name: ext-nr-llm-agent-default-max-iterations
   :type: integer
   :default: 5

   The rounds a run gets when its caller asks for no number, for example an
   ordinary backend chat turn or an editor feature's tool call. A value above
   ``agent.maxIterations`` is that
   maximum. An empty, non-numeric, zero or negative value falls back to 5.

.. confval:: agent.maxIterations
   :name: ext-nr-llm-agent-max-iterations
   :type: integer
   :default: 20

   The most rounds any run may take, whatever its caller asks for. Every round
   is a model request, so this bounds the cost of one run. A run that resumes
   after an approval or an answered question is limited by the value in force
   when it resumes, and its count starts again for that part. An empty,
   non-numeric, zero or negative value falls back to 20.

A run that reaches its number ends with one more request without tools, which
writes the answer. The values are read for every run, so a change applies to the
next one. A worker that started before the change applies it from its next
start.

The :guilabel:`Agent Runs` module and the playground continue a run after an
approval or an answer inside the web request. Each round can take several
seconds, so with a high maximum such a request can outlast a timeout of the web
server or a proxy in front of it. If PHP keeps running, completion may later
appear in :guilabel:`Agent Runs`. A PHP-worker timeout, process termination or
host failure can instead leave an executing segment unfinished. Inspect the
run status and worker lease; use the reaper procedure rather than assuming
completion. See :ref:`administration-commands`.

.. _configuration-worker:

Background worker
=================

A queued run reads its provider key in a process without a logged-in backend
user (:ref:`ADR-234 <adr-234>`). The setting is in the extension configuration of
``nr_llm``, section *worker*.

.. confval:: worker.technicalBeUserUid
   :name: ext-nr-llm-worker-technical-be-user-uid
   :type: integer
   :default: 0

   The uid of the backend user whose grants on a vault secret count when a queued
   run reads a provider key on the command line. ``nrllm:agent:work``, a Messenger
   consumer and the reaper all do. The user needs read access to the secret, by
   ownership or through a group, and nothing else.

   ``0`` applies the nr_vault CLI rule, which refuses unless ``allowCliAccess`` is
   on. Every run then fails on its first model call. A user who is disabled,
   deleted or unknown fails the run. A web request keeps its logged-in user.

   Everything else a run does in nr_vault happens as this user too. A delegated MCP
   tool stores a credential for the run's actor, which this user cannot do without
   the rights of that actor, so a queued run with such a tool fails.

.. _configuration-field-fill:

Filling empty fields on save
============================

An installation can have empty fields written by AI after a save
(:ref:`ADR-237 <adr-237>`). The settings are in the extension configuration of
``nr_llm``, section *fieldFill*. Nothing happens until ``fieldFill.fields`` is set.

.. confval:: fieldFill.fields
   :name: ext-nr-llm-field-fill-fields
   :type: string
   :default: (empty)

   Comma-separated ``table.field`` entries, for example
   ``pages.description,pages.abstract``. Only plain text fields count: a rich text
   field, a code editor, a slug or a relation stays out, and so do accounts and
   ``sys_`` tables.

.. confval:: fieldFill.maxLength
   :name: ext-nr-llm-field-fill-max-length
   :type: integer
   :default: 300

   The longest text written, in characters. The TCA ``max`` of the field applies
   when it is lower. The model is asked for a tenth less, and a longer answer is
   not written.

When an editor changes and saves a record with such a field empty, a background
run is queued and the save ends. A save that changes nothing queues nothing. A worker has to run (:ref:`administration-agent-runs-background`). It writes
the text as the user who saved, and only while the field is still empty. A failed
run writes nothing, and :guilabel:`Agent Runs` shows what became of each answer.

A save in a draft workspace is filled in that workspace: the run reads the
record and its page as the workspace shows them and stores the text as a draft
there, which waits for the workspace to be published. Live stays unchanged,
wherever the editor works when the worker runs. A table without workspace
versions is not filled from a draft (:ref:`ADR-265 <adr-265>`).

Nothing is queued for a field the save itself emptied, for a page with hardly any
text, for ``_cli_`` or an import, for a copy or a translation made by TYPO3, or
for a user who may not use the default configuration. The record's text is sent
to the provider of the default configuration.

.. _configuration-vision:

Vision editor actions
=====================

The editor actions :guilabel:`Set alternative text`,
:guilabel:`Update file metadata` and :guilabel:`Set categories from the image`
send the image of the file to the model (:ref:`ADR-235 <adr-235>`). The settings are in the extension configuration of
``nr_llm``, section *vision*.

.. confval:: vision.maxImageEdge
   :name: vision-maxImageEdge
   :type: integer
   :default: 1024

   The longest edge in pixels of the image sent to the model. TYPO3's file
   processing scales it and keeps the result in the processed folder. An image
   that cannot be brought to this size is not sent, and the action is not
   available for it. Core remembers a failed attempt: an image tried while the
   image processor was broken stays unavailable until the processed files are
   cleared in :guilabel:`Admin Tools > Maintenance`.

.. confval:: vision.excludedFolders
   :name: vision-excludedFolders
   :type: string
   :default: (empty)

   Comma-separated combined folder identifiers, for example
   ``1:/private/, 1:/hr/``. No image from these folders or their subfolders
   reaches a model.

.. confval:: vision.<field>.enabled
   :name: vision-field-enabled
   :type: boolean
   :default: 1

   For ``alternative``, ``title``, ``description`` and ``categories``. Whether
   the editor action fills this field. An action with no field left is not offered. This does not
   switch off the tool: the assistant may still use it, which the
   :guilabel:`Tools` module governs.

.. confval:: vision.<field>.maxLength
   :name: vision-field-maxLength
   :type: integer
   :default: 0

   For ``alternative``, ``title`` and ``description``; ``categories`` has no
   length. The longest value in characters. It applies to every writer of the
   field, the assistant included, and it cannot lift a narrower TCA ``max``.
   The model is asked for a tenth less, and a longer value is refused on the
   approval card. ``0`` sets no limit of its own.

.. confval:: vision.<field>.prompt
   :name: vision-field-prompt
   :type: string
   :default: (empty)

   What the model is told to write for this field. Empty uses the shipped text.
   For ``categories`` the list of categories it may choose from is always
   added.

.. confval:: vision.categories.rootUid
   :name: vision-categories-rootUid
   :type: integer
   :default: 0

   The ``sys_category`` uid below which categories may be set on a file from
   its image (:ref:`ADR-268 <adr-268>`). The model chooses only from the live,
   visible, default-language categories below it, and only from those inside
   the editor's category mounts where the editor has any; it never creates
   one, and the root itself is not offered. ``0`` switches the
   ``categories`` field off: :guilabel:`Set categories from the image` is not
   offered, and the tool ``set_file_categories`` refuses every call. At most
   ten levels and 200 categories are read, because the list goes into every
   prompt. There is no TSconfig form of this setting: a file has no page to
   read it from.

The action is not offered when the default configuration's model declares
capabilities without ``vision``. A model with an empty capability field passes. A
configuration that selects its model by criteria has to require ``vision`` in its
criteria.
The prompt asks for the language of the backend user.

.. _configuration-environment:

Environment variables
=====================

.. code-block:: bash
   :caption: .env

   # TYPO3 encryption key (used for API key encryption)
   TYPO3_CONF_VARS__SYS__encryptionKey=your-key

.. _configuration-security:

Security
========

.. _configuration-security-api-keys:

API key protection
------------------

1. **Encrypted storage** — API keys are stored as
   vault identifiers (UUIDs) via the
   `nr-vault <https://github.com/netresearch/t3x-nr-vault>`__
   extension, which uses envelope encryption.
   nr-llm never stores raw API keys.
2. **Database security** — the database only contains
   vault UUIDs, not secrets. Ensure backups are
   encrypted regardless.
3. **Backend access** — restrict the LLM module to
   authorized administrators.
4. **Key rotation** — re-encrypt via nr-vault's
   key rotation mechanism.

.. _configuration-security-input:

Input sanitization
------------------

Validate the input your application accepts. Removing markup is optional
text normalization, not authorization or a prompt-injection defence.
``trim(strip_tags())`` does not remove embedded control characters.

.. code-block:: php
   :caption: Reject control bytes and use a resolved configuration

   if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $userInput)) {
       throw new \InvalidArgumentException('Unsupported control character.');
   }
   $text = trim(strip_tags($userInput));
   $response = $llmManager->completeForConfiguration($text, $configuration);

Resolve ``$configuration`` with the guarded service in
:ref:`integration-guide-step5`. Apply length and format limits appropriate to
the application, keep input data separate from trusted instructions, and
check permissions before any record read or write.

.. _configuration-security-output:

Output handling
---------------

Treat LLM responses as untrusted content:

.. code-block:: php
   :caption: Example: Escaping output

   $response = $llmManager->completeForConfiguration($prompt, $configuration);

   $safeOutput = htmlspecialchars(
       $response->content, ENT_QUOTES, 'UTF-8'
   );

.. _configuration-logging:

Logging
=======

.. code-block:: php
   :caption: config/system/additional.php

   use Psr\Log\LogLevel;
   use TYPO3\CMS\Core\Log\Writer\FileWriter;

   $GLOBALS['TYPO3_CONF_VARS']['LOG']
       ['Netresearch']['NrLlm'] = [
       'writerConfiguration' => [
           LogLevel::DEBUG => [
               FileWriter::class => [
                   'logFileInfix' => 'nr_llm',
               ],
           ],
       ],
   ];

Log files: :file:`var/log/typo3_nr_llm_*.log`

.. _configuration-caching:

Caching
=======

The extension uses TYPO3's caching framework with
cache identifier ``nrllm_responses``.

**No cache backend is specified** — TYPO3 automatically
uses the instance's default cache backend. If your
instance has Redis, Valkey, or Memcached configured,
nr-llm uses it transparently with zero configuration.

- **Cache identifier**: ``nrllm_responses``
- **Cache group**: ``nrllm``
The backend's ``defaultLifetime`` (3600 seconds) does not enable every cache
path. Each producer decides whether to store content and with which TTL:

.. list-table:: Response-cache paths
   :header-rows: 1

   * - Path
     - Activation and TTL
     - Stored content and removal
   * - Embeddings
     - 86400 seconds by default; ``EmbeddingOptions::noCache()`` disables it.
     - Vectors; expire by TTL or the ``nrllm`` cache-group flush.
   * - Plain configuration chat/completion
     - ``answer_cache_ttl`` defaults to 0 (off), up to 86400 seconds.
     - Verbatim complete answer; requires effective ``full`` privacy. Flush
       ``nrllm_answers`` or the cache group. ``withoutAnswerCache()`` opts out.
   * - Specialized translation cache
     - ``TranslationOptions::withCacheTtl()`` opts in; off by default.
     - Translator result; glossary/configuration/skill changes flush its tag.
   * - Agent/tool/schema/streaming answer paths
     - Excluded from configuration answer caching.
     - No cached answer through that path.

A cache hit avoids the corresponding provider request and its budget charge.
Idempotency is a separate retry contract; it is not this response-cache TTL.
The privacy purge removes governed database rows; it does not replace cache
expiry or an explicit cache flush. See :ref:`configuration-parameters`.

To override the backend for this cache specifically:

.. code-block:: php
   :caption: config/system/additional.php

   use TYPO3\CMS\Core\Cache\Backend\RedisBackend;

   $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']
       ['cacheConfigurations']['nrllm_responses']
       ['backend'] = RedisBackend::class;

Clear cache:

.. code-block:: bash

   vendor/bin/typo3 cache:flush --group=nrllm

.. include:: /Includes.rst.txt

.. _adr-027:

==========================================
ADR-027: Split TaskController
==========================================

:Status: Accepted
:Date: 2026-04
:Authors: Netresearch DTT GmbH

.. _adr-027-context:

Context
=======

:php:`Classes/Controller/Backend/TaskController.php` has grown to **920
lines** carrying eleven public actions, nine private helpers, and three
distinct user-facing pathways:

* **List / catalog** — :code:`listAction()`.
* **AI wizard** (create a Task from a natural-language description) —
  :code:`wizardFormAction()`, :code:`wizardGenerateAction()`,
  :code:`wizardGenerateChainAction()`, :code:`wizardCreateAction()`.
* **Execution** (run a stored Task with various input sources) —
  :code:`executeFormAction()`, :code:`executeAction()`,
  :code:`refreshInputAction()`.
* **Record picking** (browse DB tables to source Task input from a
  record) — :code:`listTablesAction()`, :code:`fetchRecordsAction()`,
  :code:`loadRecordDataAction()`.

The 2026-04 architecture audit — generated locally and kept under the
gitignored ``claudedocs/`` directory rather than checked in (the
codebase intentionally excludes Claude Code working notes from version
control via ``.gitignore``) — flagged three concrete problems with the
controller as it stands:

1. **Inline SQL.** Eight call sites use :php:`ConnectionPool` /
   :php:`QueryBuilder` directly to query :code:`sys_log`, the picked
   record's table, and so on. Repository layer is bypassed.
2. **Inconsistent response shape.** Most backend controllers return
   typed :php:`Response/*` DTOs (``ToggleActiveResponse``,
   ``TestConfigurationResponse``, etc.) — see ADR-024 widget pattern
   and the ``ConfigurationController`` precedent. ``TaskController``'s
   AJAX actions instead return raw :php:`new JsonResponse(['success'
   => …, 'error' => …])` literals at sixteen call sites.
3. **God-class scope.** Three independent user pathways (catalog,
   wizard, execution + record picking) sharing one class makes
   navigation, testability, and per-feature ownership harder than it
   needs to be.

Adding any of the planned follow-ups — pre-flight budget gating in the
execute flow (REC #4), a typed exception layer for execute errors
(REC #8), domain-JSON-to-DTO promotion for ``Task::getInputConfig()``
(REC #6) — would each make this class even larger.

The audit explicitly noted that REC #5 should ship behind an ADR
because the change touches backend module routing, the AJAX URL surface
JavaScript depends on, and the boundary between controllers and the
service layer.

.. _adr-027-decision:

Decision
========

We will adopt a **hybrid split**: per-pathway controllers + service
extraction + uniform typed responses. Concretely:

Per-pathway controllers
-----------------------

The eleven public actions move into four focused controllers, each
sharing the same dependency-injection patterns we already use for
:php:`ConfigurationController` / :php:`ProviderController` /
:php:`ModelController`:

* :php:`Controller/Backend/TaskListController` — :code:`listAction`
  only.
* :php:`Controller/Backend/TaskWizardController` — the four wizard
  actions.
* :php:`Controller/Backend/TaskExecutionController` —
  :code:`executeFormAction`, :code:`executeAction`,
  :code:`refreshInputAction`.
* :php:`Controller/Backend/TaskRecordsController` —
  :code:`listTablesAction`, :code:`fetchRecordsAction`,
  :code:`loadRecordDataAction`.

Each controller is :php:`#[AsController]` and remains thin: parse the
request DTO, delegate to a service, return a typed response.

Service extraction
------------------

Two new application services capture the logic the controllers
currently embed:

* :php:`Service/Task/TaskInputResolverInterface` (with
  :php:`TaskInputResolver` :code:`final readonly` impl) — owns the
  four "where does the input text come from" branches that today
  live as :code:`getInputData()`, :code:`getSyslogData()`,
  :code:`getDeprecationLogData()`, :code:`getTableData()` private
  helpers. Each branch becomes an injectable strategy (or a
  :code:`match` over a typed source enum, depending on shape after
  closer inspection).
* :php:`Service/Task/TaskExecutionServiceInterface` (with
  :php:`TaskExecutionService` impl) — coordinates: resolve input via
  ``TaskInputResolver``, render the prompt template via the existing
  ``PromptTemplateService``, dispatch to ``LlmServiceManager``, return
  a typed result DTO. This is also the hook for the future REC #4
  budget pre-flight.

Repository layer
----------------

Inline SQL moves to repository methods on **two** repositories:

* :php:`Domain/Repository/TaskRepository` gains
  :code:`fetchSampleRecords(string $table, ...)` and
  :code:`loadRecordRow(string $table, int $uid)` for the picker
  controller.
* The :code:`sys_log` and deprecation-log reads (which are
  TYPO3-internal, not Task-domain) move into a small
  :php:`Service/Task/TaskInputResolver` collaborator that wraps the
  appropriate :php:`ConnectionPool` /
  :php:`Filesystem` calls in named methods, then is exposed via an
  interface so tests can stub it.

Typed response normalization
----------------------------

Every AJAX action returns a typed :php:`Response/*` DTO. Five new ones
are introduced where no existing match is good enough:

* :code:`Response/TableListResponse` (record picker — table dropdown).
* :code:`Response/RecordListResponse` (record picker — row results).
* :code:`Response/RecordDataResponse` (record picker — single row
  payload).
* :code:`Response/TaskExecutionResponse` (execute success).
* :code:`Response/TaskInputResponse` (refresh-input result).

Existing :code:`ErrorResponse` covers every error branch; raw
:php:`new JsonResponse(['success' => false, ...])` calls go away.

.. _adr-027-rollout:

Rollout plan
============

The split lands as a sequence of slices, each its own PR, each
independently revertible. A single mega-PR would block on every
review iteration; small slices keep each step reviewable.

Sequence
--------

#. **Slice 13a** — extract repository methods. ``TaskRepository``
   gains the new methods; ``TaskController`` gets refactored to call
   them but keeps every route. Pure SQL move; no behaviour change.
#. **Slice 13b** — extract :php:`TaskInputResolverInterface` +
   implementation. ``TaskController`` private helpers become
   service calls. No behaviour change.
#. **Slice 13c** — extract :php:`TaskExecutionService`. Controller
   delegates execute orchestration to the service; this is also
   where the future REC #4 budget pre-flight will hook in (see
   ADR-025 / ADR-026).
#. **Slice 13d** — introduce typed responses; convert every
   ``JsonResponse(['success' => …])`` site.
#. **Slice 13e** — split the controller in two passes:

   #. Register the four new controllers (each with the
      :code:`#[AsController]` attribute) and repoint every entry in
      ``Configuration/Backend/AjaxRoutes.php`` and
      ``Configuration/Backend/Modules.php`` from
      :php:`TaskController::actionXxx` to the matching action on the
      new per-pathway controller. ``TaskController`` itself remains
      in the tree at this point, but no production code references
      it any more — every route resolves to a new controller.
   #. In a follow-up commit (or follow-up PR if review surface gets
      large), delete ``TaskController.php`` along with any test
      doubles still referencing it. This pass is mechanical: drop
      the file, drop test imports, run the test suite.

   Sequencing matters. Routes must move *before* the file is
   deleted, otherwise the container compile would fail at the
   intermediate step.

Each slice maintains AJAX URL stability. JavaScript ``ajaxUrls``
constants registered via :code:`PageRenderer::addInlineSettingArray()`
keep their existing names; only the route's :code:`target` field
changes.

Backwards compatibility
-----------------------

* The four existing AJAX routes
  (:code:`ajax_nrllm_task_execute`, :code:`ajax_nrllm_task_list_tables`,
  :code:`ajax_nrllm_task_fetch_records`,
  :code:`ajax_nrllm_task_load_record`) keep their identifiers and
  paths. Frontend code that resolves them via the inline-settings
  mechanism is unaffected.
* The backend module entry under
  ``Configuration/Backend/Modules.php`` keeps its current
  identifier; the controller :code:`target` value updates from
  ``TaskController::listAction`` to
  ``TaskListController::listAction``.
* No public API change: ``TaskController`` is annotated
  :code:`#[AsController]` and is not part of any documented
  extension point.

.. _adr-027-consequences:

Consequences
============

Positive
--------

* Each pathway becomes navigable in isolation. PR scope on Task-area
  changes shrinks accordingly.
* The repository layer regains its position as the single source of
  Task-domain DB access. Future schema changes touch one file.
* The audit's "DTO/VO vs arrays" axis (currently 8/10 after slice 7)
  closes the last open gap on the controller layer: every backend
  AJAX endpoint then ships a typed response.
* :php:`TaskExecutionServiceInterface` becomes the natural seam for
  REC #4 (auto budget + usage in feature services). Without this
  service, REC #4 would have had to inject ``BudgetService`` directly
  into the controller — a smell.
* Each new controller has < 250 LOC, so PHPMD/PHPStan complexity
  metrics improve uniformly.

Negative / costs
----------------

* Five PRs of churn touching ~25 files. CI matrix runs each, the
  review backlog scales accordingly.
* Backend module config (``Configuration/Backend/Modules.php``) and
  AJAX routes (``Configuration/Backend/AjaxRoutes.php``) need to
  point at the new controllers; any extension that programmatically
  resolves :code:`TaskController` by class name (none in this repo,
  but possible downstream) breaks.
* Functional + E2E tests that reference ``TaskController::class``
  need updating (counted: 6 functional, 2 E2E). Each gets a
  one-line change per slice that touches the relevant action.

Alternatives considered
-----------------------

A. **Smallest-delta** — keep ``TaskController`` whole, only do
   service + repository extraction, don't split into per-pathway
   classes. Hits the audit's SQL and DTO sub-points but leaves the
   god-class shape. Rejected: doesn't solve "navigation" problem.
B. **Split-only** — split into four controllers but leave SQL
   inline and DTO usage inconsistent. Rejected: the SQL and DTO
   problems are the audit's *specific* findings; a split that
   doesn't address them is rearranging deck chairs.
C. **One mega-PR** — perform every extraction in a single change.
   Rejected: review surface too large; per-slice revertability
   gone; bisect harder.

References
==========

* Audit: ``claudedocs/audit-2026-04-23-architecture.md`` § REC #5
  (kept locally under the gitignored ``claudedocs/`` directory; not
  part of the published documentation tree).
* Existing controller patterns: :php:`ConfigurationController`,
  :php:`ProviderController`, :php:`ModelController`.
* :ref:`ADR-024 <adr-024>` (Dashboard Widgets) — typed-response
  precedent.
* :ref:`ADR-026 <adr-026>` (Provider Middleware Pipeline) — the
  natural integration point for REC #4 once
  ``TaskExecutionService`` exists.

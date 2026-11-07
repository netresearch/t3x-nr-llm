.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-037:

=================================
ADR-037: Backend AJAX admin guard
=================================

:Status: Accepted (amended; current contract below)
:Amended: 2026-11-07, current controller guards and editor permissions guide; 2026-11-07 by :ref:`ADR-130 <adr-130>`; 2026-11-07 by :ref:`ADR-131 <adr-131>`
:Date: 2026-06-28
:Authors: Netresearch DTT GmbH

.. _adr-037-current-contract:

.. note::

    Current contract (reviewed 2026-11-07): Administrative routes require admins; granted editor actions have dedicated
    capability and resource checks. See :ref:`administration-permissions`.

    The AI Tasks module is an editor entry point under AI, not universal
    administrative access.

    The decision text below records its original context; the linked successor
    explains the changed contract.


.. note::

   The decision below records the original administrative surface. It is not
   the current access matrix for all routes. Task execution, editor actions
   and approval surfaces have explicit capability and resource checks.
   Administrative CRUD, setup and playground routes remain admin-only. See
   :ref:`administration-permissions` for current grants and the controller's
   own guard for a specific action.

.. _adr-037-context:

Context
=======

The nrllm backend module is registered with ``access => admin``, so TYPO3's
module dispatcher only renders its controllers for backend administrators.
The module's interactive features, however, are driven by standalone AJAX
routes declared in ``Configuration/Backend/AjaxRoutes.php`` (``ajax_nrllm_*``).
These routes are dispatched by the generic backend AJAX route handler, **not**
through the module route — so the module's ``access => admin`` check never runs
for them.

The practical effect: any authenticated backend user (including a low-privilege
editor) could call these endpoints directly. The exposed surface is broad and
sensitive — provider/model/configuration state mutations (toggle-active,
set-default), provider and model *test* calls that decrypt vault-stored API
keys and reach out to upstream LLMs, task execution (which spends budget and
runs the configured prompt), reading of arbitrary TYPO3 records via the task
record picker, the tool playground's *run* (which executes the agent loop,
spending budget and invoking registered tools) and tool *toggle*, and the setup
wizard's *save* which creates providers and stores new API keys in the vault.

Only :php:`SkillSourceController` enforced an admin check, via a private
``denyNonAdmin()`` method duplicated nowhere else. Every other backend AJAX
controller was unguarded.

.. _adr-037-decision:

Decision
========

1. **One shared guard trait.** :php:`RequiresBackendAdminTrait`
   (``Classes/Controller/Backend/``) exposes a single private
   ``denyNonAdmin(): ?ResponseInterface`` that returns ``null`` for an admin
   and a ``403`` ``{"success": false, "error": "<message>"}`` JSON response
   otherwise, where ``<message>`` is the localised
   ``error.adminRequired`` label. :php:`SkillSourceController` now uses the
   trait; its identical private copy was deleted.

2. **Guard every administrative AJAX action, at the very top.** Each action listed in
   ``AjaxRoutes.php`` begins with
   ``if (($deny = $this->denyNonAdmin()) !== null) { return $deny; }`` before
   any body parse, repository read, or side effect. All AJAX actions already
   return :php:`ResponseInterface`, so the :php:`JsonResponse` is
   type-compatible. The guard covers
   :php:`LlmModuleController`, :php:`ProviderController`, :php:`ModelController`,
   :php:`ConfigurationController`, :php:`TaskRecordsController`,
   :php:`TaskExecutionController`, :php:`SetupWizardController`,
   :php:`ToolPlaygroundController`, :php:`ToolController` (the tool-management
   module split out later — :ref:`ADR-039 <adr-039>`) and the already-guarded
   :php:`SkillSourceController` on the original administrative route table.
   Later editor-facing actions use their dedicated grant checks; this list
   must not be used as the current access matrix.

3. **Non-AJAX module actions are left untouched.** Extbase module actions
   (``listAction``, ``indexAction``, ``executeFormAction``,
   ``wizardFormAction``, …) are reached through the ``access => admin`` module
   route and are already protected; adding the guard there would be redundant.

4. **The standard accessor is ``$GLOBALS['BE_USER']``.** The guard reads the
   current backend user from ``$GLOBALS['BE_USER']`` and checks
   :php:`instanceof BackendUserAuthentication` plus :php:`isAdmin()`. This is
   the conventional accessor for the authenticated backend user in this
   context — the AJAX route handler has already established the backend user
   session by the time the controller action runs, and using the global keeps
   the guard a zero-dependency trait that any controller can adopt without
   constructor changes.

.. _adr-037-consequences:

Consequences
============

- ●● Administrative backend AJAX endpoints require a backend admin; a
  non-admin receives a uniform ``403`` and no state is mutated, no vault key
  is decrypted, no upstream LLM is called, and no arbitrary record is read.
- ● A single shared trait removes the duplicated guard and makes "add the
  guard" the obvious, one-line step for any future backend AJAX action.
- ● The guard short-circuits before request-body parsing, so it is cheap and
  cannot be bypassed by malformed input.
- ◐ Tests that exercise these actions must now set up an admin
  ``$GLOBALS['BE_USER']`` (functional: ``setUpBackendUser(1)``; unit: an admin
  ``BackendUserAuthentication`` stub). This is a one-time, mechanical update to
  the existing controller test suites.
- ◐ ``$GLOBALS['BE_USER']`` is a global accessor rather than an injected
  dependency. It matches existing project usage and keeps the trait
  dependency-free, but it is global state and is set/reset explicitly in tests.
- ✕ This is an authorization (admin-only) control, **not** per-record or
  per-table access control: an admin retains full access to every endpoint,
  including reading arbitrary records through the task picker. Finer-grained
  authorization is out of scope.

See :ref:`ADR-023 <adr-023>` for backend capability permissions and
:ref:`ADR-012 <adr-012>` for API-key encryption (the keys these endpoints


would otherwise expose).

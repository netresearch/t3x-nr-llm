.. include:: /Includes.rst.txt

.. _adr-037:

==================================================================
ADR-037: Backend AJAX admin guard
==================================================================

:Status: Accepted
:Date: 2026-06-28
:Authors: Netresearch DTT GmbH

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
record picker, and the setup wizard's *save* which creates providers and stores
new API keys in the vault.

Only :php:`SkillSourceController` enforced an admin check, via a private
``denyNonAdmin()`` method duplicated nowhere else. Every other backend AJAX
controller was unguarded.

.. _adr-037-decision:

Decision
========

1. **One shared guard trait.** :php:`RequiresBackendAdminTrait`
   (``Classes/Controller/Backend/``) exposes a single private
   ``denyNonAdmin(): ?ResponseInterface`` that returns ``null`` for an admin
   and a ``403`` ``{"success": false, "error": "Forbidden"}`` JSON response
   otherwise. :php:`SkillSourceController` now uses the trait; its identical
   private copy was deleted.

2. **Guard every AJAX-routed action, at the very top.** Each action listed in
   ``AjaxRoutes.php`` begins with
   ``if (($deny = $this->denyNonAdmin()) !== null) { return $deny; }`` before
   any body parse, repository read, or side effect. All AJAX actions already
   return :php:`ResponseInterface`, so the :php:`JsonResponse` is
   type-compatible. The guard covers
   :php:`LlmModuleController`, :php:`ProviderController`, :php:`ModelController`,
   :php:`ConfigurationController`, :php:`TaskRecordsController`,
   :php:`TaskExecutionController`, :php:`SetupWizardController` and the
   already-guarded :php:`SkillSourceController` — 27 actions in total, matching
   the route table exactly.

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

- ●● Every backend AJAX endpoint now requires a backend admin; a
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

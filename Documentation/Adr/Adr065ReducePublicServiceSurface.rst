.. include:: /Includes.rst.txt

.. _adr-065:

================================================================
ADR-065: Reduce the public service surface (ADR-028 follow-up)
================================================================

:Status: Accepted
:Date: 2026-07-15
:Authors: Netresearch DTT GmbH

.. _adr-065-context:

Context
=======

ADR-028 froze the ``public: true`` overrides in
``Configuration/Services.yaml`` at 45 and concluded "no reduction in
count": every entry was said to be load-bearing because removing it
would break either downstream consumers or the extension's own
functional tests. The functional-test half of that argument rested on
one premise:

    "TYPO3 ``FunctionalTestCase::get()`` uses the Symfony container's
    ``->get()`` lookup, which only resolves public services."

That premise is wrong. The testing framework ships a fixture extension
``typo3/testing-framework`` →
``Resources/Core/Functional/Extensions/private_container`` whose
``PrivateContainerWeakRefPass`` runs at ``TYPE_BEFORE_REMOVING`` and
registers **every private service** (and private alias) into a public
service locator. ``FunctionalTestCase::get()`` falls back to that
locator:

.. code-block:: php

   public function get(string $id): mixed
   {
       if ($this->getContainer()->has($id)) {
           return $this->getContainer()->get($id);
       }
       return $this->getPrivateContainer()->get($id);   // private services
   }

So ``$this->get(SomeConcrete::class)`` resolves a **private** service in
functional and backend-E2E tests without any override. The repository in
fact already relied on this: ``WizardGeneratorService`` was private
(``public: false`` interface alias, no public concrete) yet resolved by
class name in green functional tests. ADR-028 Category 3 ("repositories
must be public for ``FunctionalTestCase::get()``") and the
class-name-resolution tail were therefore public for a reason that never
held.

.. _adr-065-decision:

Decision
========

Keep ``public: true`` **only** where it is genuinely required, and
privatise everything that was public solely for test resolution. A
service needs ``public: true`` if, and only if, it is:

A. part of the documented downstream LLM-API contract that consuming
   extensions resolve by class name or interface via
   ``$container->get()`` / a DI type hint; or
B. a supporting-service **interface alias** consumers wire against
   (the concrete class is private); or
C. a concrete-only documented surface with no interface (only
   ``PromptSnippetComposer``, ADR-031); or
D. a specialized standalone consumer API (speech / image in isolation);
   or
E. resolved **outside DI** via ``GeneralUtility::makeInstance()``, which
   only reuses the container-built, dependency-injected instance for
   public services.

Everything else — the repositories, the setup-wizard collaborators
``ModelDiscovery`` / ``ConfigurationGenerator``, the read-only
``UsageAnalyticsService``, and the concrete supporting services behind a
public interface alias — is now private (autoregistered by the
``Netresearch\NrLlm\`` namespace block). Functional and backend-E2E
tests resolve them unchanged through the private container. No test code
and no runtime behaviour changed; only container visibility did.

.. _adr-065-surface:

The reduced public surface (27)
===============================

**A. Documented downstream LLM-API contract** — 7 concrete + 7 interface
aliases = 14:

* ``Service\LlmServiceManager`` (+ ``LlmServiceManagerInterface``)
* ``Provider\ProviderAdapterRegistry``
  (+ ``ProviderAdapterRegistryInterface``)
* ``Service\Feature\CompletionService`` (+ Interface)
* ``Service\Feature\VisionService`` (+ Interface)
* ``Service\Feature\EmbeddingService`` (+ Interface)
* ``Service\Feature\TranslationService`` (+ Interface)
* ``Service\Feature\ToolCallingService`` (+ Interface, ADR-051)

**B. Supporting-service interface aliases** (concrete classes now
private) — 6:

* ``Service\CacheManagerInterface``
* ``Service\UsageTrackerServiceInterface``
* ``Service\PromptTemplateServiceInterface``
* ``Service\LlmConfigurationServiceInterface``
* ``Service\BudgetServiceInterface``
* ``Specialized\Translation\TranslatorRegistryInterface``

**C. Concrete-only documented surface** — 1:

* ``Service\Prompt\PromptSnippetComposer`` (ADR-031, no interface)

**D. Specialized standalone consumer API** — 4:

* ``Specialized\Speech\WhisperTranscriptionService``
* ``Specialized\Speech\TextToSpeechService``
* ``Specialized\Image\DallEImageService``
* ``Specialized\Image\FalImageService``

**E. Resolved outside DI via makeInstance()** — 2:

* ``Service\Tool\ToolRegistry`` — TCA ``itemsProcFunc`` in
  ``Form\Tca\ToolGroupItems`` (ADR-042).
* ``Service\SetupWizard\ProviderDetector`` — the DataHandler hook
  ``Hook\ProviderEndpointNormalizationHook``.

Total: 14 + 6 + 1 + 4 + 2 = **27** (down from 45).

.. _adr-065-privatised:

What became private
===================

Removed from the public set (autoregistered private; injected via DI,
resolved in tests via the private container):

* Repositories (8): ``LlmConfigurationRepository``,
  ``ProviderRepository``, ``ModelRepository``, ``TaskRepository``,
  ``PromptSnippetRepository``, ``UserBudgetRepository``,
  ``SkillRepository``, ``SkillSourceRepository``.
* Setup-wizard collaborators: ``ModelDiscovery`` (concrete;
  ``ModelDiscoveryInterface`` alias kept for autowiring, now private) and
  ``ConfigurationGenerator``.
* Supporting concretes behind a public interface alias:
  ``CacheManager``, ``UsageTrackerService``, ``PromptTemplateService``,
  ``LlmConfigurationService``, ``BudgetService``,
  ``Specialized\Translation\TranslatorRegistry``.
* ``Service\UsageAnalyticsService`` (read-only Analytics reporting
  service; its interface alias was already private).

.. _adr-065-consequences:

Consequences
============

* The audited public surface drops from 45 to 27. The
  ``PublicServicesPolicyTest`` count constant and this ADR are the audit
  trail; ADR-028's "no reduction" conclusion is superseded.
* Consuming extensions that resolved a **concrete** supporting service by
  class name (e.g. ``$container->get(CacheManager::class)``) must switch
  to the interface (``CacheManagerInterface``). This is a breaking change
  for those callers, acceptable pre-1.0 and consistent with the
  interface being the documented contract.
* Tests are unchanged: the private container keeps ``$this->get()`` on a
  private service working. Any future test that needs a private service
  by class name works out of the box for the same reason.
* Adding a new ``public: true`` still requires the three-part change from
  ADR-028 (service definition, ADR entry, ``EXPECTED_PUBLIC_TRUE_COUNT``
  bump) — but the bar is now "does a production, non-DI caller or a
  documented downstream consumer need it?", not "does a test resolve it?"

.. _adr-065-relation:

Relation to ADR-028
===================

ADR-028 stays as the record of the original policy and the
``public: true`` enforcement test. This ADR supersedes its **count** and
its Category 3 / tail rationale. The enforcement mechanism
(``Tests/Unit/Configuration/PublicServicesPolicyTest.php``) is retained;
only the expected total changed (45 → 27).

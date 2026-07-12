.. include:: /Includes.rst.txt

.. _adr-028:

================================================================
ADR-028: Public services policy in ``Configuration/Services.yaml``
================================================================

:Status: Accepted
:Date: 2026-04-30
:Slice: 25 (audit 2026-04-23 REC #9c)

Context
=======

The 2026-04-23 architecture audit (``claudedocs/audit-2026-04-23-architecture.md``)
flagged the count of ``public: true`` overrides in
``Configuration/Services.yaml`` (32 at the time of the audit; 37 after
intermediate slices added new typed-interface aliases) as
"excessive". The default in this extension's ``_defaults`` block is
``public: false``, so every ``public: true`` line is an explicit
override that needs justification.

REC #9c asked: "reduce ``public: true`` to only those genuinely needed."

Decision
========

The current public-service set is documented here as the **deliberate
policy**. Each public service belongs to one of four categories below,
each with a load-bearing reason. New ``public: true`` entries must fit
one of these categories or add a new one (with rationale appended to
this ADR).

A new unit test (``Tests/Unit/Configuration/PublicServicesPolicyTest.php``)
keeps the count honest going forward — when the policy adds a new
category it must also record the rationale.

Categories
----------

**1. Public LLM-API surface.** Services that downstream extensions
and host-instance integrations consume via
``$container->get(ServiceClass::class)`` or via direct DI hint in
their own services.yaml. These are the documented application
surface; they MUST be public.

* ``Service\LlmServiceManager`` (+ ``LlmServiceManagerInterface``)
* ``Service\Feature\CompletionService`` (+ Interface)
* ``Service\Feature\EmbeddingService`` (+ Interface)
* ``Service\Feature\TranslationService`` (+ Interface)
* ``Service\Feature\VisionService`` (+ Interface)
* ``Service\Feature\ToolCallingService`` (+ Interface, ADR-051)
* ``Service\Prompt\PromptSnippetComposer`` (concrete-only, ADR-031)
* ``Service\BudgetService`` (+ ``BudgetServiceInterface``)
* ``Service\CacheManager`` (+ ``CacheManagerInterface``)
* ``Service\UsageTrackerService`` (+ ``UsageTrackerServiceInterface``)
* ``Service\LlmConfigurationService`` (+ ``LlmConfigurationServiceInterface``)
* ``Service\PromptTemplateService`` (+ ``PromptTemplateServiceInterface``)
* ``Provider\ProviderAdapterRegistry`` (+ ``ProviderAdapterRegistryInterface``)
* ``Specialized\Translation\TranslatorRegistry`` (+ ``TranslatorRegistryInterface``)

**2. Specialized services with public method surfaces.** AI-domain
services that act as discrete public APIs, exposed for callers that
want them in isolation (image-only, speech-only consumers).

* ``Specialized\Speech\WhisperTranscriptionService``
* ``Specialized\Speech\TextToSpeechService``
* ``Specialized\Image\DallEImageService``
* ``Specialized\Image\FalImageService``

**3. Repositories consumed by tests through the TYPO3 testing
framework.** TYPO3 ``FunctionalTestCase::get()`` uses the Symfony
container's ``->get()`` lookup, which only resolves public services.
Repositories are exercised by functional tests that round-trip
fixtures through real Doctrine, so they must be public.

* ``Domain\Repository\LlmConfigurationRepository``
* ``Domain\Repository\ProviderRepository``
* ``Domain\Repository\ModelRepository``
* ``Domain\Repository\TaskRepository``
* ``Domain\Repository\UserBudgetRepository``
* ``Domain\Repository\SkillRepository``
* ``Domain\Repository\SkillSourceRepository``

**4. SetupWizard collaborators.** Three services that are
co-instantiated by the wizard controller's typed-DTO factories
(``DetectedProvider``, ``DiscoveredModel``,
``SuggestedConfiguration``). They are public so the wizard's
multi-step flow can re-resolve them across requests without holding
mutable state in the controller.

* ``Service\SetupWizard\ProviderDetector``
* ``Service\SetupWizard\ModelDiscovery`` (+ ``ModelDiscoveryInterface``)
* ``Service\SetupWizard\ConfigurationGenerator``

What is NOT public (intentionally)
----------------------------------

The autowiring resource block at the top of ``Services.yaml``
(``Netresearch\NrLlm\: { resource: '../Classes/*' }``) registers
every other class in the namespace as **private** by default. That
covers:

* Compiler passes (``DependencyInjection\``)
* Middleware (``Provider\Middleware\Fallback / Budget / Usage / Cache``)
* The fallback executor and its support helpers
* Setup-wizard support DTOs and resolvers
* All form / TCA / widget data-provider helpers
* Internal coercion / parsing helpers

These flow through DI constructor injection only. There is no
``$container->get()`` call site for any of them, no test fixture
requires them by class name, and there is no documented external
consumer.

Constraint and enforcement
==========================

The unit test
``Tests/Unit/Configuration/PublicServicesPolicyTest.php`` parses
``Configuration/Services.yaml`` and asserts:

* The total count of ``public: true`` keys matches the expected
  total (currently **45**).
* The ADR file exists and references both ``REC #9c`` and the
  ``public: true`` policy text.

Breakdown of the 45:

* **27** Category 1 — Public LLM API surface
  (14 concrete services + 13 interface aliases). Every Category-1
  service has a public interface alias except
  ``Service\Prompt\PromptSnippetComposer`` (ADR-031), which is
  concrete-only — consuming extensions resolve it by class name.
  The maths: 14 concrete + 13 aliases = 27.
* **4** Category 2 — Specialized services
  (Whisper, TextToSpeech, DallE, Fal).
* **8** Category 3 — Repositories
  (LlmConfiguration, Provider, Model, Task, PromptSnippet,
  UserBudget, Skill, SkillSource). ``PromptSnippetRepository`` is
  additionally the documented query surface for consuming
  extensions (ADR-031). ``SkillRepository`` and
  ``SkillSourceRepository`` (skills-ingest) are public so their
  functional tests resolve them via ``FunctionalTestCase::get()``.
* **4** Category 4 — SetupWizard
  (3 concrete: ProviderDetector, ModelDiscovery,
  ConfigurationGenerator + 1 alias: ModelDiscoveryInterface).
* **2** Class-name-resolution tail —
  ``Service\UsageAnalyticsService``, the read-only Analytics-module
  reporting service, public solely so its functional test resolves
  it via ``FunctionalTestCase::get()`` (same rationale as
  Category 3; production callers use constructor injection — its
  ``UsageAnalyticsServiceInterface`` alias stays private), and
  ``Service\Tool\ToolRegistry``, public so functional tests fetch
  registered tools by spec name (the tools themselves stay
  private, ADR-042).

The current test enforces only the **count** and the **ADR's
presence**. It does not statically validate that each individual
``public: true`` entry maps to a category line in this ADR — that
would require parsing the ADR's bullet lists. The intentional
friction is therefore: a contributor who adds a ``public: true``
line bumps the count, the test fails with a prompt to update both
this ADR and the constant. Reviewers verify the entry against the
categories during PR review.

Adding a new public service therefore requires three things in the
same PR: the service definition, this ADR amended (with the new
entry placed in the appropriate category, and the running total in
the test docblock updated), and the
``EXPECTED_PUBLIC_TRUE_COUNT`` constant bumped.

Consequences
============

* **No reduction in count.** Every current entry is justified;
  removing any of them would break either downstream consumers
  (Category 1, 2) or our own functional tests (Category 3, 4).
* **Future-proofing.** A new "I'll just make it public" PR now
  needs an explicit ADR amendment.
* **Drift detection.** The architecture test catches a silent
  ``public: true`` addition that bypasses the policy.

Alternative considered
======================

**Mass reduction** (privatize everything except Category 1).
Rejected: would break ~22 functional tests that resolve repositories
and wizard services via ``$this->get()``, and the eight functional
test files would each need a parallel ``services-test.yaml``
override. The maintenance cost outweighs the static-policy win;
auditing through this ADR + architecture test is the same outcome
without the test-infrastructure churn.

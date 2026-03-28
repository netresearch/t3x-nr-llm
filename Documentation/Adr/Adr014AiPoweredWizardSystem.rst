.. include:: /Includes.rst.txt

.. _adr-014:

=================================
ADR-014: AI-Powered Wizard System
=================================

:Status: Accepted
:Date: 2025-12
:Authors: Netresearch DTT GmbH

.. _adr-014-context:

Context
=======

Users need to configure LLM providers, models, configurations, and tasks -- a
complex multi-step process involving endpoint URLs, API keys, model selection,
system prompts, and temperature tuning. Manual CRUD via TYPO3 list module is
error-prone and intimidating for non-technical users.

.. _adr-014-problem-statement:

Problem statement
-----------------

1. **High barrier to entry:** First-time setup requires knowledge of API
   endpoints, adapter types, model capabilities, and prompt engineering.
2. **Model discovery gap:** Users don't know which models their provider offers.
3. **Configuration quality:** Hand-written system prompts are often suboptimal.
4. **Task chain complexity:** Creating a task requires a configuration, which
   requires a model, which requires a provider -- four entities in sequence.

.. _adr-014-decision:

Decision
========

Implement an AI-powered wizard system with three wizard types:

1. **Setup Wizard** -- Guided provider onboarding (connect, verify, discover,
   configure, save). Five-step flow driven by
   :file:`Resources/Public/JavaScript/Backend/SetupWizard.js`.

2. **Configuration Wizard** -- Takes a natural-language
   description and generates a structured
   :php:`LlmConfiguration` via
   :php:`WizardGeneratorService::generateConfiguration()`.

3. **Task Wizard** -- Takes a natural-language description and generates a
   complete task chain (task + configuration + model recommendation) via
   :php:`WizardGeneratorService::generateTaskWithChain()`.

Graceful fallback when no LLM is available:

.. code-block:: php
   :caption: Example: Fallback when LLM is unavailable

   // WizardGeneratorService::generateConfiguration()
   $config ??= $this->getDefaultConfiguration();
   if ($config === null) {
       return $this->fallbackConfiguration($description);
   }

Key architectural components:

- :php:`SetupWizardController` -- AJAX endpoints for
  detect, test, discover, generate, save.
- :php:`WizardGeneratorService` -- LLM-powered
  generation with JSON parsing and normalization.
- :php:`ModelDiscovery` /
  :php:`ModelDiscoveryInterface` -- Provider-specific
  model listing.
- :php:`ProviderDetector` -- Endpoint URL pattern
  matching for adapter type detection.
- :php:`ConfigurationGenerator` -- LLM-powered
  configuration preset generation.
- DTOs: :php:`DetectedProvider`,
  :php:`DiscoveredModel`,
  :php:`SuggestedConfiguration`,
  :php:`WizardResult`.

.. _adr-014-consequences:

Consequences
============
**Positive:**

- ●● Self-service onboarding without requiring LLM expertise.
- ●● AI-generated prompts are more effective than hand-crafted first attempts.
- ● Model discovery removes guesswork about available models.
- ● Fallback defaults ensure the wizard works even without a working LLM.
- ◐ Five-step flow with progress bar reduces cognitive load.

**Negative:**

- ◑ Requires one working LLM configuration to power the AI generation path.
- ◑ Generated configurations may need manual tuning for specialized use cases.
- ◑ Additional JavaScript adds bundle size.

**Net Score:** +5.5 (Strong positive)

.. _adr-014-files-changed:

Files changed
=============

**Added:**

- :file:`Classes/Controller/Backend/SetupWizardController.php`
- :file:`Classes/Service/WizardGeneratorService.php`
- :file:`Classes/Service/SetupWizard/ModelDiscovery.php`
- :file:`Classes/Service/SetupWizard/ModelDiscoveryInterface.php`
- :file:`Classes/Service/SetupWizard/ProviderDetector.php`
- :file:`Classes/Service/SetupWizard/ConfigurationGenerator.php`
- :file:`Classes/Service/SetupWizard/DTO/DetectedProvider.php`
- :file:`Classes/Service/SetupWizard/DTO/DiscoveredModel.php`
- :file:`Classes/Service/SetupWizard/DTO/SuggestedConfiguration.php`
- :file:`Classes/Service/SetupWizard/DTO/WizardResult.php`
- :file:`Resources/Public/JavaScript/Backend/SetupWizard.js`

.. include:: /Includes.rst.txt

.. _administration-configurations:

=======================
Managing configurations
=======================

Configurations define use-case-specific presets that
combine a model with a system prompt and generation
parameters. Extension developers reference
configurations by identifier in their code.

.. figure:: /Images/backend-configurations.png
   :alt: Configuration list with model assignment,
       use-case type, and parameter summary
   :class: with-border with-shadow
   :zoom: lightbox

   The configuration list showing each entry's
   linked model, use-case type, and key parameters.

.. _administration-configurations-add:

Adding a configuration manually
===============================

1. Navigate to :guilabel:`Admin Tools > LLM >
   Configurations`.
2. Click :guilabel:`Add Configuration`.
3. Fill in the required fields:

   :guilabel:`Identifier`
      Unique slug for programmatic access
      (e.g., ``blog-summarizer``).

   :guilabel:`Name`
      Display name (e.g., ``Blog Post Summarizer``).

   :guilabel:`Model`
      Select the model to use.

   :guilabel:`System Prompt`
      The system message that sets the AI's behavior
      and context.

4. Optionally adjust temperature (0.0-2.0), top_p,
   frequency/presence penalty, max tokens, and
   use-case type (``chat``, ``completion``,
   ``embedding``, ``translation``).
5. Click :guilabel:`Save`.

.. tip::

   Use the :ref:`Configuration wizard
   <administration-wizards-config>` to generate all
   fields from a plain-language description of your
   use case.

.. _administration-configurations-presets:

Importing configuration presets
===============================

Extensions consuming nr_llm can declare the
configurations they need as *presets*
(:ref:`ADR-056 <adr-056>`). When at least one
declared preset has not been imported yet, the
configuration list shows a :guilabel:`Pending
presets` panel above the records.

Each pending preset row shows the preset's name,
identifier, description, and a requirement check:

*  *Satisfiable* — an active model currently
   matches the preset's requirements; the model
   that would be used right now is named. Click
   :guilabel:`Import` to create the configuration
   record with one confirmation.
*  *Not satisfiable* — no active model matches;
   the first missing requirement is named. The
   :guilabel:`Import` button stays disabled until
   you configure a matching provider and model.

Imported records are normal criteria-mode
configurations: the model is resolved at runtime
from the providers and models you configured, and
you can edit or delete the record like any other.
The panel disappears once no presets are pending.

If a consuming extension later changes its preset
declaration, the imported configuration is flagged
with a :guilabel:`Preset changed` badge in the
list. The record is never updated automatically.

Next to the badge, :guilabel:`Review update` opens
a dialog that lists, field by field, the record's
current value against the changed declaration.
Confirming with :guilabel:`Apply update` overwrites
those record fields with the declared values and
clears the badge. Your own settings are preserved:
whether the configuration is active or the default,
its backend-group assignment, and its fallback chain
are never changed. If you switched the configuration
to fixed model selection, or the changed
requirements no longer match any active model, the
update is refused with the reason shown.

.. _administration-configurations-test:

Testing a configuration
=======================

Click :guilabel:`Test Configuration` on any row.
The test sends a short prompt to the model and shows
the response, model ID, and token usage.

.. figure:: /Images/backend-config-test.png
   :alt: Configuration test modal showing successful
       response from Qwen 3 via Ollama
   :class: with-border with-shadow
   :zoom: lightbox

   Successful configuration test with token count.

.. _administration-configurations-edit:

Editing configurations
======================

Click a configuration row to edit. Changes take
effect immediately for any extension code that
references this configuration's identifier — no
code deployment needed.

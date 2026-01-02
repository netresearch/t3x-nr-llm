.. include:: /Includes.rst.txt

.. _adr:
.. _architecture-decision-records:

==============================
Architecture Decision Records
==============================

This section documents significant architectural decisions made during the
development of the TYPO3 LLM Extension.

.. _adr-symbol-legend:

Symbol legend
=============

Each consequence in the ADRs is marked with severity symbols to indicate impact weight:

+--------+------------------+-------------+
| Symbol | Meaning          | Weight      |
+========+==================+=============+
| ●●     | Strong Positive  | +2 to +3    |
+--------+------------------+-------------+
| ●      | Medium Positive  | +1 to +2    |
+--------+------------------+-------------+
| ◐      | Light Positive   | +0.5 to +1  |
+--------+------------------+-------------+
| ✕      | Medium Negative  | -1 to -2    |
+--------+------------------+-------------+
| ✕✕     | Strong Negative  | -2 to -3    |
+--------+------------------+-------------+
| ◑      | Light Negative   | -0.5 to -1  |
+--------+------------------+-------------+

Net Score indicates the overall impact of the decision (sum of weights).

.. _adr-decision-records:

Decision records
================

.. toctree::
   :maxdepth: 1
   :glob:

   Adr001ProviderAbstractionLayer
   Adr002FeatureServicesArchitecture
   Adr003TypedResponseObjects
   Adr004Psr14EventSystem
   Adr005Typo3CachingFrameworkIntegration
   Adr006OptionObjectsVsArrays
   Adr007MultiProviderStrategy
   Adr008ErrorHandlingStrategy
   Adr009StreamingImplementation
   Adr010ToolFunctionCallingDesign
   Adr011ObjectOnlyOptionsApi
   Adr012ApiKeyEncryption
   Adr013ThreeLevelConfigurationArchitecture

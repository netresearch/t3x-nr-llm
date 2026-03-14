.. include:: /Includes.rst.txt

.. _testing:

=============
Testing guide
=============

Comprehensive testing guide for the TYPO3 LLM extension.

.. _testing-overview:

Overview
========

The extension includes a comprehensive test suite:

.. list-table::
   :header-rows: 1
   :widths: 25 15 60

   * - Test Type
     - Count
     - Purpose
   * - Unit tests
     - 2735
     - Individual class and method testing.
   * - Integration tests
     - 39
     - Service interaction and provider testing.
   * - E2E tests
     - 127
     - Full workflow testing with real APIs.
   * - Functional tests
     - 285
     - TYPO3 framework integration.
   * - Fuzzy tests
     - 79
     - Fuzzy/property-based testing.

.. toctree::
   :maxdepth: 2

   UnitTesting
   FunctionalTesting
   EndToEndTesting
   CiConfiguration

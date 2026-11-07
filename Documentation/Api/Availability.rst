.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-availability:

====================
Release availability
====================

The ``main`` manual includes unreleased changes. Use the documentation for
your installed version and check the package constraint of your extension.
The first release containing a symbol establishes its availability; later
releases can refine permissions, privacy and failure behavior.

.. list-table:: Selected public API additions
   :header-rows: 1
   :widths: 65 35

   * - Method
     - First release
   * - ``ConversationServiceInterface::startSession()``
     - 0.23.0
   * - ``AbstractOptions::withCallerSource()``
     - 0.30.0
   * - ``AgentRuntimeInterface::endProcess()``
     - 0.41.0
   * - ``AgentRuntimeInterface::defer()``
     - 0.42.0
   * - ``AbstractOptions::withSite()``
     - 0.43.4
   * - ``ChatOptions::withoutAnswerCache()``
     - 0.43.11

The public stability contract is :ref:`api-stability`. For complete changes,
consult :ref:`changelog`; a method's presence does not establish that an older
release implements every behavior described by this development manual.

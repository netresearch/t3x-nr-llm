.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-232:

================================================
ADR-232: An editor's budget status is public API
================================================

:Status: Accepted
:Date: 2026-10-28
:Authors: Netresearch DTT GmbH

.. _adr-232-context:

Context
=======

The AI Tasks module shows an editor their own usage today and this month
against the limits of their budget record. One read model produces it:
:php:`UserBudgetStatusService::forUser()` returns a :php:`UserBudgetStatus`
of two :php:`BudgetWindowStatus` objects with three :php:`BudgetMeter` each,
formatted by :php:`LocalizedNumberFormatter` in the reader's locale. All five
classes were ``@internal``.

Other extensions show the same status to the same editor. The cowriter
(``netresearch/t3-cowriter``) does it in its dialog and calls
:php:`forUser()` and :php:`LocalizedNumberFormatter::forBackendUser()`
directly. Under ``@internal`` these may change in any release, including a
patch release, and :php:`forUser()` already changed its signature between
0.41.8 and 0.41.9. A consumer that accepts every patch release of a minor
line would then break without a version conflict.

.. _adr-232-decision:

Decision
========

The five classes are ``@api`` (:ref:`ADR-127 <adr-127>`):

- :php:`Netresearch\\NrLlm\\Service\\Budget\\UserBudgetStatusService`, the
  entry point. Consumers obtain it from the DI container; its constructor is
  frozen in the snapshot but out of contract, and its parameter types
  (the repository and the usage-window reader) stay ``@internal``, as
  :ref:`api-stability` states for every service.
- :php:`Netresearch\\NrLlm\\Domain\\ValueObject\\UserBudgetStatus`,
  :php:`BudgetWindowStatus` and :php:`BudgetMeter`, the value objects
  :php:`forUser()` returns, including their ``toArray()`` shapes.
- :php:`Netresearch\\NrLlm\\Service\\Formatting\\LocalizedNumberFormatter`,
  which :php:`forUser()` takes and :php:`BudgetMeter` carries.

This is the signature-transitive closure of :php:`forUser()`: no further
``Netresearch\NrLlm`` type appears in a method or property signature of the
five. The snapshot test asserts that.

Nothing changes in behaviour or signatures. The change is additive: five
classes are added to ``Tests/Unit/Api/api-surface.txt``.

.. _adr-232-consequences:

Consequences
============

- Within a minor line the read model keeps its signatures and its
  ``toArray()`` shapes; during 0.x a break needs a minor release and a
  BREAKING changelog entry.
- :php:`UserBudgetStatusService` has no interface. A consumer that wants to
  replace it in a test builds a :php:`UserBudgetStatus` itself, which the
  value-object constructors allow.
- :php:`BudgetMeter::$formatter` and :php:`BudgetWindowStatus::fromUsage()`
  are part of the surface. ``fromUsage()`` takes the usage array in the shape
  the internal usage-window reader returns; that shape is now frozen with it.

.. _adr-232-alternatives:

Alternatives considered
=======================

- **A new facade that returns plain arrays.** Consumers would get a second
  read path next to the one the AI Tasks module uses, and the two could
  drift. Promoting the existing model keeps one.
- **Keeping the classes internal and asking consumers to pin a patch
  release.** That moves the cost to every consumer and still gives no
  promise.

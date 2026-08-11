.. include:: /Includes.rst.txt

.. _api-support-matrix:

==============
Support matrix
==============

Which TYPO3 and PHP versions nr_llm 1.x runs on, and when each line ends.

The versions
============

.. list-table:: TYPO3
   :header-rows: 1
   :widths: 22 20 29 29

   * - TYPO3
     - nr_llm 1.x
     - Regular maintenance ends
     - ELTS ends
   * - 13.4 LTS
     - supported
     - 2027-12-31
     - 2030-12-31
   * - 14.3 LTS
     - supported
     - 2029-06-30
     - 2032-06-30

TYPO3 14.0–14.2 are **not** supported. They are sprint releases, not the
LTS, and the extension requires ``^14.3``. The TER ``depends`` range in
``ext_emconf.php`` reads ``13.4.0-14.99.99`` only because that format
cannot express a gap.

.. list-table:: PHP
   :header-rows: 1
   :widths: 22 20 58

   * - PHP
     - nr_llm 1.x
     - Upstream security support ends
   * - 8.2
     - supported
     - 2026-12-31
   * - 8.3
     - supported
     - 2027-12-31
   * - 8.4
     - supported
     - 2028-12-31
   * - 8.5
     - supported
     - 2029-12-31

When a line ends
================

A version stays supported while its upstream is in **regular** maintenance.
ELTS is a paid TYPO3 Association product and nr_llm does not track it.

Dropping a version is a **minor** bump, never a patch, and it happens in the
first minor release after the upstream date above — not on the date itself.
Dropping one raises the floor in ``composer.json``, ``ext_emconf.php`` and
the CI matrix together, and this page moves in the same change.

Three further places state the range in prose and are **not** asserted
against anything: the ``README.md`` badges and its Requirements list,
:ref:`installation` and :ref:`introduction`. A floor change has to edit them
by hand — a green unit suite says nothing about them.

PHP 8.2 is the nearest edge: its upstream security support ends 2026-12-31,
so it is the next floor to rise.

Declared constraints
====================

The literals below are the ones the build actually uses. They are asserted
against ``composer.json``, ``ext_emconf.php`` and
``.github/workflows/ci.yml`` by ``Tests/Unit/VersionConsistencyTest`` — a
matrix that drifts from what CI runs fails the unit suite rather than
misleading a reader.

.. support-matrix-start

:composer typo3/cms-core: ``^13.4 || ^14.3``
:composer php: ``^8.2``
:ext_emconf typo3: ``13.4.0-14.99.99``
:ext_emconf php: ``8.2.0-8.99.99``
:ci typo3-versions: ``^13.4``, ``^14.3``
:ci php-versions: ``8.2``, ``8.3``, ``8.4``, ``8.5``

.. support-matrix-end

The CI values are the **union** across every matrix in ``ci.yml``. Single
cells there — the merge queue's reduced PHP set, the MariaDB functional leg
— are subsets by design and do not narrow what is supported.

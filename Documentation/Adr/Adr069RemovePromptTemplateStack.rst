.. include:: /Includes.rst.txt

.. _adr-069:

==================================================================
ADR-069: Remove the unusable PromptTemplate stack
==================================================================

:Status: Accepted
:Date: 2026-07-16
:Authors: Netresearch DTT GmbH

.. _adr-069-context:

Context
=======

The extension shipped a :php:`PromptTemplate` domain stack from an early
design phase: the :php:`Domain\Model\PromptTemplate` entity, its
:php:`Domain\Repository\PromptTemplateRepository`, the
:php:`Service\PromptTemplateService` (+ :php:`PromptTemplateServiceInterface`),
the :php:`Exception\PromptTemplateNotFoundException`, and the
``tx_nrllm_prompttemplate`` table in :file:`ext_tables.sql`.

The stack was never usable at runtime and had no production consumers:

- **No TCA.** :file:`Configuration/TCA/` has no
  ``tx_nrllm_prompttemplate.php`` and
  :file:`Configuration/Extbase/Persistence/Classes.php` has no
  :php:`PromptTemplate` mapping. Without either, Extbase cannot map the
  table, so every :php:`PromptTemplateRepository` method fails at
  runtime.
- **Zero consumers.** Nothing injected
  :php:`PromptTemplateServiceInterface` or the repository. The only
  ``PromptTemplate`` references elsewhere were the unrelated
  :php:`Task::getPromptTemplate()` / ``prompt_template`` **string**
  column and ADR-031's contrast with :php:`PromptSnippet`.
- **No coverage.** No functional test loaded the ``PromptTemplates.csv``
  fixture; the repository had 0% coverage from every suite. The service
  was unit-tested only against a mocked repository, so the runtime gap
  never surfaced. This was found while closing zero-coverage gaps
  (GitHub issue #399).

The stack was superseded twice: ADR-031 introduced the lightweight
:php:`PromptSnippet` library for reusable prompt fragments, and the
:php:`Task` entity (with its ``prompt_template`` string field) covers
predefined, editor-managed prompts. Adding TCA plus functional tests to
resurrect the stack would invest in a surface no code uses.

.. _adr-069-decision:

Decision
========

Remove the dormant PromptTemplate stack in full:

- the :php:`Domain\Model\PromptTemplate` entity and
  :php:`Domain\Repository\PromptTemplateRepository`;
- the :php:`Service\PromptTemplateService` and
  :php:`Service\PromptTemplateServiceInterface`, including the public
  interface alias in :file:`Configuration/Services.yaml`;
- the :php:`Exception\PromptTemplateNotFoundException` (used solely by
  the removed service);
- the ``tx_nrllm_prompttemplate`` ``CREATE TABLE`` block in
  :file:`ext_tables.sql`;
- the unit tests and the ``PromptTemplates.csv`` functional fixture that
  covered only the removed classes.

:php:`Task`'s unrelated ``prompt_template`` string field,
:php:`PromptSnippet`, and ADR-031 are untouched.

.. _adr-069-consequences:

Consequences
============

- **BREAKING (public API).** :php:`Service\PromptTemplateService` and
  :php:`Service\PromptTemplateServiceInterface` are removed from the DI
  container. Any external caller resolving them would have failed at
  runtime already (no TCA), so no working integration can break; the
  removal is nonetheless a public-surface change and is called out for
  completeness. Pre-1.0, this is acceptable.
- **Orphaned database table.** ``tx_nrllm_prompttemplate`` is no longer
  declared. TYPO3 does not drop tables automatically; on existing
  installations the table becomes orphaned. Operators remove it via the
  database analyzer (:guilabel:`Admin Tools > Maintenance > Analyze
  Database Structure`). No upgrade wizard is provided — the table held
  no data any code ever wrote.
- **Public-service count: 27 → 26.** Removing the
  :php:`PromptTemplateServiceInterface` alias drops the audited
  ``public: true`` count from 27 (ADR-065) to 26. This ADR is the new
  count authority, superseding ADR-065's count exactly as ADR-065
  superseded ADR-028's. The breakdown is now
  14 + 5 + 1 + 4 + 2 = **26**: ADR-065's Category B (supporting-service
  interface aliases) drops from 6 to 5. The
  :php:`Tests\Unit\Configuration\PublicServicesPolicyTest`
  ``EXPECTED_PUBLIC_TRUE_COUNT`` constant and this ADR are the audit
  trail. ADR-065 remains the record of the 45 → 27 reduction.
- **One prompt surface, not two.** ADR-031's "two prompt-related
  entities now coexist" no longer holds: :php:`PromptSnippet`
  (fragments) and :php:`Task` (predefined prompts) are the remaining
  surfaces.

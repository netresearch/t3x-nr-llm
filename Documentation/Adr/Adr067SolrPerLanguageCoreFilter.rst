.. include:: /Includes.rst.txt

.. _adr-067:

============================================================
ADR-067: Solr per-language core — no language filter query
============================================================

.. _adr-067-status:

Status
======
**Accepted** (2026-07) — bug fix refining the Solr retrieval backend introduced
in :ref:`ADR-049 <adr-049>`.

.. _adr-067-context:

Context
=======
``SolrSearchBackend`` (ADR-049) added ``fq=language:<id>`` to every Solr query, in
both ``search()`` and ``fetchSource()``. EXT:solr separates languages by
**per-language cores** (``core_de``, ``core_en``, …) whose index schema has **no**
``language`` field. ``selectUrl()`` already selects the per-language read core via
the site's per-language ``solr_core_read`` override, so the language dimension is
handled by core selection. The extra ``fq=language:<id>`` therefore filtered on a
field that does not exist — Solr returned zero results for every query, even
against a populated core.

Live-verified on the BMDV deployment: querying ``core_de`` with only
``{!typo3access}0,-1`` returned 41 documents; adding ``fq=language:0`` returned 0,
and the Solr schema API reports ``No such field [language]``.

EXT:solr's only shared-core mode shares a core across **sites of the same
language** (disambiguated by ``siteHash``; see ``siteScopedUrl()``), never across
languages — so no language filter is needed there either.

.. _adr-067-decision:

Decision
========
Drop the ``fq=language:<id>`` filter from both ``search()`` and ``fetchSource()``.
Language is selected by the per-language read core; access by
``fq={!typo3access}0,-1``. The ``language`` field stays in ``fl`` (harmless —
``toEvidence()`` falls back to the query's ``languageId`` when the response omits
it).

**Limitation (recorded, not a regression):** shared-core language separation is
unsupported. EXT:solr does not produce that topology — one core per language is
the configset default — so this is a documented boundary, not a gap.

.. _adr-067-consequences:

Consequences
============
- Solr retrieval returns results on standard EXT:solr per-language-core setups
  (previously it was always empty).
- The per-query filter contract is now ``{!typo3access}0,-1`` only (plus
  ``type``/``uid`` on ``fetchSource``) — consistent with ADR-049's stated
  public-only contract.
- No API or configuration change; a pure query-construction fix.

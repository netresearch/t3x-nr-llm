.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _changelog:

=========
Changelog
=========

This page summarizes the latest release. The development branch also includes
unreleased changes: inspect the `pending changelog fragments
<https://github.com/netresearch/t3x-nr-llm/tree/main/changelog.d>`__ before
assuming a behavior is available in a tagged package.

The `complete changelog
<https://github.com/netresearch/t3x-nr-llm/blob/main/CHANGELOG.md>`__ is the
canonical release ledger. The manual's older highlights are selective and
may omit intermediate versions.

During 0.x, a minor release may introduce breaking changes; patch updates
remain within the minor series. Review migration notes before upgrading.

.. toctree::
   :maxdepth: 1

   Changelog/Index

.. _version-0-43-20:

Version 0.43.20 (2026-11-06)
============================

The setup wizard comes first under AI > Setup, the error hints module asks
the configuration the administrator chose, in the backend language, and the
assistant no longer reports a write as refused because TYPO3 complained about
another record of the same save.

- The setup wizard is the first entry under AI > Setup, followed by providers,
  models and configurations; use-case packs come last, because a pack's
  configuration needs a provider and a model. On TYPO3 14.3, while no
  provider exists, the AI > Setup overview opens with a notice and a button
  to the setup wizard. TYPO3 13.4 has no such overview: AI > Setup opens the
  last used entry, or the setup wizard on the first visit.
- Error hints module: each administrator chooses which active LLM
  configuration is asked, from a list that marks what each configuration may
  receive by its trust zone. The choice is kept in the backend user's module
  data and falls back to the default configuration when the chosen one is
  deleted or deactivated. A refusal for the trust zone says what to do and
  links to Configurations and Providers.
- Error hints module: refusals and the model's answer follow the
  administrator's backend language instead of always being English; the
  console command stays English.
- A write the assistant made is no longer reported as refused because TYPO3
  logged an error about another record of the same save (for example from a
  DataHandler hook that writes another page). Every writer reads the record
  back first: what took is reported as written, with TYPO3's messages
  attached; a write of which nothing is left is reported as failed; a partial
  result is reported as written in part, to be checked. The attaching writers
  also check that the new reference points at the file asked for.

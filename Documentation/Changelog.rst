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

.. _version-0-49-0:

Version 0.49.0 (2026-11-16)
===========================

A2A push notifications and file parts, an A2A skill that writes page metadata,
and a security fix for queue workers. Run the TYPO3 database schema update
after updating. Push notifications and files are off by default.

- **Push notifications:** in the server role (``a2a.server.push``) a client
  registers webhooks for its own tasks, and ``nrllm:a2a:poll`` delivers them;
  in the client role (``a2a.client.push``) a delegation registers a callback
  that brings the next ``GetTask`` forward. Schedule ``nrllm:a2a:poll`` at
  least once a minute while push is on.
- **Files:** with ``a2a.files.enabled`` a message may carry ``raw`` and
  ``url`` parts of five media types, checked and kept in a private, sealed
  store; a completed task sends the files it registered as artifacts. Check
  the body size limit of every proxy and of PHP in front of the endpoint.
- **Page metadata skill:** ``nr_llm.page_metadata.v1`` writes the named
  metadata of one page in one language as a draft, bound to the scope of the
  request (ADR-296). A write must match its request (ADR-297) and the
  proposal a ``check_page_metadata`` call passed, on an unchanged row
  (ADR-298). A profile of the skill grants both tools.
- **Tools and services:** ``describe_fal_asset`` returns the metadata of a
  file inside the acting user's file mounts (ADR-303); :php:`ExternalTextCheck`
  checks a text for use outside TYPO3 against a configuration's rules
  (ADR-300).
- **Security:** a queue worker resets run-scoped state, such as the file
  gate's per-user storages, before and after each run.

Details in the repository :file:`CHANGELOG.md`.

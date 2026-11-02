.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _adr-244:

====================================================================
ADR-244: A site names its configuration and its DeepL key
====================================================================

:Status: Accepted
:Date: 2026-11-02
:Authors: Netresearch DTT GmbH

Context
=======

An installation with several sites, often several clients, had one default
configuration and one DeepL key for all of them. :ref:`ADR-238 <adr-238>` gave
term lists a site, read from the metadata key ``nrllm.siteIdentifier``, but no
caller filled it. ``TranslationOptions::withSite()`` existed for the glossary
(:ref:`ADR-208 <adr-208>`) only.

Decision
========

**Two site settings in the set** ``netresearch/nr-llm``: ``nrllm.configuration``
holds the identifier of an LLM configuration, ``nrllm.deepl.apiKeyIdentifier``
the identifier of an nr-vault secret. A key never sits in a site setting,
because ``settings.yaml`` is versioned with the project; this is how a provider
key and the global DeepL key are stored too.

**The site replaces the installation default and nothing else.** An explicit
choice wins: a pinned provider, a configuration the caller passes, an
extension's own named configuration. Below that comes the configuration the
site names, then the installation default. ``chat()``, ``complete()`` and
``streamChat()`` apply it for a call that states its site;
``ConfigurationResolver::resolveForSite()`` lets an extension with its own
fallback put the site in the same place.

**A site that names something unusable fails the call.** A configuration that
does not exist or is disabled, a group-restricted one on a path without an
actor to check — ``chat()``, ``complete()`` and ``streamChat()`` have none —
and a DeepL identifier the vault does not hold are errors.
``resolveForSite()`` with an actor applies the group rule instead. Falling
back would send the site's text to the provider or the DeepL account the site
was set up to avoid.

**The caller states the site.** ``withSite()`` moves from ``TranslationOptions``
to ``AbstractOptions``; it and the metadata key end in the same place. nr-llm
does not derive the site from the request, because a worker has none.

**DeepL binds per call.** ``DeepLTranslator::forSite()`` returns a copy with the
site's identifier. The shared service keeps the global one, and Free or Pro is
remembered per identifier, so a worker serving two sites does not send one
site's key to the other's endpoint. A site glossary carries its site, so its
DeepL glossary is created in that site's account; one created earlier under
another key is refused by DeepL and recreated (ADR-208).

**Who may change it:** administrators. The settings editor is the
``site_settings`` module in TYPO3 13.4 and the ``editSettings`` route of
``site_configuration`` in 14.3, both with access ``admin``. An administrator
who points the DeepL setting at another vault entry gains nothing, since an
administrator can already reach the vault.

Consequences
============

- ● One installation serves sites with their own provider, model and DeepL
  account, and term lists bound to a site work.
- ● The settings hold identifiers only; no secret enters the repository.
- ◑ A site has to depend on the set for the editor to show the settings.
- ◑ Embeddings, vision, conversations and the image and speech services do not
  read the site's configuration.
- ✕ A call whose caller does not state its site gets the installation default.

Net Score: +3

Alternatives considered
=======================

**The DeepL key in the site setting.** Rejected: it would be committed with the
site configuration.

**Fall back to the installation default when the site's choice is unusable.**
Rejected: silent, and it defeats the reason a site names its own choice.

**Derive the site from the current request.** Rejected: the worker, the
scheduler and the CLI have no request, and a backend request is not always
about the page it was sent from.

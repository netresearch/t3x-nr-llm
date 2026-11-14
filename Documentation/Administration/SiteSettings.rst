.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _administration-site-settings:

=============
Site settings
=============

A site can name the LLM configuration and the DeepL key its AI calls use. A
site that names neither uses the installation's: the default configuration
and the key in the extension configuration. :ref:`ADR-244 <adr-244>` records
the decisions.

.. _administration-site-settings-setup:

Setting it up
=============

#.  Add the set :guilabel:`LLM Foundation` (``netresearch/nr-llm``) to the
    site's dependencies. Without it the settings editor does not show the two
    settings, but a value in ``config/sites/<site>/settings.yaml`` still
    applies.
#.  Open :guilabel:`Site Management > Sites`, edit the site's settings and fill
    the category :guilabel:`AI (LLM Foundation)`.

Only administrators can change site settings: TYPO3 13.4 and 14.3 offer the
settings editor to administrators only. In ``settings.yaml`` write the nested
form shown below: a dotted key such as ``nrllm.configuration: x`` is not read
on a site that does not depend on the set. Whoever can change
``config/sites/<site>/settings.yaml`` in the project can change them as well.

.. code-block:: yaml
    :caption: config/sites/shop/settings.yaml

    nrllm:
      configuration: shop-writer
      deepl:
        apiKeyIdentifier: shop-deepl

.. _administration-site-settings-configuration:

LLM configuration
=================

``nrllm.configuration`` holds the identifier of an LLM configuration. A call
that states its site runs on that configuration instead of the installation
default. What an extension chose explicitly still wins: the configuration of
a cowriter request, of a landing page template or of an extension's own
named configuration.

A configuration that does not exist, is disabled or is restricted to backend
groups makes the call fail. It does not fall back to the default, because the
site was set up not to use it.

The backend AI chat (``netresearch/nr-mcp-agent``, releases after 0.36.0) puts the
site above its own choice: a conversation about a page of the site runs on the
site's configuration instead of the chat Task's. The chat works only through
tools, so it refuses a site configuration without a fixed model, or whose
model lacks the capability :guilabel:`Tool Use` or whose provider cannot call
tools, and says so in the chat; it does not fall back to the Task's
configuration. Tick :guilabel:`Tool Use` on a Groq model that can call tools:
Groq reports no capabilities, so discovery leaves it unset. The chat's own
documentation has the details (its ADR-033).

.. _administration-site-settings-deepl:

DeepL key
=========

``nrllm.deepl.apiKeyIdentifier`` holds the identifier of an nr-vault secret,
like ``translators.deepl.apiKeyIdentifier`` in the extension configuration.
Store the key in the vault first and enter its identifier here. Never enter
the key itself: ``settings.yaml`` is versioned with the project.

Translations, glossary creation and glossary deletion for that site use this
key. A Free key (ending in ``:fx``) goes to the Free endpoint, any other key to
the Pro endpoint; ``translators.deepl.baseUrl`` still overrides both. A site
glossary created under the global key is created again in the site's DeepL
account when DeepL refuses the old glossary id with a 400 or 404 that names the
glossary; how DeepL answers an id of another account has not been measured. An
identifier the vault does not hold
makes the translation fail; it does not fall back to the global key.

Whether DeepL is offered as a translator at all is still decided by the global
key: an installation whose sites all have their own key needs a key in the
extension configuration as well, or callers that pick a translator
automatically do not pick DeepL.

.. _administration-site-settings-callers:

Which calls know their site
===========================

nr-llm does not guess a site from the request. An extension states it with
``withSite()`` on its option object, which selects the site's configuration
and narrows the :ref:`term lists <administration-term-lists-scope>` that are
bound to a site. The metadata key ``ProviderCallContext::METADATA_SITE`` on a
call with an explicit configuration only narrows the term lists.

The site's configuration applies to ``chat()``, ``complete()`` and
``streamChat()``, and through them to the completion and translation services
and to document analysis on a provider that reads PDF files natively.
Embeddings, vision, conversations, the image and speech services and a
rasterized PDF, whose pages go to vision, keep the installation default. A tool
run keeps its site across an approval or an input it waits for. An agent run
queued for the background worker loses it: its turns are checked against every
site's forbidden terms and not against its own required terms.

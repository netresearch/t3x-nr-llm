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
settings editor to administrators only. Whoever can change
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
account on the site's first translation. An identifier the vault does not hold
makes the translation fail; it does not fall back to the global key.

Whether DeepL is offered as a translator at all is still decided by the global
key: an installation whose sites all have their own key needs a key in the
extension configuration as well, or callers that pick a translator
automatically do not pick DeepL.

.. _administration-site-settings-callers:

Which calls know their site
===========================

nr-llm does not guess a site from the request. An extension states it with
``withSite()`` on its option object or with the metadata key
``ProviderCallContext::METADATA_SITE``. The same value narrows the
:ref:`term lists <administration-term-lists-scope>` that are bound to a site.

The site's configuration applies to ``chat()``, ``complete()`` and
``streamChat()``. Embeddings, vision, conversations and the image and speech
services keep the installation default.

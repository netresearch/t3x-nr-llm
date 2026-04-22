..  include:: /Includes.rst.txt

..  _developer-provider-registration:

================================
Registering a provider
================================

Two mechanisms pick up your provider class. Use the attribute when
you can.

..  _developer-provider-registration-attribute:

Preferred: the ``#[AsLlmProvider]`` attribute
==============================================

Add the attribute to any provider class that lives under the
``Netresearch\NrLlm\`` namespace. The compiler pass auto-tags the
service, sets it public (so backend diagnostics can resolve it by
class name), and registers it with
:php:`LlmServiceManager` in priority order:

..  code-block:: php
    :caption: Classes/Provider/MyProvider.php

    use Netresearch\NrLlm\Attribute\AsLlmProvider;
    use Netresearch\NrLlm\Provider\AbstractProvider;

    #[AsLlmProvider(priority: 85)]
    final class MyProvider extends AbstractProvider
    {
        public function getIdentifier(): string
        {
            return 'my-provider';
        }

        public function getName(): string
        {
            return 'My LLM Service';
        }

        // ... chatCompletion(), embeddings(), supportsFeature()
    }

Priority is an ordering hint only. Providers are still resolved by
their ``getIdentifier()`` at runtime. Higher priority wins when two
providers otherwise tie.

..  note::
    The attribute scan is scoped to the ``Netresearch\NrLlm\``
    namespace to keep container-build reflection bounded.
    Third-party extensions shipping providers outside that namespace
    must continue to use the yaml-tagging path described below.

..  _developer-provider-registration-yaml:

Third-party fallback: yaml tagging
==================================

Extensions that sit outside the ``Netresearch\NrLlm\`` namespace
still work via the original mechanism — declare a service with the
``nr_llm.provider`` tag:

..  code-block:: yaml
    :caption: EXT:my_ext/Configuration/Services.yaml

    services:
      Acme\MyExt\Provider\AcmeProvider:
        public: true
        tags:
          - name: nr_llm.provider
            priority: 85

When both yaml tagging AND the attribute are present on the same
service, the yaml wins (the attribute pass skips already-tagged
services). Treat this as an override hook rather than an additive
mechanism.

..  _developer-provider-registration-interfaces:

Capability interfaces
=====================

Priority governs registration order only; it says nothing about
what a provider can do. Capabilities are advertised by implementing
the relevant interface from :php:`Netresearch\NrLlm\Provider\Contract`:

-   :php:`VisionCapableInterface` — image analysis
-   :php:`StreamingCapableInterface` — SSE streaming
-   :php:`ToolCapableInterface` — function / tool calling
-   :php:`DocumentCapableInterface` — PDF / structured document input

:php:`LlmServiceManager` dispatches to a provider only when the
caller's requested operation matches a capability the provider
actually advertises. A provider that doesn't implement
:php:`VisionCapableInterface` can never be asked to describe an
image, regardless of priority. See :ref:`adr-022` for the
attribute-discovery design decision and the Symfony
``registerAttributeForAutoconfiguration`` alternative we evaluated.

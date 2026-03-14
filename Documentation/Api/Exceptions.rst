.. include:: /Includes.rst.txt

.. _api-exceptions:

==========
Exceptions
==========

.. php:namespace:: Netresearch\NrLlm\Provider\Exception

.. php:class:: ProviderException

   Base exception for provider errors.

   .. php:method:: getProvider(): string

      Get the provider that threw the exception.

.. php:class:: ProviderConfigurationException

   Thrown when a provider is incorrectly
   configured.

   Extends
   :php:class:`Netresearch\\NrLlm\\Provider\\Exception\\ProviderException`

.. php:class:: ProviderConnectionException

   Thrown when a connection to the provider
   fails.

   Extends
   :php:class:`Netresearch\\NrLlm\\Provider\\Exception\\ProviderException`

.. php:class:: ProviderResponseException

   Thrown when the provider returns an
   unexpected or error response.

   Extends
   :php:class:`Netresearch\\NrLlm\\Provider\\Exception\\ProviderException`

.. php:class:: UnsupportedFeatureException

   Thrown when a requested feature is not
   supported by the provider.

   Extends
   :php:class:`Netresearch\\NrLlm\\Provider\\Exception\\ProviderException`

.. php:namespace:: Netresearch\NrLlm\Exception

.. php:class:: InvalidArgumentException

   Thrown for invalid method arguments.

.. php:class:: ConfigurationNotFoundException

   Thrown when a named configuration is not found.

.. _api-events:

Events
======

.. note::

   PSR-14 events (``BeforeRequestEvent``, ``AfterResponseEvent``) are planned
   for a future release. The event classes do not exist yet in the current
   codebase.

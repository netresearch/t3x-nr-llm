.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-middleware:

===================
Provider middleware
===================

Implement ``Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface``
for a provider-call concern. Its method is
``handle(ProviderCallContext $context, callable $next): mixed``. The current
configuration is on ``$context->configuration``; when replacing it, derive a
new context with ``withConfiguration()``. Pass that context to ``$next``.

.. code-block:: php
   :caption: Transparent middleware extension

   <?php

   declare(strict_types=1);

   namespace MyVendor\MyExtension\Provider;

   use Netresearch\NrLlm\Provider\Middleware\ProviderCallContext;
   use Netresearch\NrLlm\Provider\Middleware\ProviderMiddlewareInterface;

   final class ObserveCall implements ProviderMiddlewareInterface
   {
       public static function getDefaultPriority(): int
       {
           return 80;
       }

       public function handle(ProviderCallContext $context, callable $next): mixed
       {
           return $next($context);
       }
   }

The interface supplies the ``nr_llm.provider_middleware`` autoconfiguration
tag. Priority comes from the static ``getDefaultPriority()`` convention;
omitting it means priority 0. Do not rely on a duplicate tag's attribute
priority. Higher priority enters first and exits last.

.. list-table:: Built-in outer-to-inner order in this revision
   :header-rows: 1

   * - Middleware
     - Priority
   * - Telemetry
     - 110
   * - Idempotency
     - 105
   * - Cache
     - 100
   * - Guardrail
     - 90
   * - Empty answer
     - 85
   * - Budget
     - 75
   * - Fallback
     - 50
   * - Usage
     - 25
   * - Circuit breaker
     - 20

This order explains short circuits: a cache hit does not become another paid
provider request. A retry or fallback changes which inner layers execute;
observe the actual context rather than inferring the serving model from a
stored fixed-model relation. Streaming has its own dispatcher lifecycle;
see :ref:`developer-streaming`. The historical design is :ref:`adr-026` and
its context change is :ref:`adr-096`.

.. include:: /Includes.rst.txt

.. _developer-endpoint-protection:

===============================================
Protecting anonymous LLM-cost-bearing endpoints
===============================================

Every request that reaches an anonymous frontend endpoint backed by nr-llm
(a search box, a chat widget, a content assistant) triggers a paid provider
call. A single scripted attacker — or one careless crawler — can turn such
an endpoint into an open cost faucet.

nr-llm's :ref:`per-user budgets <administration-user-budgets>` cap the
*aggregate* spend attributed to a backend user, so a runaway endpoint
cannot exceed its monthly ceiling. Budgets do not, however, limit the
*request rate* of an individual attacker: one IP can still burn the whole
budget and deny the feature to everyone else. Per-request protection is the
consuming extension's responsibility, at its own HTTP surface.

This page shows the three patterns to apply. They are small, build only on
TYPO3 core and Symfony primitives, and need no extra infrastructure.

.. contents::
   :local:
   :depth: 2

.. _developer-endpoint-protection-rate-limiter:

Per-IP rate limiting
====================

TYPO3 core already depends on ``symfony/rate-limiter`` (core's own login
rate limiting uses it) and ships a storage adapter that persists limiter
state in the caching framework:
:php:`TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage`. The
whole recipe is one service alias plus a ~25-line class.

Require the component explicitly, since your code uses its classes
directly:

.. code-block:: bash
   :caption: Add the dependency

   composer require symfony/rate-limiter

Alias the Symfony storage interface to TYPO3's caching-framework-backed
implementation:

.. code-block:: yaml
   :caption: Configuration/Services.yaml

   services:
       _defaults:
           autowire: true
           autoconfigure: true
           public: false

       Symfony\Component\RateLimiter\Storage\StorageInterface:
           alias: TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage

.. note::

   Service aliases are container-wide, not per-extension. If another
   extension in the same installation aliases
   :php:`StorageInterface` to a different storage, the definitions
   collide. In that case, drop the alias and pass the storage to your
   limiter service as a named ``$storage`` argument referencing
   ``@TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage``.

The limiter itself keys on the client IP resolved through the
``normalizedParams`` request attribute — a :php:`NormalizedParams`
instance TYPO3 sets on every frontend and backend request — which honors
TYPO3's reverse-proxy configuration
(:php:`$GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP']`) instead of
trusting ``REMOTE_ADDR`` blindly. The raw ``REMOTE_ADDR`` fallback only
covers requests that never passed through TYPO3's request handling, such
as unit tests:

.. code-block:: php
   :caption: Classes/Infrastructure/RequestRateLimiter.php

   <?php

   declare(strict_types=1);

   namespace MyVendor\MyExtension\Infrastructure;

   use Psr\Http\Message\ServerRequestInterface;
   use Symfony\Component\RateLimiter\RateLimiterFactory;
   use Symfony\Component\RateLimiter\Storage\StorageInterface;
   use TYPO3\CMS\Core\Http\NormalizedParams;

   final readonly class RequestRateLimiter
   {
       private RateLimiterFactory $rateLimiterFactory;

       public function __construct(
           StorageInterface $storage,
           int $limitPerMinute = 30,
           string $limiterId = 'myext_frontend',
       ) {
           $this->rateLimiterFactory = new RateLimiterFactory(
               [
                   'id' => $limiterId,
                   'policy' => 'sliding_window',
                   'limit' => $limitPerMinute,
                   'interval' => '1 minute',
               ],
               $storage,
           );
       }

       public function tooManyRequests(ServerRequestInterface $request): bool
       {
           $normalizedParams = $request->getAttribute('normalizedParams');

           $remoteIp = $normalizedParams instanceof NormalizedParams
               ? $normalizedParams->getRemoteAddress()
               : (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');

           return !$this->rateLimiterFactory
               ->create($remoteIp)
               ->consume()
               ->isAccepted();
       }
   }

Design choices worth keeping:

*   ``sliding_window`` avoids the burst-at-window-boundary problem of the
    ``fixed_window`` policy: an attacker cannot double the effective rate
    by straddling two windows.
*   The limiter ``id`` partitions the counters. Give each cost-bearing
    endpoint its own id (and, if the costs differ, its own limit) by
    registering separately configured instances:

.. code-block:: yaml
   :caption: Configuration/Services.yaml — one limiter per endpoint

   services:
       _defaults:
           autowire: true
           autoconfigure: true
           public: false

       MyVendor\MyExtension\Infrastructure\RequestRateLimiter: ~

       myext.rate_limiter.chat:
           class: MyVendor\MyExtension\Infrastructure\RequestRateLimiter
           arguments:
               $limiterId: 'myext_chat'

The ``~`` (null) definition inherits everything from ``_defaults``:
with ``autowire: true`` the :php:`StorageInterface` constructor
argument resolves through the alias above, and the two scalar
parameters keep their declared defaults. Without the ``_defaults``
block (or an explicit ``autowire: true`` on the definition) the
container would fail to instantiate the service.

*   Where the limit value comes from — extension configuration, a site
    setting, or a constructor default — is your choice. If you read it
    from configuration, validate it at construction time and fail hard on
    a missing or non-positive value rather than silently running
    unlimited.

.. _developer-endpoint-protection-cross-site:

Rejecting cross-site requests
=============================

Browsers implementing the Fetch Metadata spec send a ``Sec-Fetch-Site``
header with every request. Rejecting the value ``cross-site`` stops other
origins from firing forged POSTs at your endpoint through visitors'
browsers — a cost-exhaustion vector that per-IP limiting alone spreads
across many victim IPs instead of stopping:

.. code-block:: php
   :caption: Classes/Infrastructure/FrontendRequestInspector.php

   <?php

   declare(strict_types=1);

   namespace MyVendor\MyExtension\Infrastructure;

   use Psr\Http\Message\ServerRequestInterface;

   final readonly class FrontendRequestInspector
   {
       public function isCrossSiteRequest(
           ServerRequestInterface $request,
       ): bool {
           return $request->getHeaderLine('Sec-Fetch-Site') === 'cross-site';
       }
   }

**The check fails open by design.** A request *without* the header (an
older browser, or a non-browser client such as curl) is not blocked. This
is deliberate, not an oversight: all current browsers send the header
automatically, so the check reliably covers the browser-mediated
cross-site attack it targets, while non-browser clients — which never
send Fetch Metadata — are exactly what the rate limiter above handles.
Treat this check as defense in depth on top of the rate limiter, never as
the sole protection.

.. _developer-endpoint-protection-error-shaping:

Never-leak error shaping
========================

An anonymous endpoint must not reveal *why* a request was refused or what
failed internally. Provider names, exception messages, configuration
paths, and budget states are reconnaissance material. Shape every failure
path to the same generic, translated message, and keep the diagnostic
detail in server-side logs only:

.. code-block:: php
   :caption: Controller action combining all three patterns

   use InvalidArgumentException;
   use Netresearch\NrLlm\Provider\Exception\ProviderException;
   use Psr\Http\Message\ResponseInterface;

   // … inside the controller class:

   public function searchAction(string $query = ''): ResponseInterface
   {
       if ($this->requestInspector->isCrossSiteRequest($this->request)
           || $this->rateLimiter->tooManyRequests($this->request)
       ) {
           $this->view->assign('message', $this->translator->translate(
               'search.rateLimited',
               'Too many requests - please wait a moment and try again.',
           ));

           return $this->htmlResponse();
       }

       try {
           $answer = $this->queryFlow->answer(trim($query));
       } catch (ProviderException | InvalidArgumentException) {
           // The exception carries its diagnostic context for server-side
           // logging; none of it is forwarded to the response.
           $this->view->assign('message', $this->translator->translate(
               'search.error',
               'Something went wrong. Please try again later.',
           ));

           return $this->htmlResponse();
       }

       $this->view->assign('answer', $answer);

       return $this->htmlResponse();
   }

Two details matter:

*   Catch :php:`InvalidArgumentException` (or your own input-validation
    exception) alongside the provider exceptions. Value-object
    constructors that enforce structural invariants (query length caps,
    range checks) throw it for attacker-controlled input; uncaught, that
    surfaces as an HTTP 500 with a stack trace instead of the generic
    message.
*   Use the same response *shape* for the rate-limited and the failed
    path. Distinguishable responses let an attacker probe which control
    they tripped.

.. _developer-endpoint-protection-reference:

Reference implementation
========================

The ``nr_ai_search`` extension (Netresearch) applies all three patterns to
its anonymous search and chat plugins: its
:php:`RequestRateLimiter` and :php:`FrontendRequestInspector` classes in
:file:`Classes/Infrastructure/` match the recipes above, with the limit
read from extension configuration and a separately configured limiter id
per controller.

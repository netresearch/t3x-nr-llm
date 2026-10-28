.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _developer-own-provider-usage:

==============================================
Report usage from your own provider calls
==============================================

This page is for an extension that sends its requests to an AI provider
with its own code instead of through nr-llm. Such an extension can still
respect the editor's AI budget and appear with its costs in the Analytics
module and in the monthly billing report. It needs two calls: a budget
check before the request and a usage report after it.

.. contents::
   :local:
   :depth: 2

.. _developer-own-provider-usage-alternatives:

Before you start: route the call through nr-llm instead
=======================================================

If you can, let nr-llm make the call. A call through
:php:`LlmServiceManagerInterface` or a feature service (see
:ref:`integration-guide`) is checked against the budget and recorded
automatically; name your extension on it with
``AbstractOptions::withCallerSource()`` and Analytics lists its cost under
your extension key. One path does not carry that name yet:
:php:`TranslationService::translateWithTranslator()`, which hands the
options to a specialized translator such as DeepL, records the translation
as unattributed. For third-party extensions you cannot change, the
compatibility layer `nr-llm-compat
<https://github.com/netresearch/t3x-nr-llm-compat>`__ reroutes their
provider calls through nr-llm and names each one the same way. The two
calls on this page are for the remaining case: your extension keeps its
own provider code.

.. _developer-own-provider-usage-example:

Example
=======

The class below summarizes a text with a direct HTTP request to a provider
and wraps that request with the two calls. Both services are ``@api``
interfaces and public in the container, so constructor injection is
enough. A functional test of nr-llm runs this exact code against the real
services and the database.

.. code-block:: php
   :caption: Classes/Service/SummaryGenerator.php

   <?php

   declare(strict_types=1);

   namespace MyVendor\MyExtension\Service;

   use Netresearch\NrLlm\Exception\BudgetExceededException;
   use Netresearch\NrLlm\Service\BudgetServiceInterface;
   use Netresearch\NrLlm\Service\UsageTrackerServiceInterface;
   use Psr\Http\Client\ClientInterface;
   use Psr\Http\Message\RequestFactoryInterface;
   use Psr\Http\Message\StreamFactoryInterface;

   final readonly class SummaryGenerator
   {
       private const ENDPOINT = 'https://api.example.com/v1/chat/completions';

       // USD per one million tokens, from your provider's price list
       private const INPUT_PRICE = 2.50;
       private const OUTPUT_PRICE = 10.00;

       // An estimate of what one summary costs, not a cap: the
       // recorded cost of a call can be higher
       private const PLANNED_COST = 0.02;

       public function __construct(
           private BudgetServiceInterface $budget,
           private UsageTrackerServiceInterface $usageTracker,
           private ClientInterface $httpClient,
           private RequestFactoryInterface $requestFactory,
           private StreamFactoryInterface $streamFactory,
       ) {}

       /**
        * @param int $beUserUid the logged-in backend user, never a
        *                       request value
        */
       public function summarize(int $beUserUid, string $text): string
       {
           // 1. Check the editor's budget before the call
           $check = $this->budget->check($beUserUid, self::PLANNED_COST);
           if (!$check->allowed) {
               throw new BudgetExceededException($check);
           }

           // 2. Your own provider call; authentication and error
           //    handling are left out here
           $payload = json_encode([
               'model' => 'example-model',
               'messages' => [
                   ['role' => 'user', 'content' => 'Summarize: ' . $text],
               ],
           ], JSON_THROW_ON_ERROR);
           $request = $this->requestFactory
               ->createRequest('POST', self::ENDPOINT)
               ->withHeader('Content-Type', 'application/json')
               ->withBody($this->streamFactory->createStream($payload));
           $body = (string) $this->httpClient->sendRequest($request)
               ->getBody();
           $answer = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
           $promptTokens = (int) $answer['usage']['prompt_tokens'];
           $completionTokens = (int) $answer['usage']['completion_tokens'];

           // 3. Report what the call used and what it cost
           $this->usageTracker->trackUsage(
               serviceType: 'chat',
               provider: 'example',
               metrics: [
                   'tokens' => $promptTokens + $completionTokens,
                   'promptTokens' => $promptTokens,
                   'completionTokens' => $completionTokens,
                   'cost' => ($promptTokens * self::INPUT_PRICE
                       + $completionTokens * self::OUTPUT_PRICE) / 1_000_000,
               ],
               modelId: (string) $answer['model'],
               beUserUid: $beUserUid,
               sourceExtension: 'my_extension',
           );

           return (string) $answer['choices'][0]['message']['content'];
       }
   }

Pass the uid of the logged-in backend user, for example
``$context->getPropertyFromAspect('backend.user', 'id')`` with an injected
:php:`\TYPO3\CMS\Core\Context\Context`. Never take it from request
parameters: the budget and the attribution would then belong to whoever
the request names.

.. _developer-own-provider-usage-budget:

Check the budget before the call
================================

:php:`BudgetServiceInterface::check(int $beUserUid, float $plannedCost =
0.0, ?LlmConfiguration $configuration = null)` returns a
:php:`BudgetCheckResult`. It reads the editor's budget record (see
:ref:`administration-user-budgets`) and the usage already recorded today
and this month. It does not reserve or record anything.

``$beUserUid``
    Required. A value of ``0`` or less skips the per-user budget: command
    line and scheduler calls have none.

``$plannedCost``
    Optional, default ``0.0``. What the coming call is expected to cost, in
    USD: an estimate, not a cap. Nothing stops the call from costing more,
    and :php:`trackUsage()` records the actual cost, which can be higher —
    in the example, a long input text makes a more expensive call. It is
    added to the cost already spent before the comparison with
    the daily and monthly cost limit. A negative value counts as ``0.0``.
    Request limits count the coming call as one request; token limits
    compare only the tokens already used, because the tokens of the coming
    call are not known yet.

``$configuration``
    Optional, default ``null``. Only for a persisted nr-llm configuration
    record whose own daily limits should apply as well; an extension with
    its own provider code usually has none. Those limits are compared with
    the usage recorded under the configuration's uid, so pass the same uid
    as ``$configurationUid`` to :php:`trackUsage()`; otherwise your calls
    do not count against them.

When :php:`$check->allowed` is ``false``, do not send the request.
:php:`$check->exceededLimit` names the limit that was reached as a stable
key (a :php:`BudgetCheckResult::LIMIT_*` constant such as
``daily_cost``), and :php:`$check->reason` is an English sentence for logs.
The example throws :php:`BudgetExceededException`, the exception nr-llm
throws for its own calls, so code that already handles it for nr-llm calls
handles yours too. Returning a message to the editor instead works just as
well.

The check is best-effort: two requests running at the same time can both
pass it before either is recorded.

.. _developer-own-provider-usage-track:

Report the call after it
========================

:php:`UsageTrackerServiceInterface::trackUsage()` adds the call to the
usage table :sql:`tx_nrllm_service_usage`. Calls on the same day with the
same values for service type, provider, backend user, configuration,
model, task and source extension are added up in one row.

``$serviceType``
    Required. The kind of call, for example ``chat``, ``complete``,
    ``embed``, ``vision``, ``image``, ``speech`` or ``translation`` — the
    values nr-llm itself records. Analytics and the billing report group by
    it.

``$provider``
    Required. A label for the provider, shown in the provider breakdown.

``$metrics``
    Optional, default ``[]``. The keys ``tokens``, ``promptTokens``,
    ``completionTokens``, ``characters``, ``audioSeconds``, ``images`` and
    ``cost``; a missing key counts as ``0``. ``cost`` is in USD and is
    stored with six decimals. ``tokens`` is not derived from the other two
    token counts: pass the total, because the budget's token limits and
    the Analytics token figures read it.

``$configurationUid``, ``$modelUid``, ``$taskUid``
    Optional, default ``null``, ``0`` and ``0``. Uids of nr-llm records; an
    extension with its own provider code usually has none. If you passed a
    configuration to :php:`check()`, pass its uid as ``$configurationUid``:
    the configuration's daily limits only count usage recorded under that
    uid.

``$modelId``
    Optional, default ``''``. The model name, shown in the model
    breakdown.

``$beUserUid``
    Optional, default ``null``: the backend user of the current request is
    used, or ``0`` when there is none. Pass the same uid as to
    :php:`check()`, so the call counts against the budget that was checked.

``$countsAsRequest``
    Optional, default ``true``. Pass ``false`` for a sub-call of an
    operation that reports its own request, so that the tokens and the cost
    are added but the request is counted once.

``$sourceExtension``
    Optional, default ``''``. Your extension key. Without it, the call is
    listed as unattributed. Longer values are cut to 64 characters.

.. _developer-own-provider-usage-admin:

What the administrator sees
===========================

Every reported call appears in :guilabel:`AI > Operation > Analytics`
(:ref:`administration-analytics`):

-   the per-extension table lists cost, requests and tokens under the value
    of ``$sourceExtension``
    (:ref:`administration-analytics-per-extension`);
-   the provider, model and service breakdowns use ``$provider``,
    ``$modelId`` and ``$serviceType``;
-   the per-user table counts the call for the backend user passed as
    ``$beUserUid``, against that user's monthly budget.

The monthly billing report (:ref:`administration-analytics-billing`)
counts the editor as active and lists the call under "Results by kind",
one row per service type, calling extension and task. A call reported
with ``$beUserUid`` ``0`` is listed in the row for usage without a backend
user.

The next :php:`check()` for the same editor includes the reported cost and
tokens, so the budget covers your extension's calls and the calls made
through nr-llm together.

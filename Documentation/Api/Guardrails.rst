.. SPDX-License-Identifier: CC-BY-4.0
   SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _api-guardrails:

====================
Guardrail extensions
====================

Input and output checks are separate public extension points in
``Netresearch\NrLlm\Service\Guardrail``. Both inherit ``GuardrailIdentity``:
``getIdentifier(): string`` is a stable logical policy name and
``isMandatory(): bool`` declares whether configurations may omit it.
The registry resolves mandatory status per identifier and refuses mismatched
input/output declarations. Renaming an identifier changes stored policy.

``InputGuardrailInterface::checkInput(string $text): GuardrailResult``
    Runs on outgoing message text before provider dispatch. Its
    ``nr_llm.input_guardrail`` autoconfiguration tag registers the check.

``GuardrailInterface::checkOutput(CompletionResponse $response): GuardrailResult``
    Runs on buffered provider output through ``nr_llm.guardrail``. Output-only
    checks cannot inspect the original prompt through pipeline context.

.. _api-guardrails-example:

Minimal application policy
==========================

Use separate input and output services: the interfaces have different
``TAG_NAME`` constants. Their shared identifier links the policy.
This example blocks an explicit application marker. It illustrates registration
and return types; it is not a detector for all secret or harmful content.

.. code-block:: php
   :caption: Separate input and output services with one policy identity

   <?php

   declare(strict_types=1);

   namespace MyVendor\MyExtension\Guardrail;

   use Netresearch\NrLlm\Domain\Model\CompletionResponse;
   use Netresearch\NrLlm\Domain\ValueObject\GuardrailResult;
   use Netresearch\NrLlm\Service\Guardrail\GuardrailInterface;
   use Netresearch\NrLlm\Service\Guardrail\InputGuardrailInterface;

   final class PublicationInputMarker implements InputGuardrailInterface
   {
       public function getIdentifier(): string
       {
           return 'myext-publication-marker';
       }

       public function isMandatory(): bool
       {
           return false;
       }

       public function checkInput(string $text): GuardrailResult
       {
           return str_contains($text, 'DO_NOT_SEND')
               ? GuardrailResult::deny('Application marker present.')
               : GuardrailResult::allow();
       }

   }

   final class PublicationOutputMarker implements GuardrailInterface
   {
       public function getIdentifier(): string
       {
           return 'myext-publication-marker';
       }

       public function isMandatory(): bool
       {
           return false;
       }

       public function checkOutput(CompletionResponse $response): GuardrailResult
       {
           return str_contains($response->content, 'DO_NOT_PUBLISH')
               ? GuardrailResult::deny('Application marker present.')
               : GuardrailResult::allow();
       }
   }

.. _api-guardrails-verdicts:

Verdicts and limits
===================

``GuardrailResult`` provides ``allow()``, ``redact($text, $reason,
$thinking = null)``, ``deny($reason)``, ``retry($reason)`` and
``requireApproval($reason)``. Input redaction replaces message text;
input retry has no useful pre-provider action and is ignored. Output checks
can request a bounded retry through the runtime policy.

Deny and approval-required verdicts become typed guardrail policy exceptions.
They do not by themselves create a resumable human-approval workflow. In a
persisted agent run that path settles to ``FAILED`` with the policy reason;
tool approvals are the separate :ref:`api-agent-runtime` lifecycle.
A paid output can therefore be withheld after the provider call succeeded.

For configuration/site-sensitive output policy implement
``ContextualGuardrailInterface::checkOutputInContext($response, $context)``
and ``holdsStream($context)``. A held stream is screened as a whole before
release; do not assume every visible live token has passed final output checks.
Streaming redaction has its separate ``StreamRedactableInterface`` contract.

Text screening does not inspect every image byte, remote-tool side effect or
value ultimately written by a tool. Tool authorization, approval, input
validation and contextual output escaping remain separate responsibilities.
See :ref:`administration-governance` and :ref:`tool-workspaces`.

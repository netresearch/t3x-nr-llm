.. include:: /Includes.rst.txt

.. _adr-207:

====================================================================
ADR-207: The DeepL character quota is shown on the test page
====================================================================

:Status: Accepted
:Date: 2026-09-25
:Authors: Netresearch DTT GmbH

Context
=======

DeepL bills by the character against an account quota. The Free API stops at
its monthly limit; a Pro account keeps billing, often up to a cost limit the
account owner set. :php:`DeepLTranslator::getUsage()` has asked DeepL's
``/v2/usage`` endpoint for the characters used and the limit since the
translator was written, and nothing called it. An administrator learned that
the quota was spent when translations started failing with DeepL's status 456.

DeepL is not an LLM provider record. It is configured in the Extension
Configuration (``translators.deepl.*``), so the provider list, the provider
"Test connection" action and the analytics dashboard do not know it. The one
backend surface that does is the test page of
:ref:`ADR-118 <adr-118>`: its translation card lists the registered
translators, marks the ones without a credential and translates through
DeepL on request.

Decision
========

**The quota is shown in the translation card of the test page, through a
fourth AJAX route on** :php:`SpecializedTestController`. No new module, no new
card.

- ``nrllm_test_deepl_quota`` calls ``deeplQuotaAction()``, which asks the
  translation registry for ``deepl`` and returns ``used``, ``limit``,
  ``usedPercent`` and ``plan``.
- The page asks it when it opens and again after a DeepL translation. The
  usage endpoint costs no characters, which is why this is the one
  specialized endpoint that runs without a button.
- :php:`CharacterQuotaReportingInterface` declares
  ``getCharacterQuota(): CharacterQuota``, and :php:`DeepLTranslator`
  implements it. The controller depends on the interface, not the class:
  :php:`DeepLTranslator` is final, and ADR-118 solved the same problem for the
  image services with :php:`ImageGeneratorInterface`. It is not part of
  :php:`TranslatorInterface`, because most translators have no character
  quota, and adding a method there would break every third-party translator.
  A translator registered as ``deepl`` that does not implement it gets a 501
  with a plain message.
- ``plan`` names the endpoint that answered: ``free`` for
  ``api-free.deepl.com``, ``pro`` for ``api.deepl.com``, ``custom`` for a
  configured ``translators.deepl.baseUrl``. It is read from the endpoint
  :php:`DeepLTranslator` already chose from the key — a Free key ends in
  ``:fx`` — so the key is not read a second time to label it.
- A limit of 0 means DeepL reported none; the share used is then null rather
  than a division by zero.

Failures answer as the translation test does. No credential, or DeepL out of
reach, is a 503 naming the Extension Configuration. A key DeepL refuses is a
502 saying the key was rejected. Anything else is a 500 with the detail in the
log. None of the three carries the exception message, and that matters here:
the message of a failed DeepL call includes the text DeepL sent back, and the
key must not reach the page by that route either.

Consequences
============

- An administrator sees how much of the DeepL quota is left before
  translations fail, and whether the configured key is a Free or a Pro key.
- Opening the test page sends one request to DeepL whenever a DeepL key is
  configured. It costs no characters and is admin-only (ADR-037).
- The action adds a credential-bearing enforcement point to
  :php:`SpecializedTestController`; the inventory in ADR-169 section 7 counted
  40 sites before it.
- The quota is shown only on the test page. A dashboard widget or a warning
  near the limit would need the quota stored or polled; neither is built.

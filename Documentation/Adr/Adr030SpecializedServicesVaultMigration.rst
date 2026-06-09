.. include:: /Includes.rst.txt

.. _adr-030:

==================================================================
ADR-030: Specialized Services Authenticate Through nr-vault
==================================================================

:Status: Accepted
:Date: 2026-06-09
:Authors: Netresearch DTT GmbH

.. _adr-030-context:

Context
=======

The database-backed LLM providers have authenticated through the nr-vault
secure HTTP client since :ref:`adr-012` — they store a vault *identifier*
(a UUID) rather than a plaintext key, and :php:`AbstractProvider::getHttpClient()`
returns ``$vault->http()->withAuthentication(...)`` so the secret is resolved,
injected, audited, and memory-scrubbed inside the vault. The plaintext key
never surfaces in this extension's code.

The five specialised single-task services — DALL-E and FAL (image), Whisper
and TTS (speech), and DeepL (translation), all built on
:php:`AbstractSpecializedService` (see REC #7) — predated that posture. Each
read a plaintext ``apiKey`` from extension configuration into a
``protected string $apiKey`` property and assembled its own ``Authorization``
header via a ``buildAuthHeaders()`` hook, sending the request through a plain
PSR-18 client. This contradicted :ref:`adr-012` and the project rule that
*API keys MUST be stored as nr-vault UUID identifiers, never as plaintext*.

Two of the services do not use the Bearer scheme: FAL expects
``Authorization: Key <secret>`` and DeepL expects
``Authorization: DeepL-Auth-Key <secret>``. The secure client's ``Header``
placement could previously inject only the bare secret as a header value, so
these schemes could not be expressed through it at all — which is why they had
remained on the plaintext path. nr-vault ``0.8.0`` added a ``prefix`` option to
``withAuthentication()`` for ``Header`` placement, removing that blocker.

.. _adr-030-decision:

Decision
========

Migrate every keyed specialised service onto the vault secure HTTP client,
mirroring :php:`AbstractProvider`:

1. **Identifier, not key.** :php:`AbstractSpecializedService` takes
   :php:`VaultServiceInterface` as its first constructor argument and stores
   ``$apiKeyIdentifier`` (the vault UUID) instead of ``$apiKey``.
   :php:`isAvailable()` becomes
   ``$apiKeyIdentifier !== '' && $vault->exists($apiKeyIdentifier)``.

2. **Placement hooks replace** ``buildAuthHeaders()``. The base exposes
   :php:`getSecretPlacement()` (default :php:`SecretPlacement::Bearer`),
   :php:`getSecretPlacementOptions()` (default ``[]``), and
   :php:`getAdditionalHeaders()` (non-auth headers only, e.g. DeepL's
   ``User-Agent``). :php:`getSecureClient()` builds
   ``$vault->http()->withAuthentication($id, placement, options)->withReason(...)``
   and ``executeRequest()`` sends through it. Per-service placement:

   - DALL-E, Whisper, TTS — ``Bearer`` (OpenAI family).
   - FAL — ``Header`` + ``{headerName: Authorization, prefix: 'Key '}``.
   - DeepL — ``Header`` + ``{headerName: Authorization, prefix: 'DeepL-Auth-Key '}``.

3. **DeepL Free/Pro routing stays automatic.** DeepL selects the
   ``api-free.deepl.com`` host for keys ending in ``:fx`` and ``api.deepl.com``
   otherwise. Since the key is no longer held as plaintext, the host is resolved
   lazily on the first request: the secret is retrieved from the vault exactly
   once, tested for the ``:fx`` suffix, and immediately ``sodium_memzero``-d.
   An explicit ``baseUrl`` override still wins. The request itself always
   authenticates through the audited secure client, never that transient copy.

4. **Configuration.** The ext_conf keys become identifiers:
   ``providers.openai.apiKeyIdentifier`` (DALL-E/Whisper/TTS),
   ``image.fal.apiKeyIdentifier``, and ``translators.deepl.apiKeyIdentifier``.

A ``setHttpClient()`` test seam — identical to the providers' — lets unit
tests inject a plain client and assert request/response plumbing without the
vault; the placement hooks are asserted directly.

.. _adr-030-consequences:

Consequences
============

- No specialised service holds a plaintext API key; every upstream call is
  audited and the secret is scrubbed inside the vault, satisfying
  :ref:`adr-012` uniformly across providers and specialised services.
- Requires nr-vault ``^0.8.0`` (the ``prefix`` option). A ``0.7`` install would
  silently drop the prefix and send a broken ``Authorization`` header for
  FAL/DeepL, so the composer floor is raised.
- Host applications that previously wrote ``providers.openai.apiKey`` (and the
  FAL/DeepL plaintext keys) into nr_llm's extension configuration must store a
  vault secret and write its identifier instead.
- DeepL incurs one extra vault read per service instance the first time it
  sends a request (to choose Free/Pro); the result is cached for the instance
  lifetime.

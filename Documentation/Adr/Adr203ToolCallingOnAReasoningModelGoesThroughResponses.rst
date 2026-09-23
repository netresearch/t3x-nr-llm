.. include:: /Includes.rst.txt

.. _adr-203:

==============================================================================
ADR-203: Tool calling on an OpenAI reasoning model goes through Responses
==============================================================================

:Status: Accepted
:Date: 2026-09-23
:Authors: Netresearch DTT GmbH

.. _adr-203-context:

Context
=======

:php:`OpenAiProvider::chatCompletionWithTools()` posts every tool request to
``chat/completions``. OpenAI refuses that request for a GPT-6 model at its own
default reasoning effort, which is ``medium`` for Sol and Luna. The three
models differ in what they allow, and the difference decides the design:

*   ``gpt-6-sol`` and ``gpt-6-luna`` accept function tools on
    ``chat/completions`` only with ``reasoning_effort`` set to ``none``.
*   ``gpt-6-astra`` supports no ``none`` effort at all — the value returns
    HTTP 400 — and accepts no function calling on ``chat/completions`` under
    any setting.

So the parameter that would fix the first two does not exist for the third.
OpenAI's own answer is the Responses API, and its reasoning guide requires that
every item between the last user message and a function-call output is passed
into the next request untouched, so the model can carry its reasoning across
the steps of one run.

Nothing in this extension carried provider state between two calls. A
:php:`CompletionResponse` returns content, tool calls and usage; the tool loop
rebuilds the next request from :php:`ChatMessage` objects alone, and a
suspended run is persisted as :php:`ChatMessage::toArray()` shapes.

.. _adr-203-decision:

Decision
========

1.  **The transport is chosen per request, from a model profile.** A tool
    request goes to ``responses`` when the model's profile says
    ``chat/completions`` cannot serve its function tools — today the three
    GPT-6 models. Everything else stays on ``chat/completions``, the GPT-5
    and o-series reasoning models included, whose tool calls work there. The
    profile
    is one documented table — which efforts a family allows, whether
    ``chat/completions`` serves tools for it, what its default effort is —
    and not a regular expression grown one family at a time. The regex that
    :php:`isReasoningModel()` used matched ``o1``–``o9`` and ``gpt-5`` and
    silently excluded GPT-6, which is how sampling parameters were still being
    sent to it.
2.  **Only tool calls move.** Plain chat, streaming and vision keep posting to
    ``chat/completions``. A reasoning model answers those at its default effort
    today, and moving a working path to fix a broken one changes behaviour for
    every installation to no purpose.
3.  **An endpoint that is not OpenAI's keeps ``chat/completions`` unless the
    configuration opts in.** The base URL is operator-configurable and the
    ``openai`` adapter also serves compatible gateways. Such a gateway need not
    implement ``/v1/responses``, and switching it would break an installation
    that works. The predicate is the configured URL's host, not the whole
    string, because :php:`ProviderEndpointNormalizationHook` may have rewritten
    it. The opt-in is ``"openai_tools_transport": "responses"`` in the
    configuration's options JSON, which
    :php:`LlmConfiguration::toOptionsArray()` already merges into every call;
    ``"chat/completions"`` forces the old path. Whether an endpoint serves
    ``/v1/responses`` is a property of the endpoint, so the provider record
    would be the better home. It is not used because it does not hold today:
    :php:`ProviderAdapterRegistry::createAdapterFromModel()` re-runs
    ``configure()`` on the cached provider adapter with five keys, and that
    drops every other key the provider's options JSON set — measured on
    ``organizationId`` and ``customHeaders``, which come back empty. That is a
    defect of its own and is fixed separately.
4.  **``store`` stays ``false`` and no ``previous_response_id`` is sent.**
    Continuity comes from replaying the items, which in stateless mode carry
    ``encrypted_content``. Whether a conversation may be retained on OpenAI's
    servers is a data-protection decision for the installation, and this
    change does not make it on anyone's behalf.
5.  **The opaque items ride on :php:`ChatMessage`, in a field the wire shape
    does not carry.** A new :php:`?array $providerItems` holds them.
    :php:`ChatMessage::toArray()` is unchanged, because it is the OpenAI
    Chat Completions wire shape and the Groq, Mistral, OpenRouter and Ollama
    adapters put its result straight into a request payload — an unknown key
    there reaches four live APIs. A second, explicitly named
    :php:`toTranscriptArray()` carries the field for persistence — a
    suspended run and a queued one — and :php:`ToolLoopService` turns every
    stored turn that carries the key back into a :php:`ChatMessage` before
    anything is sent, so the key reaches no request as an array: not the
    Responses request, and not the Chat Completions closing answer or a
    fallback provider that follows a resume.
6.  **What came back is recorded on the response, under named keys in the
    existing metadata slot** — the transport that was used, the items to
    replay, and the effort that was applied. :php:`CompletionResponse` is
    frozen in ``api-surface.txt`` and :php:`AbstractOptions` argues in its own
    docblock against growing it; ``metadata`` already exists and already
    survives :php:`GuardrailMiddleware`'s rebuild of a screened response. An
    absent key and an empty array stay different facts.

.. _adr-203-consequences:

Consequences
============

✓ Tool calling works on every GPT-6 model at its own reasoning effort, and the
model keeps its reasoning across the steps of a run.

✓ The model-specific knowledge sits in one profile table instead of in a regex,
a capability list and a discovery filter that drifted apart.

✕ There are two serialisations of a :php:`ChatMessage`. ``toArray()`` is the
wire shape and ``toTranscriptArray()`` is the stored shape, and they differ in
one field. The alternative was to emit the field in ``toArray()`` and strip it
in four adapters, which leaves the fifth to be written wrong.

✕ Two request builders and two response parsers exist for OpenAI. They are
tested separately and a model profile decides between them; the duplication is
real and is the price of not moving the three working call paths.

**An assumption that only production can settle.** Sampling parameters are now
stripped for ``gpt-6*`` as they already were for ``gpt-5*``. No documentation
page states that GPT-6 rejects a non-default ``temperature``, and no OpenAI
credential exists on the machine this was written on, so it was not measured.
If the assumption is wrong, an operator loses a knob and sees no error; if it
had been left as it was and is wrong the other way, every GPT-6 call returns
HTTP 400 with ``temperature`` named in the message. That is the signature to
look for, and the asymmetry is why the parameter is stripped.

.. _adr-203-revisit:

Revisit when
============

OpenAI stops serving function tools on ``chat/completions`` for the
non-reasoning models as well; the profile then has one branch instead of two
and the Chat Completions builder can go.

An installation wants OpenAI's built-in tools — web search, file search, code
interpreter. They are reachable only over this transport and need a tool
vocabulary this record does not define.

.. include:: /Includes.rst.txt

.. _adr-204:

==============================================================================
ADR-204: Reasoning effort is a request option, and the thinking switch sets it
==============================================================================

:Status: Accepted
:Date: 2026-09-23
:Authors: Netresearch DTT GmbH

.. _adr-204-context:

Context
=======

:php:`ChatOptions` carries a :php:`?bool $think`. The Tool Playground offers it
as a "Thinking" switch, :php:`ChatOptions::toArray()` writes it into the options
array bypassing the null filter so that an explicit ``false`` survives, and
:php:`OllamaProvider` is the only reader of the key. :php:`getThink()` has no
caller anywhere in ``Classes/``.

So on six of seven providers the switch does nothing, and nothing says so. An
operator who met the GPT-6 tool-calling refusal (:ref:`ADR-203 <adr-203>`) and
turned thinking off saw the same error, which is the behaviour a switch wired to
one adapter produces.

A boolean is also the wrong shape for what the providers accept.
``reasoning.effort`` takes ``none``, ``minimal``, ``low``, ``medium``, ``high``,
``xhigh`` and ``max``, the supported subset differs per model, and ``gpt-6-astra``
rejects ``none`` outright.

.. _adr-204-decision:

Decision
========

1.  **Reasoning effort is a request option of its own.** A backed enum
    :php:`ReasoningEffort` names the seven values the API defines. It reaches
    the provider through :php:`ChatOptions::withReasoningEffort()` as the
    options key ``reasoning_effort``.
2.  **It is a fluent setter, not a constructor parameter.**
    :php:`ToolOptions` repeats its parent's thirteen parameters positionally
    before adding its own three, so appending one to :php:`ChatOptions` would
    move ``toolChoice`` along by one and break a positional caller. The
    repository already holds ``suppressRequestCount`` and ``responseSchema``
    out of the constructor for a comparable reason, and says so in
    :php:`ChatOptions`.
3.  **``think`` keeps its meaning and gains a translation.** It stays the
    coarse switch it is, and on a model with an effort scale
    ``think = false`` now selects the lowest effort the model allows. An
    explicit ``reasoning_effort`` wins over it, because the specific setting
    beats the general one. Only the GPT-6 models have a scale in the model
    profile, because only their model pages were read for it. GPT-5 and the
    o-series differ inside each family — o1-mini and o1-preview take no
    ``reasoning_effort`` at all, o1 and o3 stop at ``high`` — so they get no
    scale, receive no effort, and record none: what they received before
    this record.
4.  **Where the lowest allowed effort is not ``none``, the request is clamped
    and the clamp is visible.** ``gpt-6-astra`` has no ``none``; a
    ``think = false`` request to it is sent at ``low``. Refusing the call
    instead would turn a preference into an error for a model that is working;
    sending ``none`` anyway returns HTTP 400. What makes the clamp defensible
    is that it is recorded: the applied effort is written to the response
    metadata, so "you asked for none and got low" is readable rather than
    silent.
5.  **The applied effort is read back from the provider where the provider
    reports it, and from the request where it does not.** A Responses reply
    carries ``reasoning.effort``; that value is recorded. On the Chat
    Completions path there is no such field, so what was sent is recorded, and
    the metadata says which of the two it is. A provider that has no effort
    scale writes nothing at all, and an absent key means "this provider does
    not have the concept" — not "the default applied".

.. _adr-204-consequences:

Consequences
============

✓ The Playground's switch does something on OpenAI, and what it did is
readable on the response rather than inferred from the answer's length.

✓ A model-specific limit — Astra's missing ``none`` — is handled once, in the
model profile :ref:`ADR-203 <adr-203>` introduces, instead of at each call site.

✕ Five providers still ignore both ``think`` and ``reasoning_effort``: Claude,
Gemini, Groq, Mistral and OpenRouter, and so do OpenAI's GPT-5 and o-series
models. This record does not change that. What it
changes is that ignoring them is now visible, because a provider that applied an
effort says so and one that did not writes no key.

✕ ``think`` and ``reasoning_effort`` are two ways to say a related thing, and
that is one more than a design would choose. Removing ``think`` is a breaking
change on a frozen surface and belongs to the 1.0 freeze, not here.

.. _adr-204-revisit:

Revisit when
============

A second provider gains an effort scale. The translation of ``think`` then
belongs in a shared place rather than in the OpenAI model profile.

Somebody needs an effort on a GPT-5 or o-series model. The scale for each
of those families is then read from its model page and added to the
profile, one family at a time.

The 1.0 API freeze: ``think`` is then either removed in favour of the enum, or
kept and documented as the two-state shorthand it is.

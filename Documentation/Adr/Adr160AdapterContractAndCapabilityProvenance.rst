.. include:: /Includes.rst.txt

.. _adr-160:

============================================================================
ADR-160: One adapter contract, and honest capability provenance
============================================================================

:Status: Accepted
:Date: 2026-08-11
:Authors: Netresearch DTT GmbH

.. _adr-160-context:

Context
=======

Two separate honesty gaps, both about a claim nobody could check.

.. _adr-160-context-adapters:

Seven adapters, seven private definitions of "works"
----------------------------------------------------

``Classes/Provider/`` holds seven adapters — OpenAI, Claude, Gemini, Groq,
Mistral, Ollama, OpenRouter. Each had its own unit test and its own opinion of
what needed testing. Nothing compared them.

The result was not that adapters were untested. It was that *the differences
between them were invisible*. Reading the suite could not tell you whether
"OpenRouter has no test for retry behaviour" meant the adapter does not retry
or that nobody wrote the test. Three examples the contract found on its first
run:

- ``OllamaProvider`` implements ``ToolCapableInterface`` and its
  ``supportsTools()`` returns true, but ``tools`` was missing from its
  ``$supportedFeatures``. The service layer's ``instanceof`` gate let a tool
  call through while ``LlmServiceManager::supportsFeature('tools', 'ollama')``
  denied the same capability to whoever asked.
- ``OpenRouterProvider`` sends through a private request path (it needs the
  ``HTTP-Referer`` / ``X-Title`` attribution headers and a 402 = out-of-credits
  mapping). That path had no ``try``/``catch`` around ``sendRequest()``, so a
  connection refusal or a cURL timeout escaped the adapter as a raw PSR-18
  exception and reached the caller as an unhandled 500 — the same class of bug
  the unparseable-body branch right below it already fixed.
- The same private path never called ``validateConfiguration()``. An
  OpenRouter with no vault key fell through ``getHttpClient()``'s
  api-key-less branch and sent a keyless request, so a local misconfiguration
  reached the operator as the provider's 401 — the provider's name on our
  mistake, after an outbound call that never had a chance.
  ``streamChatCompletion()`` validated; ``chatCompletion()`` did not.

None is exotic. All three survived because no artefact stated what an adapter
must answer to.

.. _adr-160-context-capabilities:

A capability with no provenance
-------------------------------

``tx_nrllm_model.capabilities`` is a comma-separated token list. An operator's
manual tick in the record editor and an answer from the provider's own model
endpoint produced byte-identical rows. Afterwards nothing could tell them
apart — not the model module, not routing, not the operator.

That matters most exactly where it is least visible. When a provider's model
endpoint is unreachable, ``ModelDiscovery`` substitutes the static catalog
bundled with the extension (``DiscoveryResult::fallback()``). A model created
from that catalog looked, in the database, exactly like a model the provider
had confirmed.

.. _adr-160-decision:

Decision
========

.. _adr-160-decision-contract:

1. One abstract contract case every adapter extends
---------------------------------------------------

``Tests/Unit/Provider/Contract/AbstractAdapterContractTestCase`` fixes seven
things for all seven adapters: the identifier, the capability declaration,
error normalisation per exception type, refusing to send without a credential,
timeout behaviour, usage reporting, and — where declared — the shape of a tool
call and of a structured-output request. Every adapter has a concrete
subclass; the four OpenAI-dialect adapters share their wire fixtures through
one intermediate case.

Three rules keep it a contract rather than a lowest common denominator:

**A capability an adapter does not have is skipped by name.** The document,
unsupported-schema, credential and retry contracts call ``markTestSkipped()``
with the reason. A reader of the run sees nine named skips and can tell
"cannot" from "not tested" — which is the entire point. The tool contracts
carry the same guard and never fire it: all seven bundled adapters implement
``ToolCapableInterface``. The guard stays for the eighth.

**A deliberate deviation is declared, not tolerated.** Three hooks —
``expectedServerErrorException()``, ``retriesTransportFailures()`` and
``requiresApiKey()`` — carry the differences that are real: the first two
because ``OpenRouterProvider`` does not send through
``AbstractProvider::sendRequest()``, the third because a local Ollama
authenticates nothing. Overriding one is a statement in the subclass, with the
reason in its docblock. That is where the deviation is now written down;
before, it was written down nowhere.

**No live calls.** Every adapter is driven through an injected PSR-18 double.

The suite lives under ``Tests/Unit`` deliberately, not under
``Tests/Integration``. The ``integration`` testsuite is in
``Build/phpunit.xml`` but no CI job runs it: ``ci.yml`` runs
``ci:test:php:unit``, ``…:functional`` and ``…:fuzzy``, and ``integration``
appears only in the local ``composer ci`` aggregate. A conformance suite that
no gate executes is a decoration.

**Streaming is covered by the declaration contract only.** An SSE fixture is
dialect-specific enough that a shared one would assert the fixture rather than
the adapter, and each adapter's own test already carries one.

**The repair round-trip stays where it is.** :ref:`ADR-126 <adr-126>`'s single
repair attempt, the nested-keyword limits and the rejection of a schema
outside the subset are ``CompletionService`` and ``JsonSchemaValidator``
behaviour, not adapter behaviour, and both already have tests for them. What
the adapter owns is the provider-native request shape, the degradation when
the provider cannot enforce the schema, and passing a malformed answer back
untouched so the layer that can repair it sees the real body. Those three are
in the contract.

.. _adr-160-decision-provenance:

2. Capability provenance, with the catalog kept separate
---------------------------------------------------------

Three columns on ``tx_nrllm_model``: ``capabilities_discovered`` (what the last
discovery reported), ``capabilities_confirmed_at`` (when, 0 = never) and
``capabilities_source`` (a ``CapabilitySource`` value).

Per-capability provenance is **derived**, not stored per capability:
``Model::getCapabilityProvenance()`` compares the declared set against the
discovered set. A capability the provider named carries that run's source and
date; one only the operator ticked carries ``CapabilitySource::Operator`` and
no date, because there is no confirmation to date. This gives the right answer
for free on every record written before provenance existed — nothing confirmed
it, and it now says so.

``CapabilitySource::Catalog`` is deliberately distinct from ``Discovery``.
Folding the substituted static catalog into "confirmed by the provider" would
manufacture exactly the confidence this record exists to remove.

**The source follows the capability tokens, not the model list.** These come
apart, and reading only ``ModelDiscovery::wasLastDiscoveryFromFallback()``
would have been the same conflation one level down. OpenAI's ``/v1/models``
returns ``id``/``object``/``created``/``owned_by``, Anthropic's returns no
capabilities either, and Groq's listing has no capability field: their
discoverers read the tokens out of the bundled catalog on the live path
exactly as on the fallback path. Gemini's curated table wins over the listing
for every model it names. So ``DiscoveredModel`` carries
``capabilitiesFromApi``, set by the discoverer and true only where the tokens
were derived from the response payload — Mistral's ``capabilities`` object,
OpenRouter's ``supported_parameters`` and ``input_modalities``, Ollama's
``/api/show`` array, and Gemini's ``supportedGenerationMethods`` for a model
the table does not know. ``CapabilityVerifier`` records ``Discovery`` only
when a live list and payload-derived tokens both hold; everything else is
``Catalog``. The practical consequence is that confirming an OpenAI or
Anthropic model against a reachable API yields ``Catalog``, which is the true
answer — nothing about those tokens was confirmed.

.. _adr-160-decision-consumer:

3. The consumer is the model backend module
--------------------------------------------

The capability column of ``Backend/Model/List.html`` renders each capability
with its provenance: a plain badge when a live provider answer confirmed it, a
warning badge with a question mark and a tooltip naming the source otherwise,
plus a "last confirmed" line per row. A "Confirm capabilities" row action
(``ModelController::verifyCapabilitiesAction``) runs discovery for the model's
provider and records the answer, so "last confirmed" ages honestly instead of
freezing at creation time. The same three fields appear read-only on the
Capabilities tab of the record editor.

**Why the routing readout on the Governance tab waited.** It is not the
readout the brief assumed. ``Backend/Governance.html`` renders governance
*profile deviations* — ``GovernanceProfileEvaluator::deviations()`` over policy
rows. There is no per-model capability readout there to annotate, so wiring
provenance into it means first building that readout. That is a larger change
than this one and belongs with whoever builds it.

**Routing does not read provenance, on purpose.** Making eligibility depend on
it would silently drop every model whose capabilities an operator declared by
hand — a behaviour change dressed as a data change. Provenance is
informational until someone decides, explicitly, that it should gate.

.. _adr-160-decision-writer:

4. The setup wizard is not a provenance writer
------------------------------------------------

``CapabilityVerifier`` is the only writer. The wizard deliberately is not one:
by the time ``SetupWizardController::createModels()`` persists the selected
models, the discovery it displayed happened in an earlier request and it can no
longer tell a live answer from the substituted catalog. Stamping "confirmed by
discovery" there would be the same conflation in a different place. A
wizard-created model therefore starts as unconfirmed — which is true, and
which is what makes the Confirm action worth clicking.

.. _adr-160-consequences:

Consequences
============

✓ Adding a provider means writing a contract subclass, and the abstract case
enumerates what the adapter has to answer to. A capability it lacks is a named
skip, not an omission.

✓ Three real defects are closed: Ollama's contradictory tool declaration,
OpenRouter's raw transport exception, and OpenRouter's keyless request on an
unconfigured adapter. All three were found by the contract on its first run
rather than in production.

✓ An operator can see which capabilities the provider actually confirmed, and
when.

✕ ``supportsFeature('tools', 'ollama')`` now returns true where it returned
false. That is the correct answer — the adapter has always been able to make
tool calls — but it is a behaviour change for anything that branched on the
wrong one.

✕ Two contract holes exist by declaration, both from OpenRouter's private
request path. It maps a 5xx other than 503 to ``ProviderResponseException``
rather than ``ProviderConnectionException`` — 503 has its own arm in
``handleOpenRouterError()`` and keeps the shared class — and it does not retry
transport failures at all. Neither is fixed here, because unifying that path
means changing its 401/402/429/500 messages, which existing tests pin.

The 5xx mapping does **not** cost a fallback hop. ``FailureClassifier``
(:ref:`ADR-095 <adr-095>`) reads the carried HTTP status, not the exception
class: a ``ProviderResponseException`` with a 5xx code classifies as
``FailureClass::SERVER_ERROR``, which answers ``isRetryable()`` and
``tripsCircuit()`` exactly as ``CONNECTION`` does, so ``FallbackMiddleware``
hops either way. What it costs is the class a caller catches and the wording:
a handler with a ``catch (ProviderResponseException)`` arm ahead of a generic
one — ``ProviderController::testConnectionAction()`` is the in-tree case —
takes that arm for an OpenRouter 5xx and the generic ``ProviderException`` arm
for every other adapter's, and the message reads
``OpenRouter API error (502): …`` where the shared path says
``Server returned status 502``.

✕ Provenance is per confirmation run, not per capability write. An operator who
edits the capability list right after a verification gets the new capability
attributed to themselves — correct — but the confirmation date of the
untouched ones does not move, which is also correct and may read as stale.

.. _adr-160-scope:

Explicitly out of scope
=======================

No new provider adapter. :ref:`ADR-147 <adr-147>` keeps AWS Bedrock and Google
Vertex AI a deliberate gap with two named triggers — ``symfony/ai-platform``
reaching 1.0, or a named customer requirement. Neither has fired, and a
conformance suite is not one of them.
